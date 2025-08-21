<?php

use IlBronza\DemoDBReplicator\Http\Controllers\DBReplicatorController;

Route::group(['middleware' => [
	'web',
	'auth',
	'role:superadmin'
	]], function () {

	Route::get('dbreplicator/replicate-db', [DBReplicatorController::class, 'execute'])->name('replicate-db');
});
