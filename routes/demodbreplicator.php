<?php

use IlBronza\DemoDBReplicator\Http\Controllers\DBReplicatorController;

Route::group(['middleware' => [
	'web',
	'auth',
	'dbreplicator.roles'
	]], function () {

	Route::get('dbreplicator/replicate-db', [DBReplicatorController::class, 'execute'])->name('replicate-db');
});
