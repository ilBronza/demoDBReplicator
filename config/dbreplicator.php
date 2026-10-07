<?php

use IlBronza\DemoDBReplicator\Http\Controllers\DBReplicatorController;

return [
    'defaultRoles' => [
        'superadmin',
    ],

    'routeRoles' => [
    ],

    'replicatorController' => DBReplicatorController::class,
    'dumpFilePath' => storage_path('app/tmp/'),
    'dumpFileName' => 'dbreplication',

    'stagingPath' => env('IB_REPLICATOR_STAGING_PATH', '/var/www/gestionaledev'),

    'mySqlDumpPath' => env('IB_REPLICATOR_MYSQL_DUMP', 'mysqldump'),
    'mysqlPath' => env('IB_REPLICATOR_MYSQL', 'mysql'),
    'timeout' => 1800,
    'dumpOptions' => [
        '--single-transaction', '--quick', '--routines', '--events', '--triggers',
    ],

    'migrate' => true,

    'connections' => [
        'source' => 'mysql',
        'destination' => 'mysql_replications',
    ]
];