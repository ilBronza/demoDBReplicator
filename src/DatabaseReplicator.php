<?php

namespace IlBronza\DemoDBReplicator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DatabaseReplicator
{
    protected MySqlClient $client;

    public function __construct(?MySqlClient $client = null)
    {
        $this->client = $client ?? app(MySqlClient::class);
    }

    public function getDumpFilePath(): string
    {
        return rtrim(config('dbreplicator.dumpFilePath'), '/\\') . '/'
            . config('dbreplicator.dumpFileName') . '.sql';
    }

    public function export(): bool
    {
        $this->client->dump(config('dbreplicator.connections.source'), $this->getDumpFilePath());
        Log::info('Database export completed successfully.');

        return true;
    }

    public function import(): bool
    {
        $path = $this->getDumpFilePath();
        $this->client->import(config('dbreplicator.connections.destination'), $path);
        unlink($path);
        Log::info('Database import completed successfully.');

        return true;
    }

    public function dropAllDestinationTables(?string $name = null): void
    {
        $name = $name ?? config('dbreplicator.connections.destination');
        $settings = $this->client->connectionConfig($name);
        $connection = DB::connection($name);
        $tables = $connection->table('information_schema.tables')
            ->useWritePdo()
            ->where('table_schema', $settings['database'])
            ->get(['TABLE_NAME', 'TABLE_TYPE']);
        $foreignKeys = (int) $connection->selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled', [], false)->enabled;

        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (['VIEW', 'BASE TABLE'] as $type) {
                foreach ($tables as $table) {
                    if ($table->TABLE_TYPE !== $type) {
                        continue;
                    }

                    $identifier = str_replace('`', '``', $table->TABLE_NAME);
                    $object = $type === 'VIEW' ? 'VIEW' : 'TABLE';
                    $connection->statement("DROP {$object} IF EXISTS `{$identifier}`");
                }
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=' . $foreignKeys);
        }

        Log::info("All tables and views dropped from connection [{$name}].");
    }

    public function withDestinationLock(string $name, callable $callback)
    {
        $settings = $this->client->connectionConfig($name);
        // Also serialize aliases that reach the same schema through TCP/socket.
        $identity = $settings['database'];
        $path = $this->client->ensureDirectory() . '/.replicator-' . hash('sha256', $identity) . '.lock';
        $lock = fopen($path, 'c');

        if ($lock === false) {
            throw new RuntimeException('Cannot create database replication lock.');
        }

        try {
            chmod($path, 0600);

            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another database replication is already running.');
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function withDatabaseLock(string $name, callable $callback)
    {
        $settings = $this->client->connectionConfig($name);
        $connection = DB::connection($name);
        $key = 'dbreplicator:' . substr(hash('sha256', $settings['database']), 0, 48);
        $result = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key], false);

        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new RuntimeException('Another database import is already running on this destination.');
        }

        try {
            return $callback();
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$key], false);
        }
    }

    public function dropAllDestinationObjects(string $name): void
    {
        $settings = $this->client->connectionConfig($name);
        $connection = DB::connection($name);
        // Read routine/event metadata before changing anything.
        $events = $connection->table('information_schema.events')->useWritePdo()
            ->where('EVENT_SCHEMA', $settings['database'])->get(['EVENT_NAME']);
        $routines = $connection->table('information_schema.routines')->useWritePdo()
            ->where('ROUTINE_SCHEMA', $settings['database'])->get(['ROUTINE_NAME', 'ROUTINE_TYPE']);

        foreach ($events as $event) {
            $connection->statement('DROP EVENT IF EXISTS `' . str_replace('`', '``', $event->EVENT_NAME) . '`');
        }

        // Dropping tables also removes their triggers.
        $this->dropAllDestinationTables($name);

        foreach ($routines as $routine) {
            if (! in_array($routine->ROUTINE_TYPE, ['FUNCTION', 'PROCEDURE'], true)) {
                throw new RuntimeException('Unknown database routine type.');
            }
            $connection->statement('DROP ' . $routine->ROUTINE_TYPE . ' IF EXISTS `'
                . str_replace('`', '``', $routine->ROUTINE_NAME) . '`');
        }
    }

    protected function assertDifferentDatabases(): void
    {
        $source = $this->client->connectionConfig(config('dbreplicator.connections.source'));
        $target = $this->client->connectionConfig(config('dbreplicator.connections.destination'));
        $normalizeHost = static function (array $connection): string {
            $host = $connection['host'] ?? '127.0.0.1';

            return in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? 'localhost' : $host;
        };

        $sourceSocket = $source['unix_socket'] ?? '';
        $targetSocket = $target['unix_socket'] ?? '';
        $sameSocket = $sourceSocket !== '' && $sourceSocket === $targetSocket;
        $sameHost = $normalizeHost($source) === $normalizeHost($target)
            && ($source['port'] ?? 3306) == ($target['port'] ?? 3306)
            && ($sourceSocket === '' || $targetSocket === '');

        if ($source['database'] === $target['database'] && ($sameSocket || $sameHost)) {
            throw new RuntimeException('Source and destination must be different databases.');
        }
    }

    public static function replicate(): void
    {
        $replicator = app(static::class);
        $replicator->assertDifferentDatabases();
        $destination = config('dbreplicator.connections.destination');

        $replicator->withDestinationLock($destination, function () use ($replicator, $destination) {
            $replicator->export();
            $replicator->dropAllDestinationTables();
            $replicator->import();

            if (config('dbreplicator.migrate')) {
                $replicator->client->migrate($destination, config('dbreplicator.stagingPath'));
            }
        });
    }
}
