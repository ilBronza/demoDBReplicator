<?php

namespace IlBronza\DemoDBReplicator\Http\Middleware;

use IlBronza\CRUD\Middleware\CRUDBasePackageMiddlewareRolesPermissions;

/**
 * Resolves allowed roles for DB replicator routes from config (dbreplicator.defaultRoles / dbreplicator.routeRoles).
 */
class DemoDBReplicatorMiddlewareRolesPermissions extends CRUDBasePackageMiddlewareRolesPermissions
{
    protected string $configPackageName = 'dbreplicator';
}
