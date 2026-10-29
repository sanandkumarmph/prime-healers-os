<?php

// Test-only bootstrap. Every process checks the actual instance and database
// before any schema or workflow operation. Never use deployed configuration.
$config=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$mode=$argv[2] ?? '';
$input=isset($argv[3]) ? json_decode(file_get_contents($argv[3]),true,512,JSON_THROW_ON_ERROR) : [];
if (($config['host'] ?? null)!=='127.0.0.1' || (int)($config['port'] ?? 0)===3306
    || !preg_match('/^phos_validation_[a-f0-9]{12}$/',$config['database'] ?? '')
    || !str_contains(str_replace('\\','/',$config['expectedDataDirectory'] ?? ''),'/phos-mariadb-common-')) {
    throw new RuntimeException('Only the dedicated disposable loopback MariaDB configuration is permitted.');
}
$pdo=new PDO('mysql:host=127.0.0.1;port='.(int)$config['port'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
$normalize=fn($value)=>strtolower(rtrim(str_replace('\\','/',$value),'/'));
if ($normalize($pdo->query('SELECT @@datadir')->fetchColumn())!==$normalize($config['expectedDataDirectory'])
    || !str_starts_with($pdo->query('SELECT VERSION()')->fetchColumn(),'10.11.16')) throw new RuntimeException('Disposable MariaDB identity/version mismatch.');
if (in_array($mode,['init','baseline'],true)) {
    $query=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'); $query->execute([$config['database']]);
    if ($query->fetchColumn()) throw new RuntimeException('Refusing to initialize an existing database.');
    $pdo->exec('CREATE DATABASE `'.$config['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
unset($pdo);
$environment=['APP_ENV'=>'testing','APP_KEY'=>$config['appKey'],'APP_URL'=>'http://127.0.0.1','APP_DEBUG'=>'true',
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$config['port'],'DB_DATABASE'=>$config['database'],
    'DB_USERNAME'=>$config['username'],'DB_PASSWORD'=>$config['password'],'DB_URL'=>'','DB_SOCKET'=>'',
    'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','BROADCAST_CONNECTION'=>'null','BCRYPT_ROUNDS'=>'4'];
foreach ($environment as $name=>$value) { putenv($name.'='.$value); $_ENV[$name]=$_SERVER[$name]=$value; }
$root=dirname(__DIR__,2);
if (is_file($root.'/bootstrap/cache/config.php')) throw new RuntimeException('Refusing a checkout with a cached configuration.');
require $root.'/vendor/autoload.php';
// PHPUnit must own its application's exception/error-handler lifecycle.
if ($deferApplicationBootstrap ?? false) return;
$app=require $root.'/bootstrap/app.php';
$console=$app->make(\Illuminate\Contracts\Console\Kernel::class); $console->bootstrap();
set_exception_handler(function (\Throwable $error): void {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
});
if (\Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS name')->name!==$config['database']) throw new RuntimeException('Application database identity mismatch.');
\Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout=15');
if (isset($input['isolation'])) {
    if (!in_array($input['isolation'],['REPEATABLE READ','READ COMMITTED'],true)) throw new RuntimeException('Unexpected isolation level.');
    \Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$input['isolation']);
}
