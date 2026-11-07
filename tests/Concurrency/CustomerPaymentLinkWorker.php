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

app()->instance(\App\Services\CustomerPayments\UatPaymentPosting::class, new class extends \App\Services\CustomerPayments\UatPaymentPosting {
    public function enabled(): bool { return true; }
});
if ($mode === 'fixture') {
    $org = TestData::organization(); $user = TestData::user($org); auth()->setUser($user);
    $invoice = Invoice::create(['organization_id'=>$org->id,'invoice_number'=>'GATEWAY-'.bin2hex(random_bytes(5)),
        'invoice_date'=>today(),'total_amount'=>118,'balance_amount'=>118,'paid_amount'=>0,'status'=>'unpaid']);
    $account = \App\Models\CustomerPaymentAccount::create(['organization_id'=>$org->id,'mode'=>'test',
        'webhook_token'=>(string) \Illuminate\Support\Str::uuid(),'key_id'=>'rzp_test_fixture',
        'key_secret'=>'synthetic-concurrency-secret','key_fingerprint'=>hash('sha256',random_bytes(16)),
        'webhook_secret'=>'synthetic-webhook-secret','is_active'=>true]);
    $link = \App\Models\CustomerPaymentLink::create(['organization_id'=>$org->id,'invoice_id'=>$invoice->id,
        'account_id'=>$account->id,'created_by'=>$user->id,'reference'=>(string) \Illuminate\Support\Str::uuid(),
        'provider_id'=>'plink_'.bin2hex(random_bytes(6)),'amount_minor'=>11800,'currency'=>'INR','status'=>'active',
        'expires_at'=>now()->addDays(7)]);
    echo json_encode(['organization'=>$org->id,'user'=>$user->id,'invoice'=>$invoice->id,'link'=>$link->id]),PHP_EOL; exit;
}
$input = json_decode(file_get_contents($argv[3]),true,512,JSON_THROW_ON_ERROR);
if ($mode === 'cleanup') {
    DB::disconnect(); $pdo = new PDO('mysql:host=127.0.0.1;port='.(int)$config['port'],$config['username'],$config['password']);
    $pdo->exec('DROP DATABASE '.$config['database']); echo json_encode(['disposable_database_removed'=>true]),PHP_EOL; exit;
}
if ($mode === 'summary') {
    $invoice=Invoice::findOrFail($input['invoice']);
    echo json_encode(['payments'=>Payment::where('invoice_id',$invoice->id)->count(),
        'receipts'=>\App\Models\CustomerPaymentReceipt::where('link_id',$input['link'])->count(),
        'balance'=>(float)$invoice->balance_amount,'status'=>$invoice->payment_status]),PHP_EOL;exit;
}
auth()->setUser(\App\Models\User::findOrFail($input['user']));
if (!empty($input['entered'])) Payment::creating(function () use ($input) {
    file_put_contents($input['entered'],'entered'); $deadline=microtime(true)+12;
    while (!is_file($input['release'])) { if(microtime(true)>$deadline) throw new RuntimeException('Barrier timed out'); usleep(20000); }
});
if ($mode === 'manual') {
    try {
        app(\App\Services\Finance\OrganizationFinancialTransaction::class)->run($input['organization'],function () use ($input) {
            $invoice=Invoice::whereKey($input['invoice'])->lockForUpdate()->firstOrFail();
            app(\App\Services\Finance\PaymentSyncService::class)->createInvoicePayment($input['organization'],$invoice,
                ['payment_date'=>today(),'amount'=>118,'payment_method'=>'cash'],null,true);
        });
        echo json_encode(['manual_recorded'=>true]),PHP_EOL;
    } catch (ValidationException $error) { echo json_encode(['manual_blocked'=>true]),PHP_EOL; }
    exit;
}
if ($mode !== 'receive') throw new RuntimeException('Unknown gateway worker mode');
$link=\App\Models\CustomerPaymentLink::findOrFail($input['link']);
$entity=['id'=>$link->provider_id,'reference_id'=>$link->reference,'currency'=>'INR','amount'=>11800,
    'amount_paid'=>11800,'accept_partial'=>false,'status'=>'paid','order_id'=>'order_Fixture'];
$payment=['id'=>'pay_Fixture'.$link->id,'order_id'=>'order_Fixture','currency'=>'INR','amount'=>11800,
    'status'=>'captured','captured'=>true,'method'=>'upi','created_at'=>now()->timestamp,'amount_refunded'=>0];
app(\App\Services\CustomerPayments\CustomerPaymentLinks::class)->receive($link,$entity,$payment,'synthetic-event');
echo json_encode(['received'=>true]),PHP_EOL;
