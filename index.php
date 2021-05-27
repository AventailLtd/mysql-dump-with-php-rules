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

$overrideFilename = getenv('OVERRIDE_PHP_FILENAME');
$pdo = new PDO('mysql:host=' . getenv('MYSQL_HOST') . ';port=' . (getenv('MYSQL_PORT') ?: '3306') . ';dbname=' . getenv('MYSQL_DB') . ';charset=utf8mb4', getenv('MYSQL_USERNAME'), getenv('MYSQL_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false, // https://stackoverflow.com/questions/21024197/do-unbuffered-queries-for-one-request-with-pdo
]);

$pdo->exec('SET SESSION `sql_mode` = "STRICT_TRANS_TABLES"');

if (file_exists($overrideFilename)) {
    require($overrideFilename);
    $dump = new DumpOverride($pdo, getenv('DUMP_FILENAME'));
} else {
    $dump = new Dump($pdo, getenv('DUMP_FILENAME'));
}
$dump->run();
