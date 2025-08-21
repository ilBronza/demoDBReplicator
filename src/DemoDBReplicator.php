<?php

namespace IlBronza\DemoDBReplicator;

use IlBronza\CRUD\Providers\RouterProvider\RoutedObjectInterface;
use IlBronza\CRUD\Traits\IlBronzaPackages\IlBronzaPackagesTrait;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

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
        if (! $menu = app('menu'))
            return;

        $button = $menu->provideButton([
            'text' => 'generals.settings',
            'name' => 'settings',
            'icon' => 'gear',
            'roles' => ['administrator']
        ]);

        $productsGeneralManagerButton = $menu->createButton([
            'name' => 'databaseReplicator',
            'icon' => 'database',
            'href' => app('dbreplicator')->route('replicate-db'),
            'text' => 'dbReplicator::dbReplicator.replicateDb'
        ]);

        $button->addChild($productsGeneralManagerButton);
    }

}