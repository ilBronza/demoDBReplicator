<?php

namespace IlBronza\DemoDBReplicator\Http\Middleware;

use Closure;
use IlBronza\CRUD\Middleware\CRUDBasePackageMiddlewareRolesPermissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves allowed roles for DB replicator routes from config (dbreplicator.defaultRoles / dbreplicator.routeRoles).
 */
class DemoDBReplicatorMiddlewareRolesPermissions extends CRUDBasePackageMiddlewareRolesPermissions
{
    protected string $configPackageName = 'dbreplicator';

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment('staging'), 403);

        return parent::handle($request, $next);
    }
}
