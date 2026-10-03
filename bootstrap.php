<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';
require_once 'configs/database.php';

use Illuminate\Database\Capsule\Manager as DB;

// Set up Environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$capsule = new DB();

$databaseConnections = databaseConfigs() ?? ['connections' => []];

foreach ($databaseConnections['connections'] as $connectionName => $config) {
    // SQLite only opens databases that exist, so they are created on the first start.
    if ($config['driver'] === 'sqlite' && $config['database'] !== ':memory:' && ! file_exists($config['database'])) {
        @mkdir(dirname($config['database']), 0755, true);
        touch($config['database']);
    }

    $capsule->addConnection($config, $connectionName);
}

$capsule->setAsGlobal();
$capsule->bootEloquent();
