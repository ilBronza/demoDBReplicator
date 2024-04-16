<?php

namespace IlBronza\DemoDBReplicator\Http\Controllers;

use App\Http\Controllers\Controller;
use IlBronza\DemoDBReplicator\DemoDBReplicator;

class DBReplicatorController extends Controller
{
	public function execute()
	{
        return DemoDBReplicator::replicate();
	}
}