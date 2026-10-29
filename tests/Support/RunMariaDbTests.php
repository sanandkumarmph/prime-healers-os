<?php

// Same release suite as SQLite, pointed only at a separately verified test DB.
$suiteArguments=array_slice($argv,2);
$configArgument=$argv[1] ?? throw new RuntimeException('Pass a new disposable database config JSON.');
$argv=$_SERVER['argv']=[$argv[0],$configArgument,'init'];
$deferApplicationBootstrap=true;
require __DIR__.'/DisposableMariaDb.php';
echo 'Verified disposable MariaDB: '.$config['database'].' on 127.0.0.1:'.$config['port'].PHP_EOL;
\Pest\TestSuite::getInstance($root,'tests');
$input=new \Symfony\Component\Console\Input\ArgvInput([$argv[0],...$suiteArguments]);
$output=new \Symfony\Component\Console\Output\ConsoleOutput();
$kernel=\Pest\Kernel::boot(\Pest\TestSuite::getInstance($root,'tests'),$input,$output);
$arguments=[$argv[0],'--do-not-cache-result',...$suiteArguments];
$status=$kernel->handle($arguments,$arguments);$kernel->terminate();exit($status);
