<?php

namespace IlBronza\DemoDBReplicator\Tests\Unit;

use IlBronza\DemoDBReplicator\RemoteLaravelDatabaseExporter;
use IlBronza\DemoDBReplicator\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class RemoteLaravelDatabaseExporterTest extends TestCase
{
    protected string $projectDirectory;
    protected string $originalDirectory;
    protected string $originalTimezone;
    protected array $originalCacheEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDirectory = __DIR__ . '/../.runtime/remote-' . bin2hex(random_bytes(4));
        $this->originalDirectory = getcwd();
        $this->originalTimezone = date_default_timezone_get();
        $this->originalCacheEnvironment = [getenv('APP_CONFIG_CACHE'), $_ENV['APP_CONFIG_CACHE'] ?? null, $_SERVER['APP_CONFIG_CACHE'] ?? null];

        foreach (['vendor', 'bootstrap/cache', 'config'] as $directory) {
            mkdir($this->projectDirectory . '/' . $directory, 0700, true);
        }

        $cache = $this->projectDirectory . '/bootstrap/cache/config.php';
        $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $cache;
        putenv('APP_CONFIG_CACHE=' . $cache);
        $autoload = dirname(__DIR__, 3) . '/.ibtest/vendor/autoload.php';
        file_put_contents($this->projectDirectory . '/vendor/autoload.php', '<?php require_once ' . var_export($autoload, true) . ';');
        file_put_contents($this->projectDirectory . '/bootstrap/app.php',
            '<?php return new \Illuminate\Foundation\Application(' . var_export($this->projectDirectory, true) . ');');
        file_put_contents($this->projectDirectory . '/config/app.php',
            "<?php return ['env' => 'production', 'timezone' => 'UTC', 'providers' => ['AProviderThatMustNeverBeBooted']];");
    }

    protected function tearDown(): void
    {
        chdir($this->originalDirectory);
        Container::setInstance($this->app);
        date_default_timezone_set($this->originalTimezone);
        [$value, $env, $server] = $this->originalCacheEnvironment;
        putenv($value === false ? 'APP_CONFIG_CACHE' : 'APP_CONFIG_CACHE=' . $value);
        $_ENV['APP_CONFIG_CACHE'] = $env;
        $_SERVER['APP_CONFIG_CACHE'] = $server;
        unset($_ENV['REMOTE_EXPORT_TEST_HOST'], $_SERVER['REMOTE_EXPORT_TEST_HOST']);
        putenv('REMOTE_EXPORT_TEST_HOST');
        (new Filesystem)->deleteDirectory($this->projectDirectory);

        parent::tearDown();
    }

    protected function databaseConfig(array $connections, string $default = 'production'): void
    {
        file_put_contents($this->projectDirectory . '/config/database.php',
            '<?php return ' . var_export(['default' => $default, 'connections' => $connections], true) . ';');
    }

    protected function mysqlSettings(): array
    {
        return [
            'driver' => 'mysql', 'host' => 'production-db', 'port' => 3309,
            'database' => 'production_database', 'username' => 'production_user',
            'password' => "synthetic\"pass\\word\n", 'charset' => 'utf8mb4',
        ];
    }

    public function test_remote_env_is_loaded_through_laravel_configuration_without_booting_providers(): void
    {
        file_put_contents($this->projectDirectory . '/.env', "REMOTE_EXPORT_TEST_HOST=\"db with spaces\"\n");
        $settings = $this->mysqlSettings();
        $this->databaseConfig(['production' => $settings]);
        $file = $this->projectDirectory . '/config/database.php';
        file_put_contents($file, str_replace("'production-db'", "env('REMOTE_EXPORT_TEST_HOST')", file_get_contents($file)));
        $connection = (new RemoteLaravelDatabaseExporter)->connectionConfig($this->projectDirectory);

        $this->assertSame('db with spaces', $connection['host']);
        $this->assertSame($settings['password'], $connection['password']);
        $this->assertSame('production_database', $connection['database']);
    }

    public function test_cached_configuration_takes_precedence_over_env_and_config_files(): void
    {
        $settings = $this->mysqlSettings();
        $settings['database'] = 'cached_production_database';
        file_put_contents($this->projectDirectory . '/.env', "REMOTE_EXPORT_TEST_HOST=obsolete-host\n");
        file_put_contents($this->projectDirectory . '/config/database.php', "<?php throw new \RuntimeException('Must not load uncached config');");
        file_put_contents($this->projectDirectory . '/bootstrap/cache/config.php',
            '<?php return ' . var_export([
                'app' => ['env' => 'production'],
                'database' => ['default' => 'production', 'connections' => ['production' => $settings]],
            ], true) . ';');
        $connection = (new RemoteLaravelDatabaseExporter)->connectionConfig($this->projectDirectory);

        $this->assertSame('cached_production_database', $connection['database']);
        $this->assertSame('production-db', $connection['host']);
    }

    public function test_named_connection_url_and_write_settings_are_resolved(): void
    {
        $this->databaseConfig([
            'unused' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'chosen' => [
                'url' => 'mysql://url_user:url_password@reader:3310/url_database',
                'write' => ['host' => 'writer', 'port' => 3311],
            ],
        ], 'unused');
        $connection = (new RemoteLaravelDatabaseExporter)->connectionConfig($this->projectDirectory, 'chosen');

        $this->assertSame('writer', $connection['host']);
        $this->assertSame(3311, $connection['port']);
        $this->assertSame('url_user', $connection['username']);
        $this->assertSame('url_password', $connection['password']);
        $this->assertSame('url_database', $connection['database']);
    }

    protected function exporter(): RemoteLaravelDatabaseExporter
    {
        mkdir($this->dumpDirectory, 0700, true);

        return new class($this->dumpDirectory) extends RemoteLaravelDatabaseExporter {
            public array $command = [];
            public array $credentials = [];
            public bool $fail = false;
            protected string $directory;

            public function __construct(string $directory)
            {
                $this->directory = $directory;
            }

            protected function credentialsDirectory(): string
            {
                return $this->directory;
            }

            protected function streamDump(array $command): int
            {
                $this->command = $command;
                $path = substr($command[1], strlen('--defaults-file='));
                $this->credentials = [
                    'path' => $path, 'contents' => file_get_contents($path), 'mode' => fileperms($path) & 0777,
                ];

                if ($this->fail) {
                    throw new RuntimeException('dump failed');
                }

                return 0;
            }
        };
    }

    public function test_export_uses_remote_config_and_removes_private_credentials_file(): void
    {
        $settings = $this->mysqlSettings();
        $this->databaseConfig(['production' => $settings]);
        $bootstrap = $this->projectDirectory . '/bootstrap/app.php';
        file_put_contents($bootstrap, str_replace('<?php ', "<?php echo 'bootstrap debug output must stay remote'; ", file_get_contents($bootstrap)));
        $exporter = $this->exporter();
        $this->expectOutputString('');

        $this->assertSame(0, $exporter->export($this->projectDirectory, '', 'mysqldump', ['--quick']));
        $this->assertContains('--host=production-db', $exporter->command);
        $this->assertContains('--port=3309', $exporter->command);
        $this->assertContains('--user=production_user', $exporter->command);
        $this->assertSame('production_database', end($exporter->command));
        $this->assertStringNotContainsString($settings['password'], implode(' ', $exporter->command));
        $this->assertStringContainsString('password="synthetic\"pass\\\\word\n"', $exporter->credentials['contents']);
        $this->assertSame(0600, $exporter->credentials['mode']);
        $this->assertFileDoesNotExist($exporter->credentials['path']);
    }

    public function test_export_failure_still_removes_remote_credentials_file(): void
    {
        $this->databaseConfig(['production' => $this->mysqlSettings()]);
        $exporter = $this->exporter();
        $exporter->fail = true;

        try {
            $exporter->export($this->projectDirectory, '', 'mysqldump', []);
            $this->fail('Expected failed remote dump.');
        } catch (RuntimeException $exception) {
            $this->assertSame('dump failed', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($exporter->credentials['path']);
    }

    public function test_invalid_project_root_is_rejected_before_export(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remote project root');
        (new RemoteLaravelDatabaseExporter)->connectionConfig($this->projectDirectory . '/missing');
    }

    public function test_non_mysql_remote_connection_is_rejected(): void
    {
        $this->databaseConfig(['production' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MySQL or MariaDB');
        (new RemoteLaravelDatabaseExporter)->connectionConfig($this->projectDirectory);
    }
}
