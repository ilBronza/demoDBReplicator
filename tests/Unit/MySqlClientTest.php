<?php

namespace IlBronza\DemoDBReplicator\Tests\Unit;

use IlBronza\DemoDBReplicator\Tests\Fixtures\RecordingMySqlClient;
use IlBronza\DemoDBReplicator\Tests\TestCase;
use IlBronza\DemoDBReplicator\DemoDBReplicatorServiceProvider;
use RuntimeException;
use Symfony\Component\Process\Process;

class MySqlClientTest extends TestCase
{
    public function test_dump_streams_stdout_and_keeps_credentials_out_of_arguments(): void
    {
        config(['database.connections.mysql.password' => "a\"b\\c\n'd\$e"]);
        $client = new RecordingMySqlClient;
        $client->chunks = [[Process::OUT, 'first'], [Process::ERR, 'warning'], [Process::OUT, 'second']];
        $path = $this->dumpDirectory . '/dump.sql';
        $client->dump('mysql', $path);

        $this->assertSame('firstsecond', file_get_contents($path));
        $this->assertContains('--host=127.0.0.1', $client->commands[0]);
        $this->assertContains('--port=3307', $client->commands[0]);
        $this->assertContains('--protocol=TCP', $client->commands[0]);
        $this->assertStringNotContainsString('--password=', implode(' ', $client->commands[0]));
        $this->assertStringContainsString('password="a\\"b\\\\c\\n\'d$e"', $client->credentials[0]['contents']);
        $this->assertSame(0600, $client->credentials[0]['mode']);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertFileDoesNotExist($client->credentials[0]['path']);
    }

    public function test_failed_export_preserves_previous_dump_and_removes_partial_and_credentials(): void
    {
        $client = new RecordingMySqlClient;
        $client->ensureDirectory();
        $path = $this->dumpDirectory . '/dump.sql';
        file_put_contents($path, 'previous dump');
        $client->successful = false;
        $client->chunks = [[Process::OUT, 'incomplete'], [Process::ERR, 'connection failed']];

        try {
            $client->dump('mysql', $path);
            $this->fail('Expected the export to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('connection failed', $exception->getMessage());
        }

        $this->assertSame('previous dump', file_get_contents($path));
        $this->assertFileDoesNotExist($path . '.partial');
        $this->assertFileDoesNotExist($client->credentials[0]['path']);
    }

    public function test_empty_successful_export_is_rejected(): void
    {
        $client = new RecordingMySqlClient;
        $client->chunks = [];
        $path = $this->dumpDirectory . '/dump.sql';

        try {
            $client->dump('mysql', $path);
            $this->fail('Expected an empty dump to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('empty', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path . '.partial');
    }

    public function test_import_uses_destination_settings_and_a_file_stream(): void
    {
        config(['database.connections.mysql_replications.unix_socket' => '/tmp/staging mysql.sock']);
        $client = new RecordingMySqlClient;
        $client->ensureDirectory();
        $path = $this->dumpDirectory . '/dump.sql';
        file_put_contents($path, 'dump input');
        $client->import('mysql_replications', $path);

        $this->assertSame('dump input', $client->inputs[0]);
        $this->assertContains('--database=staging_db', $client->commands[0]);
        $this->assertContains('--socket=/tmp/staging mysql.sock', $client->commands[0]);
        $this->assertContains('--protocol=SOCKET', $client->commands[0]);
        $this->assertFileDoesNotExist($client->credentials[0]['path']);
        $this->assertFileExists($path);
    }

    public function test_import_failure_keeps_dump_but_removes_credentials(): void
    {
        $client = new RecordingMySqlClient;
        $client->ensureDirectory();
        $path = $this->dumpDirectory . '/dump.sql';
        file_put_contents($path, 'dump input');
        $client->successful = false;

        try {
            $client->import('mysql', $path);
            $this->fail('Expected import failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed', $exception->getMessage());
        }

        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($client->credentials[0]['path']);
    }

    public function test_connection_url_is_resolved_before_building_client_arguments(): void
    {
        config(['database.connections.mysql.url' => 'mysql://url_user:url_password@localhost:3310/url_db']);
        $client = new RecordingMySqlClient;
        $client->dump('mysql', $this->dumpDirectory . '/dump.sql');

        $this->assertContains('--host=localhost', $client->commands[0]);
        $this->assertContains('--port=3310', $client->commands[0]);
        $this->assertContains('--user=url_user', $client->commands[0]);
        $this->assertSame('url_db', end($client->commands[0]));
    }

    public function test_process_exception_stops_the_process_and_removes_temporary_files(): void
    {
        $client = new RecordingMySqlClient;
        $client->runException = new RuntimeException('process timeout');
        $path = $this->dumpDirectory . '/dump.sql';

        try {
            $client->dump('mysql', $path);
            $this->fail('Expected process failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('process timeout', $exception->getMessage());
        }

        $this->assertTrue($client->stopped);
        $this->assertFileDoesNotExist($path . '.partial');
        $this->assertFileDoesNotExist($client->credentials[0]['path']);
    }

    public function test_published_option_and_host_lists_replace_package_defaults(): void
    {
        config([
            'dbreplicator.dumpOptions' => ['--single-transaction', '--quick'],
            'dbreplicator.pull.localHosts' => ['localhost'],
        ]);
        (new DemoDBReplicatorServiceProvider($this->app))->register();

        $this->assertSame(['--single-transaction', '--quick'], config('dbreplicator.dumpOptions'));
        $this->assertSame(['localhost'], config('dbreplicator.pull.localHosts'));
    }
}
