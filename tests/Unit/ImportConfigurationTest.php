<?php

namespace IlBronza\DemoDBReplicator\Tests\Unit;

use IlBronza\DemoDBReplicator\ImportConfiguration;
use IlBronza\DemoDBReplicator\Tests\TestCase;
use RuntimeException;

class ImportConfigurationTest extends TestCase
{
    public function test_insecure_or_ambiguous_api_urls_and_short_tokens_are_rejected(): void
    {
        foreach (['http://production.test/export', 'https://user:password@production.test/export',
            'https://production.test/export?token=secret', 'https://production.test/export#fragment', 'invalid'] as $url) {
            $this->importProfile(['url' => $url]);
            try {
                (new ImportConfiguration)->get('main');
                $this->fail('Expected invalid URL.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('HTTPS', $exception->getMessage());
            }
        }
        $this->importProfile(['token' => 'short']);
        $this->expectExceptionMessage('at least 32');
        (new ImportConfiguration)->get('main');
    }

    public function test_a_destination_guard_is_mandatory(): void
    {
        $profile = $this->importProfile(['destinationGuard' => []]);
        $this->expectExceptionMessage('destinationGuard.database');
        (new ImportConfiguration)->assertDestination($profile, config('database.connections.mysql'));
    }

    public function test_socket_destination_is_checked_against_the_configured_socket(): void
    {
        $profile = $this->importProfile(['destinationGuard' => ['database' => 'local_db', 'unix_socket' => '/tmp/mysql.sock']]);
        $connection = array_merge(config('database.connections.mysql'), ['unix_socket' => '/tmp/mysql.sock']);
        (new ImportConfiguration)->assertDestination($profile, $connection);
        $connection['unix_socket'] = '/tmp/production.sock';
        $this->expectExceptionMessage('destinationGuard.unix_socket');
        (new ImportConfiguration)->assertDestination($profile, $connection);
    }
}
