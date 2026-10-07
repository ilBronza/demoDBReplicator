<?php

namespace IlBronza\DemoDBReplicator;

use IlBronza\CRUD\Providers\RouterProvider\RoutedObjectInterface;
use IlBronza\CRUD\Traits\IlBronzaPackages\IlBronzaPackagesTrait;

/** this class replicates a database from a source to a destination
 *  the source and destination databases are defined in the config file
 *  the dump file is created in the storage path
 *  the dump file is loaded in the destination database
 *  the dump file is deleted after the load
 *  the class returns a message with the result of the operation
 */

class DemoDBReplicator implements RoutedObjectInterface
{
    use IlBronzaPackagesTrait;

    static $packageConfigPrefix = 'dbreplicator';

    public function manageMenuButtons()
    {
        if (! app()->environment('staging') || ! app()->bound('menu'))
            return;

        if (! $menu = app('menu'))
            return;

        $roles = config('dbreplicator.routeRoles.replicate-db')
            ?: config('dbreplicator.defaultRoles', ['superadmin']);

        $button = $menu->provideButton([
            'text' => 'dbReplicator::dbReplicator.packages',
            'name' => 'packages',
            'icon' => 'cubes',
            'roles' => $roles
        ]);

        $replicatorButton = $menu->createButton([
            'name' => 'databaseReplicator',
            'icon' => 'database',
            'href' => app('dbreplicator')->route('replicate-db'),
            'text' => 'dbReplicator::dbReplicator.replicateDb',
            'roles' => $roles
        ]);

        $button->addChild($replicatorButton);
    }

}
