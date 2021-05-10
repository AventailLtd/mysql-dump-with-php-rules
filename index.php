<?php

require('vendor/autoload.php');
require('Dump.php');
//require('include/DBLaci/Framework/SQLUtils.php');

// required env check:
$envs = ['MYSQL_HOST', 'MYSQL_DB', 'MYSQL_USERNAME', 'MYSQL_PASSWORD'];
foreach ($envs as $requiredEnv) {
    if (!getenv($requiredEnv)) {
        echo 'These env variables are reuired: ' . implode(', ', $envs) . " - missing: $requiredEnv\n";
        die(1);
    }
}


$pdo = new PDO('mysql:host=' . getenv('MYSQL_HOST') . ';port=' . (getenv('MYSQL_PORT') ?: '3306') . ';dbname=' . getenv('MYSQL_DB') . ';charset=utf8mb4', getenv('MYSQL_USERNAME'), getenv('MYSQL_PASSWORD'), [
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('SET SESSION `sql_mode` = \'STRICT_TRANS_TABLES\'');

if (file_exists('DumpOverride.php')) {
    require('DumpOverride.php');
    $dump = new DumpOverride($pdo, getenv('DUMP_FILENAME'));
} else {
    $dump = new Dump($pdo, getenv('DUMP_FILENAME'));
}
$dump->run();
