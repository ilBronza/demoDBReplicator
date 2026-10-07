<?php

namespace IlBronza\DemoDBReplicator\Tests;

require_once __DIR__ . '/bootstrap.php';

use IlBronza\DemoDBReplicator\DemoDBReplicatorServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected string $dumpDirectory;

    protected function getPackageProviders($app): array
    {
        return [DemoDBReplicatorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->dumpDirectory = __DIR__ . '/.runtime/dumps-' . bin2hex(random_bytes(4));
        $app['config']->set('dbreplicator.dumpFilePath', $this->dumpDirectory);
        $app['config']->set('dbreplicator.migrate', false);
        $app['config']->set('dbreplicator.pull.ssh.host', 'production.example.test');
        $app['config']->set('dbreplicator.pull.ssh.user', 'deploy');
        $app['config']->set('dbreplicator.pull.remote.defaultsFile', '/home/deploy/.mysql.cnf');
        $app['config']->set('dbreplicator.pull.remote.database', 'production_db');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307,
            'database' => 'local_db', 'username' => 'local_user', 'password' => 'local-password',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ]);
        $app['config']->set('database.connections.mysql_replications', array_merge(
            $app['config']->get('database.connections.mysql'), ['database' => 'staging_db']
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->dumpDirectory) && is_dir($this->dumpDirectory)) {
            foreach (scandir($this->dumpDirectory) as $file) {
                if ($file !== '.' && $file !== '..') {
                    unlink($this->dumpDirectory . '/' . $file);
                }
            }

            rmdir($this->dumpDirectory);
        }

        parent::tearDown();
    }

    protected function environment(string $environment): void
    {
        $this->app['env'] = $environment;
        $this->app['config']->set('app.env', $environment);
    }
}
