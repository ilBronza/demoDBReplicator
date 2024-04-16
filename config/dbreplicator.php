<?php

use IlBronza\DemoDBReplicator\Http\Controllers\DBReplicatorController;

return [
    'replicatorController' => DBReplicatorController::class,
    'dumpFilePath' => storage_path('app/temp/'),
    'dumpFileName' => 'dbreplication',
    'databases' => [
        'source' => [
            'configFile' => base_path() . '/.dbReplicatorConf',
            'name' => config('database.connections.' . config('database.default') . '.database')
        ],
        'destination' => [
            'configFile' => base_path() . '/.dbReplicatorConf',
            'name' => config('database.connections.mysql_replications.database')
        ]
    ]
];