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

    // Staging/local only. One entry per menu item; see readme.md for examples.
    // Credentials and destinations are never accepted from browser input.
    'imports' => [],

    // Production only: explicitly opt in and allowlist each source connection.
    'export' => [
        'enabled' => env('IB_REPLICATOR_API_ENABLED', false),
        'databases' => [],
    ],

    'connections' => [
        'source' => 'mysql',
        'destination' => 'mysql_replications',
    ],

    'pull' => [
        'destination' => env('IB_REPLICATOR_LOCAL_CONNECTION', 'mysql'),
        'localHosts' => ['127.0.0.1', 'localhost', '::1', 'mysql', 'mariadb', 'host.docker.internal'],
        'ssh' => [
            'binary' => env('IB_REPLICATOR_SSH', 'ssh'),
            'host' => env('IB_REPLICATOR_SSH_HOST'),
            'user' => env('IB_REPLICATOR_SSH_USER'),
            'port' => env('IB_REPLICATOR_SSH_PORT', 22),
            'identityFile' => env('IB_REPLICATOR_SSH_IDENTITY_FILE'),
        ],
        'remote' => [
            'projectPath' => env('IB_REPLICATOR_REMOTE_PROJECT_PATH'),
            'connection' => env('IB_REPLICATOR_REMOTE_CONNECTION'),
            'phpPath' => env('IB_REPLICATOR_REMOTE_PHP', 'php'),
            'mysqldumpPath' => env('IB_REPLICATOR_REMOTE_MYSQL_DUMP', 'mysqldump'),
            // Alternative for servers without an accessible Laravel project.
            'defaultsFile' => env('IB_REPLICATOR_REMOTE_DEFAULTS_FILE'),
            'database' => env('IB_REPLICATOR_PRODUCTION_DATABASE'),
        ],
    ],
];
