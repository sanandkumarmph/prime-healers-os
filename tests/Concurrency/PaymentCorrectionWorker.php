<?php

// Internal HTTP-kernel worker for disposable, loopback-only concurrency validation.
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestData;

$config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[2] ?? '';
if ($config['host'] !== '127.0.0.1'
    || !preg_match('/^phos_validation_[a-f0-9]{12}$/', $config['database'])
    || !str_contains(str_replace('\\', '/', $config['expectedDataDirectory']), '/phos-mariadb-')) {
    throw new RuntimeException('Refusing a non-disposable database configuration.');
}
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=' . (int) $config['port'],
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
);
$normalize = fn ($path) => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
if ($normalize($pdo->query('SELECT @@datadir')->fetchColumn()) !== $normalize($config['expectedDataDirectory'])) {
    throw new RuntimeException('The connected MariaDB data directory is not the disposable instance.');
}
if ($mode === 'init') {
    $existing = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $existing->execute([$config['database']]);
    if ($existing->fetchColumn()) {
        throw new RuntimeException('Refusing to initialize an existing database.');
    }
    $pdo->exec('CREATE DATABASE ' . $config['database'] . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
unset($pdo);

$environment = [
    'APP_ENV' => 'testing', 'APP_KEY' => $config['appKey'],
    'APP_URL' => 'http://127.0.0.1', 'APP_DEBUG' => 'true',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'],
    'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
    'DB_URL' => '', 'DB_SOCKET' => '', 'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'BCRYPT_ROUNDS' => '4',
];
foreach ($environment as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$root = dirname(__DIR__, 2);
if (is_file($root . '/bootstrap/cache/config.php')) {
    throw new RuntimeException('Remove only this disposable checkout configuration cache before validation.');
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$console = $app->make(ConsoleKernel::class);
$console->bootstrap();
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
if (DB::selectOne('SELECT DATABASE() AS name')->name !== $config['database']) {
    throw new RuntimeException('Application selected a different database.');
}
if ($mode === 'init') {
    $exit = $console->call('migrate', ['--force' => true]);
    if ($exit !== 0) {
        file_put_contents(dirname($argv[1]) . '/migration-errors.txt', $console->output());
        throw new RuntimeException('Disposable migration failed; see migration-errors.txt.');
    }
    echo json_encode(['initialized' => true, 'serverVersion' => DB::selectOne('SELECT VERSION() AS version')->version]), PHP_EOL;
    exit;
}
if ($mode === 'fixture') {
    $organization = TestData::organization(['name' => 'Concurrency ' . bin2hex(random_bytes(4))]);
    $user = TestData::user($organization);
    auth()->guard('web')->setUser($user);
    $customer = Customer::create([
        'organization_id' => $organization->id,
        'name' => 'Synthetic concurrency customer', 'phone' => '9876500001',
    ]);
    $invoice = Invoice::create([
        'organization_id' => $organization->id, 'customer_id' => $customer->id,
        'invoice_number' => 'CONC-' . bin2hex(random_bytes(5)),
        'invoice_date' => now()->toDateString(), 'due_date' => now()->addDay()->toDateString(),
        'subtotal' => 1000, 'total_amount' => 1000, 'paid_amount' => 0,
        'balance_amount' => 1000, 'status' => 'unpaid', 'payment_status' => 'unpaid',
    ]);
    $payment = Payment::create(['organization_id' => $organization->id, 'customer_id' => $customer->id,
        'invoice_id' => $invoice->id, 'payment_date' => today(), 'amount' => 400, 'payment_method' => 'cash']);
    $invoice->syncFinancialStatus();
    echo json_encode(['organization' => $organization->id, 'user' => $user->id, 'invoice' => $invoice->id,
        'payment' => $payment->id, 'version' => app(App\Services\Finance\PaymentCorrectionService::class)->version($payment)]), PHP_EOL;
    exit;
}
$input = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
if ($mode === 'summary') {
    $invoice = Invoice::findOrFail($input['invoice']);
    echo json_encode([
        'payments' => Payment::where('invoice_id', $invoice->id)->count(),
        'amount' => (float) Payment::where('invoice_id', $invoice->id)->sum('amount'),
        'reversals' => Payment::where('invoice_id', $invoice->id)->whereNotNull('reversal_of_payment_id')->count(),
        'audits' => App\Models\ActivityLog::where('invoice_id', $invoice->id)->where('action', 'payment.reversed')->count(),
        'claims' => DB::table('payment_submissions')->where('organization_id', $invoice->organization_id)->count(),
        'balance' => (float) $invoice->balance_amount, 'status' => $invoice->payment_status,
    ]), PHP_EOL;
    exit;
}
if ($mode !== 'request') {
    throw new RuntimeException('Unknown worker mode.');
}
class PaymentCorrectionBarrier
{
    public function handle($request, $next)
    {
        $input = $GLOBALS['phosCorrectionInput'];
        file_put_contents($input['entered'], 'entered');
        if (!empty($input['release'])) {
            $deadline = microtime(true) + 15;
            while (!is_file($input['release'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Concurrency barrier timed out.');
                }
                usleep(20000);
            }
        }
        $response = $next($request);
        if (!empty($input['failAfterReceipt'])) {
            throw ValidationException::withMessages(['amount' => 'Injected post-receipt rollback.']);
        }
        return $response;
    }
}
$GLOBALS['phosCorrectionInput'] = $input;
$operation = $input['operation'] ?? 'reverse';
$route = match ($operation) {
    'reverse' => 'payments.reverse', 'payment' => 'invoices.payments.store', 'delete_invoice' => 'invoices.destroy',
};
$app['router']->getRoutes()->getByName($route)->middleware(PaymentCorrectionBarrier::class);
auth()->guard('web')->setUser(App\Models\User::findOrFail($input['user']));
$url = match ($operation) {
    'reverse' => '/payments/'.$input['payment'].'/reverse',
    'payment' => '/invoices/'.$input['invoice'].'/payments',
    'delete_invoice' => '/invoices/'.$input['invoice'],
};
$payload = $operation === 'reverse'
    ? ['reason' => 'Synthetic incorrect receipt entry', 'confirm' => 1, 'version' => $input['version']]
    : ['payment_submission_token' => $input['token'] ?? '', 'payment_date' => date('Y-m-d'),
        'amount' => $input['amount'] ?? 0, 'payment_method' => 'other'];
$request = Request::create('http://127.0.0.1'.$url, $operation === 'delete_invoice' ? 'DELETE' : 'POST',
    $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
file_put_contents($input['started'], 'started');
$http = $app->make(HttpKernel::class);
$response = $http->handle($request);
$http->terminate($request, $response);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getStatusCode() >= 400 ? $response->getContent() : null]), PHP_EOL;
