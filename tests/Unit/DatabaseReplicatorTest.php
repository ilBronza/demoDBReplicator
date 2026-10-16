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

    public function test_full_replacement_removes_events_tables_views_triggers_and_routines(): void
    {
        $statements = [];
        $client = Mockery::mock(MySqlClient::class);
        $client->shouldReceive('connectionConfig')->with('mysql')->andReturn(['database' => 'local_db']);
        $connection = Mockery::mock();
        foreach ([
            'events' => ['EVENT_SCHEMA', ['EVENT_NAME'], [(object) ['EVENT_NAME' => 'old`event']]],
            'routines' => ['ROUTINE_SCHEMA', ['ROUTINE_NAME', 'ROUTINE_TYPE'], [
                (object) ['ROUTINE_NAME' => 'old_function', 'ROUTINE_TYPE' => 'FUNCTION'],
                (object) ['ROUTINE_NAME' => 'old_procedure', 'ROUTINE_TYPE' => 'PROCEDURE'],
            ]],
            'tables' => ['table_schema', ['TABLE_NAME', 'TABLE_TYPE'], [
                (object) ['TABLE_NAME' => 'old_table', 'TABLE_TYPE' => 'BASE TABLE'],
                (object) ['TABLE_NAME' => 'old_view', 'TABLE_TYPE' => 'VIEW'],
            ]],
        ] as $table => [$column, $columns, $rows]) {
            $query = Mockery::mock();
            $query->shouldReceive('useWritePdo')->once()->andReturnSelf();
            $query->shouldReceive('where')->once()->with($column, 'local_db')->andReturnSelf();
            $query->shouldReceive('get')->once()->with($columns)->andReturn(collect($rows));
            $connection->shouldReceive('table')->once()->with('information_schema.' . $table)->andReturn($query);
        }
        $connection->shouldReceive('selectOne')->with('SELECT @@FOREIGN_KEY_CHECKS AS enabled', [], false)
            ->andReturn((object) ['enabled' => 1]);
        $connection->shouldReceive('statement')->andReturnUsing(function ($sql) use (&$statements) {
            $statements[] = $sql;
            return true;
        });
        DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

        (new DatabaseReplicator($client))->dropAllDestinationObjects('mysql');
        $this->assertSame([
            'DROP EVENT IF EXISTS `old``event`', 'SET FOREIGN_KEY_CHECKS=0',
            'DROP VIEW IF EXISTS `old_view`', 'DROP TABLE IF EXISTS `old_table`',
            'SET FOREIGN_KEY_CHECKS=1', 'DROP FUNCTION IF EXISTS `old_function`',
            'DROP PROCEDURE IF EXISTS `old_procedure`',
        ], $statements);
    }

    public function test_database_lock_is_released_after_failure_and_a_busy_lock_never_executes_callback(): void
    {
        $client = Mockery::mock(MySqlClient::class);
        $client->shouldReceive('connectionConfig')->with('mysql')->andReturn(['database' => 'local_db']);
        $connection = Mockery::mock();
        $key = 'dbreplicator:' . substr(hash('sha256', 'local_db'), 0, 48);
        $connection->shouldReceive('selectOne')->with('SELECT GET_LOCK(?, 0) AS acquired', [$key], false)
            ->twice()->andReturn((object) ['acquired' => 1], (object) ['acquired' => 0]);
        $connection->shouldReceive('selectOne')->with('SELECT RELEASE_LOCK(?) AS released', [$key], false)
            ->once()->andReturn((object) ['released' => 1]);
        DB::shouldReceive('connection')->with('mysql')->andReturn($connection);
        $replicator = new DatabaseReplicator($client);
        try {
            $replicator->withDatabaseLock('mysql', static function () {
                throw new RuntimeException('callback failed');
            });
            $this->fail('Expected callback failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failed', $exception->getMessage());
        }
        $this->expectExceptionMessage('already running');
        $replicator->withDatabaseLock('mysql', function () {
            $this->fail('A busy lock must block the callback.');
        });
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
