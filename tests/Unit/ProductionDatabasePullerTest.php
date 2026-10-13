<?php

namespace IlBronza\DemoDBReplicator\Tests\Unit;

use IlBronza\DemoDBReplicator\DatabaseReplicator;
use IlBronza\DemoDBReplicator\MySqlClient;
use IlBronza\DemoDBReplicator\ProductionDatabasePuller;
use IlBronza\DemoDBReplicator\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;

class ProductionDatabasePullerTest extends TestCase
{
    protected array $events = [];
    protected ?string $failure = null;
    protected array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->environment('local');
    }

    protected function puller(): ProductionDatabasePuller
    {
        $client = Mockery::mock(MySqlClient::class)->makePartial();
        $client->shouldReceive('runToFile')->andReturnUsing(function ($command, $path) use ($client) {
            $this->events[] = 'download';

            if ($this->failure === 'download') {
                throw new RuntimeException('SSH transfer failed');
            }

            $client->ensureDirectory();
            file_put_contents($path, 'production dump');
            $this->paths['dump'] = $path;
        });
        $client->shouldReceive('dump')->andReturnUsing(function ($connection, $path) {
            $this->assertSame('mysql', $connection);
            $this->events[] = 'backup';

            if ($this->failure === 'backup') {
                throw new RuntimeException('Local backup failed');
            }

            file_put_contents($path, 'local backup');
            $this->paths['backup'] = $path;
        });
        $client->shouldReceive('import')->andReturnUsing(function ($connection, $path) {
            $this->assertSame('mysql', $connection);
            $this->assertSame('production dump', file_get_contents($path));
            $this->events[] = 'import';

            if ($this->failure === 'import') {
                throw new RuntimeException('Import failed');
            }
        });
        $replicator = Mockery::mock(DatabaseReplicator::class);
        $replicator->shouldReceive('withDestinationLock')->andReturnUsing(function ($connection, $callback) {
            return $callback();
        });
        $replicator->shouldReceive('dropAllDestinationTables')->with('mysql')->andReturnUsing(function () {
            $this->events[] = 'drop';
        });

        return new ProductionDatabasePuller($client, $replicator);
    }

    public function test_success_backs_up_before_drop_and_retains_the_backup(): void
    {
        $backup = $this->puller()->pull();

        $this->assertSame(['download', 'backup', 'drop', 'import'], $this->events);
        $this->assertSame('local backup', file_get_contents($backup));
        $this->assertFileDoesNotExist($this->paths['dump']);
    }

    public function test_failed_download_never_backs_up_drops_or_imports(): void
    {
        $this->failure = 'download';

        try {
            $this->puller()->pull();
            $this->fail('Expected failed download.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SSH transfer failed', $exception->getMessage());
        }

        $this->assertSame(['download'], $this->events);
    }

    public function test_failed_backup_never_drops_or_imports_and_keeps_downloaded_dump(): void
    {
        $this->failure = 'backup';

        try {
            $this->puller()->pull();
            $this->fail('Expected failed backup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local backup failed', $exception->getMessage());
        }

        $this->assertSame(['download', 'backup'], $this->events);
        $this->assertFileExists($this->paths['dump']);
    }

    public function test_failed_import_retains_backup_and_production_dump(): void
    {
        $this->failure = 'import';

        try {
            $this->puller()->pull();
            $this->fail('Expected failed import.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Import failed', $exception->getMessage());
        }

        $this->assertFileExists($this->paths['backup']);
        $this->assertFileExists($this->paths['dump']);
    }

    public function test_local_migrations_run_on_destination_after_import(): void
    {
        config(['dbreplicator.migrate' => true]);
        Artisan::swap(Mockery::mock(Kernel::class));
        Artisan::shouldReceive('call')->with('migrate', ['--database' => 'mysql', '--force' => true])
            ->once()->andReturnUsing(function () {
                $this->events[] = 'migrate';

                return 0;
            });
        $this->puller()->pull();

        $this->assertSame(['download', 'backup', 'drop', 'import', 'migrate'], $this->events);
    }

    public function test_migration_failure_preserves_recovery_files(): void
    {
        config(['dbreplicator.migrate' => true]);
        Artisan::swap(Mockery::mock(Kernel::class));
        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('migration error');

        try {
            $this->puller()->pull();
            $this->fail('Expected failed migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('migration error', $exception->getMessage());
        }

        $this->assertFileExists($this->paths['backup']);
        $this->assertFileExists($this->paths['dump']);
    }

    public function test_no_migrate_skips_migrations_even_when_enabled_in_config(): void
    {
        config(['dbreplicator.migrate' => true]);
        Artisan::swap(Mockery::mock(Kernel::class));
        Artisan::shouldReceive('call')->never();
        $this->puller()->pull(false);

        $this->assertSame(['download', 'backup', 'drop', 'import'], $this->events);
    }

    public function test_pull_is_rejected_outside_local_before_any_io(): void
    {
        $this->environment('production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=local');
        $client = Mockery::mock(MySqlClient::class);
        $client->shouldNotReceive('connectionConfig');
        (new ProductionDatabasePuller($client, Mockery::mock(DatabaseReplicator::class)))->pull();
    }

    public function test_remote_destination_is_rejected_before_download(): void
    {
        config(['database.connections.mysql.host' => 'production.example.test']);

        try {
            $this->puller()->pull();
            $this->fail('Expected remote destination rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local database host', $exception->getMessage());
        }

        $this->assertSame([], $this->events);
    }

    public function test_missing_remote_configuration_is_rejected_before_download(): void
    {
        config(['dbreplicator.pull.remote.defaultsFile' => null]);

        try {
            $this->puller()->pull();
            $this->fail('Expected missing configuration rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('remote MySQL defaults file', $exception->getMessage());
        }

        $this->assertSame([], $this->events);
    }

    public function test_remote_shell_arguments_are_escaped_and_ssh_uses_key_authentication(): void
    {
        config([
            'dbreplicator.pull.remote.database' => 'prod; touch injected',
            'dbreplicator.pull.remote.defaultsFile' => "/home/deploy/my 'credentials'.cnf",
            'dbreplicator.pull.ssh.identityFile' => '/home/local/key with spaces',
        ]);
        $command = $this->puller()->remoteDumpCommand();

        $this->assertContains('BatchMode=yes', $command);
        $this->assertContains('/home/local/key with spaces', $command);
        $this->assertContains('deploy@production.example.test', $command);
        $remote = end($command);
        $this->assertStringContainsString(escapeshellarg('prod; touch injected'), $remote);
        $this->assertStringContainsString(escapeshellarg("--defaults-file=/home/deploy/my 'credentials'.cnf"), $remote);
        $this->assertStringNotContainsString('--password=', $remote);
    }

    public function test_artisan_pull_command_reports_success_and_failure_exit_codes(): void
    {
        $this->app->instance(ProductionDatabasePuller::class, $this->puller());
        $this->artisan('dbreplicator:pull', ['--no-migrate' => true])->assertExitCode(0);
        $this->failure = 'download';
        $this->artisan('dbreplicator:pull')->expectsOutput('SSH transfer failed')->assertExitCode(1);
    }

    public function test_remote_project_root_needs_no_defaults_file_or_database_name(): void
    {
        config([
            'dbreplicator.pull.remote.projectPath' => "/var/www/project with 'quotes'",
            'dbreplicator.pull.remote.defaultsFile' => null,
            'dbreplicator.pull.remote.database' => null,
            'dbreplicator.pull.remote.connection' => 'production',
        ]);
        $command = $this->puller()->remoteDumpCommand();
        $remote = end($command);

        $this->assertStringContainsString(escapeshellarg("/var/www/project with 'quotes'"), $remote);
        $this->assertStringContainsString(escapeshellarg('production'), $remote);
        $this->assertStringContainsString("'php' '-r'", $remote);
        $this->assertStringContainsString('RemoteLaravelDatabaseExporter', $remote);
        $this->puller()->pull();
        $this->assertSame(['download', 'backup', 'drop', 'import'], $this->events);
    }

    public function test_remote_php_code_is_valid_and_project_mode_takes_precedence(): void
    {
        $puller = new class(new MySqlClient, Mockery::mock(DatabaseReplicator::class)) extends ProductionDatabasePuller {
            public function arguments(array $remote): array
            {
                return $this->remoteLaravelArguments($remote);
            }
        };
        $remote = config('dbreplicator.pull.remote');
        $remote['projectPath'] = '/var/www/production';
        $arguments = $puller->arguments($remote);
        $tokens = token_get_all('<?php ' . $arguments[2], TOKEN_PARSE);

        $this->assertNotEmpty($tokens);
        $this->assertSame('', $arguments[4]);
        $this->assertSame(config('dbreplicator.dumpOptions'), json_decode($arguments[6], true));

        config(['dbreplicator.pull.remote.projectPath' => $remote['projectPath']]);
        $command = $this->puller()->remoteDumpCommand();
        $this->assertStringNotContainsString('/home/deploy/.mysql.cnf', end($command));
    }
}
