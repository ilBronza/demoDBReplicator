<?php

namespace IlBronza\DemoDBReplicator\Http\Controllers;

use App\Http\Controllers\Controller;
use IlBronza\DemoDBReplicator\DatabaseReplicator;
use IlBronza\DemoDBReplicator\ImportAccess;
use Illuminate\Http\Request;
use IlBronza\Ukn\Ukn;

class DBReplicatorController extends Controller
{
	public function execute(Request $request)
	{
        ImportAccess::authorize($request);
		$time = microtime(true);
        DatabaseReplicator::replicate();

        Ukn::s('Database replicato con successo in ' . round(microtime(true) - $time, 2) . ' secondi');

        return back();
	}
}
