<?php

namespace IlBronza\DemoDBReplicator\Tests\Unit;

use IlBronza\DemoDBReplicator\ApiDatabaseImporter;
use IlBronza\DemoDBReplicator\DatabaseReplicator;
use IlBronza\DemoDBReplicator\ImportConfiguration;
use IlBronza\DemoDBReplicator\MySqlClient;
use IlBronza\DemoDBReplicator\RemoteApiDownloader;
use IlBronza\DemoDBReplicator\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;

class ApiDatabaseImporterTest extends TestCase
{
    protected array $events = [];
    protected array $paths = [];
    protected ?string $failure = null;
    protected bool $sameDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->environment('staging');
        $this->importProfile();
    }

    protected function importer(): ApiDatabaseImporter
    {
        $client = Mockery::mock(MySqlClient::class)->makePartial();
        $client->shouldReceive('databaseIdentity')->with('mysql')->andReturnUsing(function () {
            return str_repeat($this->sameDatabase ? 'a' : 'b', 64);
        });
        $client->shouldReceive('dump')->with('mysql', Mockery::type('string'))->andReturnUsing(function ($name, $path) {
            $this->events[] = 'backup';
            $this->paths['backup'] = $path;
            if ($this->failure === 'backup') {
                throw new RuntimeException('backup failed');
            }
            file_put_contents($path, $this->failure === 'emptyBackup' ? '' : 'backup SQL');
        });
        $client->shouldReceive('import')->with('mysql', Mockery::type('string'))->andReturnUsing(function ($name, $path) {
            $this->events[] = 'import';
            $this->assertSame('production SQL', file_get_contents($path));
            if ($this->failure === 'import') {
                throw new RuntimeException('import failed');
            }
        });
        $downloader = Mockery::mock(RemoteApiDownloader::class);
        $downloader->shouldReceive('download')->andReturnUsing(function ($profile, $path) {
            $this->events[] = 'download';
            if ($this->failure === 'download') {
                throw new RuntimeException('download failed');
            }
            $this->paths['dump'] = $path;
            file_put_contents($path, 'production SQL');

            return str_repeat('a', 64);
        });
        $replicator = Mockery::mock(DatabaseReplicator::class);
        foreach (['withDestinationLock', 'withDatabaseLock'] as $method) {
            $replicator->shouldReceive($method)->with('mysql', Mockery::type('callable'))
                ->andReturnUsing(function ($name, $callback) {
                    if ($this->failure === 'lock') {
                        throw new RuntimeException('lock failed');
                    }
                    return $callback();
                });
        }
        $replicator->shouldReceive('dropAllDestinationObjects')->with('mysql')->andReturnUsing(function () {
            $this->events[] = 'drop';
            if ($this->failure === 'drop') {
                throw new RuntimeException('drop failed');
            }
        });

        return new ApiDatabaseImporter($client, $replicator, $downloader, new ImportConfiguration);
    }

    public function test_success_replaces_only_after_download_and_backup_and_retains_backup(): void
    {
        $backup = $this->importer()->import('main');
        $this->assertSame(['download', 'backup', 'drop', 'import'], $this->events);
        $this->assertSame('backup SQL', file_get_contents($backup));
        $this->assertFileDoesNotExist($this->paths['dump']);
    }

    public function test_failures_before_replacement_leave_destination_untouched(): void
    {
        foreach (['download' => ['download'], 'backup' => ['download', 'backup'],
            'emptyBackup' => ['download', 'backup'], 'lock' => []] as $failure => $expected) {
            $this->failure = $failure;
            $this->events = [];
            try {
                $this->importer()->import('main');
                $this->fail('Expected failed import.');
            } catch (RuntimeException $exception) {
                $this->assertSame($expected, $this->events);
            }
        }
    }

    public function test_failed_drop_and_import_retain_both_recovery_files(): void
    {
        foreach (['drop', 'import'] as $failure) {
            $this->failure = $failure;
            try {
                $this->importer()->import('main');
                $this->fail('Expected failed replacement.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure . ' failed', $exception->getMessage());
                $this->assertFileExists($this->paths['dump']);
                $this->assertFileExists($this->paths['backup']);
            }
        }
    }

    public function test_production_and_environment_disagreement_are_blocked_before_io(): void
    {
        foreach ([['production', 'production'], ['production', 'staging'], ['staging', 'production'], ['testing', 'testing']] as [$app, $config]) {
            $this->environment($app);
            config(['app.env' => $config]);
            try {
                $this->importer()->import('main');
                $this->fail('Expected environment rejection.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('APP_ENV', $exception->getMessage());
                $this->assertSame([], $this->events);
            }
        }
    }

    public function test_wrong_destination_host_database_and_port_are_blocked_before_download(): void
    {
        foreach (['host' => 'production.example.test', 'database' => 'production_db', 'port' => 9999] as $key => $value) {
            $original = config('database.connections.mysql.' . $key);
            config(['database.connections.mysql.' . $key => $value]);
            \Illuminate\Support\Facades\DB::purge('mysql');
            try {
                $this->importer()->import('main');
                $this->fail('Expected destination rejection.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('destinationGuard.' . $key, $exception->getMessage());
                $this->assertSame([], $this->events);
            }
            config(['database.connections.mysql.' . $key => $original]);
        }
    }

    public function test_api_source_cannot_be_overwritten_through_a_destination_alias(): void
    {
        $this->sameDatabase = true;
        try {
            $this->importer()->import('main');
            $this->fail('Expected source protection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('same database', $exception->getMessage());
            $this->assertSame(['download'], $this->events);
        }
    }

    public function test_remote_staging_destinations_are_allowed_when_explicitly_configured(): void
    {
        config(['database.connections.mysql.host' => 'staging-db.internal']);
        $this->importProfile(['destinationGuard' => ['database' => 'local_db', 'host' => 'staging-db.internal', 'port' => 3307]]);
        $this->importer()->import('main');
        $this->assertSame(['download', 'backup', 'drop', 'import'], $this->events);
    }

    public function test_optional_migrations_run_on_correct_connection_and_failure_keeps_recovery_files(): void
    {
        $this->importProfile(['migrate' => true]);
        Artisan::swap(Mockery::mock(\Illuminate\Contracts\Console\Kernel::class));
        Artisan::shouldReceive('call')->once()->with('migrate', ['--database' => 'mysql', '--force' => true])
            ->andReturnUsing(function () {
                $this->events[] = 'migrate';
                return 1;
            });
        try {
            $this->importer()->import('main');
            $this->fail('Expected failed migrations.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('migrations failed', $exception->getMessage());
            $this->assertSame(['download', 'backup', 'drop', 'import', 'migrate'], $this->events);
            $this->assertFileExists($this->paths['dump']);
            $this->assertFileExists($this->paths['backup']);
        }
    }
}
