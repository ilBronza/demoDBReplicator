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
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('t', 32)));
        $app['config']->set('session.driver', 'array');
        $this->dumpDirectory = __DIR__ . '/.runtime/dumps-' . bin2hex(random_bytes(4));
        $app['config']->set('dbreplicator.dumpFilePath', $this->dumpDirectory);
        $views = __DIR__ . '/.runtime/views';
        if (! is_dir($views)) {
            mkdir($views, 0700, true);
        }
        $app['config']->set('view.compiled', $views);
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

    protected function importProfile(array $overrides = []): array
    {
        $profile = array_replace([
            'label' => 'Gestionale',
            'url' => 'https://production.example.test/dbreplicator/api/exports/main',
            'token' => str_repeat('a', 64),
            'destination' => 'mysql',
            'destinationGuard' => ['database' => 'local_db', 'host' => '127.0.0.1', 'port' => 3307],
        ], $overrides);
        config(['dbreplicator.imports.main' => $profile]);

        return $profile;
    }

    protected function signIn(bool $superadmin = true): void
    {
        $this->actingAs(new class(['id' => 1, 'superadmin' => $superadmin]) extends \Illuminate\Auth\GenericUser {
            public function hasAnyRole(array $roles): bool
            {
                return $this->superadmin && $roles === ['superadmin'];
            }
        });
    }
}
