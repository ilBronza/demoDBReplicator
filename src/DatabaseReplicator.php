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
