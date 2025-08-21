<?php

namespace IlBronza\DemoDBReplicator\Http\Controllers;

use App\Http\Controllers\Controller;
use IlBronza\DemoDBReplicator\DatabaseReplicator;
use IlBronza\Ukn\Ukn;

class DBReplicatorController extends Controller
{
	public function execute()
	{
		$time = microtime(true);
        DatabaseReplicator::replicate();

        Ukn::s('Dabase replicato con successo in ' . round(((microtime(true) - $time) / 1000), 2) . ' secondi');

        return back();
	}
}