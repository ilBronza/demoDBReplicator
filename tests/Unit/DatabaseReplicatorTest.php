<?php

namespace IlBronza\DemoDBReplicator\Tests\Unit;

use IlBronza\DemoDBReplicator\DatabaseReplicator;
use IlBronza\DemoDBReplicator\MySqlClient;
use IlBronza\DemoDBReplicator\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;

class DatabaseReplicatorTest extends TestCase
{
    public function test_identical_source_and_destination_are_rejected_before_export(): void
    {
        config(['database.connections.mysql_replications' => config('database.connections.mysql')]);
        $replicator = Mockery::mock(DatabaseReplicator::class, [new MySqlClient])->makePartial();
        $replicator->shouldNotReceive('export');
        $this->app->instance(DatabaseReplicator::class, $replicator);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different databases');
        DatabaseReplicator::replicate();
    }

    public function test_staging_artisan_command_calls_the_replication_service_in_order(): void
    {
        $events = [];
        $replicator = Mockery::mock(DatabaseReplicator::class, [new MySqlClient])->makePartial();
        $replicator->shouldReceive('withDestinationLock')->once()->andReturnUsing(function ($connection, $callback) {
            $this->assertSame('mysql_replications', $connection);

            return $callback();
        });

        foreach (['export', 'dropAllDestinationTables', 'import'] as $method) {
            $replicator->shouldReceive($method)->once()->andReturnUsing(function () use (&$events, $method) {
                $events[] = $method;

                return $method === 'dropAllDestinationTables' ? null : true;
            });
        }

        $this->app->instance(DatabaseReplicator::class, $replicator);
        $this->artisan('dbreplicator:replicate')->assertExitCode(0);
        $this->assertSame(['export', 'dropAllDestinationTables', 'import'], $events);
    }

    public function test_socket_and_tcp_aliases_cannot_copy_a_database_over_itself(): void
    {
        config([
            'database.connections.mysql_replications' => config('database.connections.mysql'),
            'database.connections.mysql.unix_socket' => '/tmp/mysql.sock',
            'database.connections.mysql_replications.host' => 'localhost',
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different databases');
        DatabaseReplicator::replicate();
    }

    public function test_destination_lock_is_released_after_a_callback_failure(): void
    {
        $replicator = new DatabaseReplicator(new MySqlClient);

        try {
            $replicator->withDestinationLock('mysql', static function () {
                throw new RuntimeException('callback failed');
            });
            $this->fail('Expected callback failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failed', $exception->getMessage());
        }

        $this->assertSame('released', $replicator->withDestinationLock('mysql', static function () {
            return 'released';
        }));
    }

    public function test_views_are_dropped_before_tables_and_foreign_keys_are_restored(): void
    {
        $statements = [];
        $replicator = $this->replicatorWithFakeTables($statements);
        $replicator->dropAllDestinationTables();

        $this->assertSame([
            'SET FOREIGN_KEY_CHECKS=0', 'DROP VIEW IF EXISTS `report`',
            'DROP TABLE IF EXISTS `odd``table`', 'SET FOREIGN_KEY_CHECKS=1',
        ], $statements);
    }

    public function test_foreign_keys_are_restored_even_when_drop_fails(): void
    {
        $statements = [];
        $replicator = $this->replicatorWithFakeTables($statements, true);

        try {
            $replicator->dropAllDestinationTables();
            $this->fail('Expected DROP failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('DROP failed', $exception->getMessage());
        }

        $this->assertSame('SET FOREIGN_KEY_CHECKS=1', end($statements));
    }

    protected function replicatorWithFakeTables(array &$statements, bool $fail = false): DatabaseReplicator
    {
        $client = Mockery::mock(MySqlClient::class);
        $client->shouldReceive('connectionConfig')->with('mysql_replications')->andReturn(['database' => 'staging_db']);
        $query = Mockery::mock();
        $query->shouldReceive('useWritePdo')->andReturnSelf();
        $query->shouldReceive('where')->with('table_schema', 'staging_db')->andReturnSelf();
        $query->shouldReceive('get')->with(['TABLE_NAME', 'TABLE_TYPE'])->andReturn(collect([
            (object) ['TABLE_NAME' => 'odd`table', 'TABLE_TYPE' => 'BASE TABLE'],
            (object) ['TABLE_NAME' => 'report', 'TABLE_TYPE' => 'VIEW'],
        ]));
        $connection = Mockery::mock();
        $connection->shouldReceive('table')->with('information_schema.tables')->andReturn($query);
        $connection->shouldReceive('selectOne')->with('SELECT @@FOREIGN_KEY_CHECKS AS enabled', [], false)
            ->andReturn((object) ['enabled' => 1]);
        $connection->shouldReceive('statement')->andReturnUsing(function ($sql) use (&$statements, $fail) {
            $statements[] = $sql;

            if ($fail && strpos($sql, 'DROP ') === 0) {
                throw new RuntimeException('DROP failed');
            }

            return true;
        });
        DB::shouldReceive('connection')->with('mysql_replications')->andReturn($connection);

        return new DatabaseReplicator($client);
    }
}
