<?php

use IlBronza\DemoDBReplicator\Http\Controllers\DBReplicatorController;

return [
    'replicatorController' => DBReplicatorController::class,
    'dumpFilePath' => storage_path('app/tmp/'),
    'dumpFileName' => 'dbreplication',

    'stagingPath' => env('IB_REPLICATOR_STAGING_PATH', '/var/www/gestionaledev'),

    'tcp_socketString' =>  env('IB_REPLICATOR_SOCKET_TCP', '-S /var/run/mysqld/mysqld.sock'),
    // 'tcp_socketString' =>  env('IB_REPLICATOR_SOCKET_TCP', '-S /var/run/mysqld/mysqld.sock'),

    'mySqlDumpPath' =>  env('IB_REPLICATOR_MYSQL_DUMP', '/opt/homebrew/opt/mysql-client/bin/mysqldump'),

    'connections' => [
        'source' => 'mysql',
        'destination' => 'mysql_replications',
    ]
];