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
        if (! ImportAccess::environmentAllowed() || ! app()->bound('menu'))
            return;

        if (! $menu = app('menu'))
            return;

        $profiles = app(ImportConfiguration::class)->all();
        if (! count($profiles))
            return;

        $roles = ['superadmin'];

        $button = $menu->provideButton([
            'text' => 'menu::menu.settings',
            'name' => 'settings',
            'icon' => 'gear',
            'roles' => $roles
        ]);

        $replicatorButton = $menu->createButton([
            'name' => 'databaseImports',
            'icon' => 'database',
            'text' => 'dbReplicator::dbReplicator.importDatabases',
            'roles' => $roles
        ]);

        $button->addChild($replicatorButton);

        foreach ($profiles as $name => $profile) {
            $replicatorButton->addChild($menu->createButton([
                'name' => 'databaseImport-' . $name,
                'icon' => 'database',
                'href' => route('dbreplicator.import.show', ['database' => $name]),
                'text' => $profile['label'] ?? $name,
                'roles' => $roles,
            ]));
        }
    }

}
