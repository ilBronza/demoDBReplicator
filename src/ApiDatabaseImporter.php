<?php

namespace IlBronza\DemoDBReplicator;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ApiDatabaseImporter
{
    protected MySqlClient $client;
    protected DatabaseReplicator $replicator;
    protected RemoteApiDownloader $downloader;
    protected ImportConfiguration $configuration;

    public function __construct(MySqlClient $client, DatabaseReplicator $replicator,
        RemoteApiDownloader $downloader, ImportConfiguration $configuration)
    {
        $this->client = $client;
        $this->replicator = $replicator;
        $this->downloader = $downloader;
        $this->configuration = $configuration;
    }

    public function import(string $name): string
    {
        ImportAccess::assertEnvironment();
        $profile = $this->configuration->get($name);
        $destination = $profile['destination'];
        $this->configuration->assertDestination($profile, $this->client->connectionConfig($destination));

        return $this->replicator->withDestinationLock($destination, function () use ($profile, $destination, $name) {
            return $this->replicator->withDatabaseLock($destination, function () use ($profile, $destination, $name) {
                $suffix = bin2hex(random_bytes(16));
                $directory = $this->client->ensureDirectory();
                $dump = $directory . '/api-production-' . $suffix . '.sql';
                $backup = $directory . '/staging-backup-' . $suffix . '.sql';
                $stage = 'download';

                try {
                    $source = $this->downloader->download($profile, $dump);
                    $stage = 'destination identity';
                    if (hash_equals($source, $this->client->databaseIdentity($destination))) {
                        throw new RuntimeException('Source and destination identify the same database; import refused.');
                    }

                    $stage = 'backup';
                    $this->client->dump($destination, $backup);
                    if (! is_file($backup) || filesize($backup) === 0) {
                        throw new RuntimeException('The destination backup is missing or empty; import refused.');
                    }

                    // Recheck immediately before the first destructive statement.
                    $stage = 'destination validation';
                    ImportAccess::assertEnvironment();
                    $this->configuration->assertDestination($profile, $this->client->connectionConfig($destination));
                    Log::info('Database import replacing destination.', ['profile' => $name, 'backup' => $backup]);
                    $stage = 'drop';
                    $this->replicator->dropAllDestinationObjects($destination);
                    $stage = 'import';
                    $this->client->import($destination, $dump);

                    $stage = 'migrations';
                    if (($profile['migrate'] ?? false) === true
                        && Artisan::call('migrate', ['--database' => $destination, '--force' => true]) !== 0) {
                        throw new RuntimeException('Destination migrations failed.');
                    }

                    $stage = 'cleanup';
                    unlink($dump);
                    Log::info('Database import completed.', ['profile' => $name, 'backup' => $backup]);

                    return $backup;
                } catch (Throwable $exception) {
                    // Paths permit recovery without logging credentials or SQL.
                    Log::error('Database import failed; available recovery files retained.', [
                        'profile' => $name, 'dump' => $dump, 'backup' => $backup,
                        'stage' => $stage, 'exception_type' => get_class($exception),
                    ]);
                    throw $exception;
                }
            });
        });
    }
}
