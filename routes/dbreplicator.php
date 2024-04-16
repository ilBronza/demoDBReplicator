<?php

Route::group(['middleware' => [
	'web',
	'auth',
	'role:superadmin'
	]], function () {

	Route::get('dbreplicator/replicate-db', [config('dbreplicator.replicatorController'), 'execute']);
});
