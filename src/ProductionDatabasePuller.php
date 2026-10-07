<?php

namespace IlBronza\DemoDBReplicator;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class ProductionDatabasePuller
{
    protected MySqlClient $client;
    protected DatabaseReplicator $replicator;

    public function __construct(MySqlClient $client, DatabaseReplicator $replicator)
    {
        $this->client = $client;
        $this->replicator = $replicator;
    }

    public function pull(bool $migrate = true, ?callable $progress = null): string
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Production pulls are only allowed with APP_ENV=local.');
        }

        $destination = config('dbreplicator.pull.destination', 'mysql');
        $settings = $this->client->connectionConfig($destination);

        if (empty($settings['unix_socket'])
            && ! in_array($settings['host'] ?? '127.0.0.1', config('dbreplicator.pull.localHosts'), true)) {
            throw new RuntimeException('The destination must be a configured local database host.');
        }

        $command = $this->remoteDumpCommand();

        return $this->replicator->withDestinationLock($destination, function () use ($destination, $command, $migrate, $progress) {
            $directory = $this->client->ensureDirectory();
            $suffix = date('Ymd_His') . '-' . bin2hex(random_bytes(4));
            $dump = $directory . '/production-' . $suffix . '.sql';
            $backup = $directory . '/local-backup-' . $suffix . '.sql';
            $report = $progress ?? static function (string $message) {};

            $report('Downloading production dump over SSH...');
            $this->client->runToFile($command, $dump);
            $report('Production dump saved at: ' . $dump);

            $report('Backing up the local database...');
            $this->client->dump($destination, $backup);
            $report('Local backup retained at: ' . $backup);

            $report('Replacing the local database...');
            $this->replicator->dropAllDestinationTables($destination);
            $this->client->import($destination, $dump);

            if ($migrate && config('dbreplicator.migrate')) {
                $report('Running local migrations...');

                if (Artisan::call('migrate', ['--database' => $destination, '--force' => true]) !== 0) {
                    throw new RuntimeException('Local migrations failed. ' . Artisan::output());
                }
            }

            unlink($dump);

            return $backup;
        });
    }

    public function remoteDumpCommand(): array
    {
        $ssh = config('dbreplicator.pull.ssh');
        $remote = config('dbreplicator.pull.remote');

        if (empty($ssh['host']) || empty($remote['database']) || empty($remote['defaultsFile'])) {
            throw new RuntimeException('Configure the SSH host, production database and remote MySQL defaults file.');
        }

        $this->client->validateDatabaseName($remote['database']);

        $command = [
            $ssh['binary'], '-T', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15',
            '-p', (string) $ssh['port'],
        ];

        if (! empty($ssh['identityFile'])) {
            $command[] = '-i';
            $command[] = $ssh['identityFile'];
        }

        $command[] = '--';
        $command[] = empty($ssh['user']) ? $ssh['host'] : $ssh['user'] . '@' . $ssh['host'];
        $arguments = array_merge([
            $remote['mysqldumpPath'], '--defaults-file=' . $remote['defaultsFile'],
        ], $this->client->dumpOptions(), [$remote['database']]);

        // SSH executes this one argument through the remote POSIX shell.
        $command[] = implode(' ', array_map('escapeshellarg', $arguments));

        return $command;
    }
}
