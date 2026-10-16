<?php

use IlBronza\DemoDBReplicator\Http\Controllers\DBReplicatorController;
use IlBronza\DemoDBReplicator\Http\Controllers\DatabaseImportController;
use IlBronza\DemoDBReplicator\Http\Controllers\DatabaseExportController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => [
	'web',
	'dbreplicator.import',
	'auth',
	'dbreplicator.roles'
	]], function () {

	Route::post('dbreplicator/replicate-db', [DBReplicatorController::class, 'execute'])->name('replicate-db');
});

Route::middleware(['web', 'dbreplicator.import', 'auth'])->group(function () {
    Route::get('dbreplicator/imports/{database}', [DatabaseImportController::class, 'show'])
        ->where('database', '[a-zA-Z0-9_-]+')->name('dbreplicator.import.show');
    Route::post('dbreplicator/imports/{database}', [DatabaseImportController::class, 'import'])
        ->where('database', '[a-zA-Z0-9_-]+')->name('dbreplicator.import.run');
});

// Deliberately stateless: no session authentication or CSRF on server-to-server exports.
Route::post('dbreplicator/api/exports/{database}', [DatabaseExportController::class, 'export'])
    ->where('database', '[a-zA-Z0-9_-]+')
    ->middleware('dbreplicator.export')->name('dbreplicator.export');
