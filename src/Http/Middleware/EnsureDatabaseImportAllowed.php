<?php

namespace IlBronza\DemoDBReplicator\Http\Middleware;

use Closure;
use IlBronza\DemoDBReplicator\ImportAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDatabaseImportAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        ImportAccess::authorize($request);

        return $next($request);
    }
}
