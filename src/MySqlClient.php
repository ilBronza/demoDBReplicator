<?php

namespace IlBronza\DemoDBReplicator;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class MySqlClient
{
    public function databaseIdentity(string $name): string
    {
        $settings = $this->connectionConfig($name);
        $server = DB::connection($name)->selectOne('SELECT @@hostname AS hostname, @@port AS port', [], false);

        return hash('sha256', json_encode([
            (string) $server->hostname, (string) $server->port, $settings['database'],
        ], JSON_THROW_ON_ERROR));
    }

    public function connectionConfig(string $name): array
    {
        if (! is_array(config("database.connections.{$name}"))) {
            throw new RuntimeException("Database connection [{$name}] is not configured.");
        }

        // Laravel resolves connection URLs here, without opening the connection.
        $connection = DB::connection($name)->getConfig();

        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Database connection [{$name}] must use MySQL or MariaDB.");
        }

        $this->validateDatabaseName($connection['database'] ?? '');

        if (isset($connection['host']) && ! is_string($connection['host'])) {
            throw new RuntimeException("Database connection [{$name}] must specify a single host.");
        }

        return $connection;
    }

    public function validateDatabaseName(string $database): void
    {
        if ($database === '' || $database[0] === '-' || preg_match('/[\x00-\x1f\x7f]/', $database)) {
            throw new RuntimeException('A valid database name is required.');
        }
    }

    public function dumpOptions(): array
    {
        return config('dbreplicator.dumpOptions', [
            '--single-transaction', '--quick', '--routines', '--events', '--triggers',
        ]);
    }

    public function ensureDirectory(): string
    {
        $directory = rtrim(config('dbreplicator.dumpFilePath'), '/\\');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create dump directory [{$directory}].");
        }

        return $directory;
    }

    public function dump(string $connection, string $path): void
    {
        $settings = $this->connectionConfig($connection);

        $this->withCredentials($settings, function (string $credentials) use ($settings, $path) {
            $command = array_merge([
                config('dbreplicator.mySqlDumpPath', 'mysqldump'),
                '--defaults-file=' . $credentials,
            ], $this->connectionArguments($settings), $this->dumpOptions(), [$settings['database']]);

            $this->runToFile($command, $path);
        });
    }

    public function import(string $connection, string $path): void
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException("Dump file missing or empty at [{$path}].");
        }

        $settings = $this->connectionConfig($connection);
        $input = fopen($path, 'rb');

        if ($input === false) {
            throw new RuntimeException("Cannot read dump file [{$path}].");
        }

        try {
            $this->withCredentials($settings, function (string $credentials) use ($settings, $input) {
                $this->run($this->process(array_merge([
                    config('dbreplicator.mysqlPath', 'mysql'),
                    '--defaults-file=' . $credentials,
                    '--database=' . $settings['database'],
                ], $this->connectionArguments($settings)), $input));
            });
        } finally {
            fclose($input);
        }
    }

    public function runToFile(array $command, string $path): void
    {
        $this->ensureDirectory();
        $partial = $path . '.partial';
        $output = fopen($partial, 'xb');

        if ($output === false) {
            throw new RuntimeException("Cannot create temporary dump [{$partial}].");
        }

        try {
            if (! chmod($partial, 0600)) {
                throw new RuntimeException('Cannot protect the dump file.');
            }

            $this->run($this->process($command), function (string $buffer) use ($output) {
                $offset = 0;
                $length = strlen($buffer);

                while ($offset < $length) {
                    $written = fwrite($output, substr($buffer, $offset));

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Cannot write the database dump.');
                    }

                    $offset += $written;
                }
            });

            $size = ftell($output);
            fclose($output);
            $output = null;

            if (! $size) {
                throw new RuntimeException('The database dump is empty.');
            }

            if (! rename($partial, $path)) {
                throw new RuntimeException("Cannot save dump at [{$path}].");
            }
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }

            if (is_file($partial)) {
                unlink($partial);
            }
        }
    }

    public function migrate(string $connection, string $projectPath): void
    {
        if (! is_file(rtrim($projectPath, '/\\') . '/artisan')) {
            throw new RuntimeException("Laravel project not found at [{$projectPath}].");
        }

        $this->run($this->process([
            PHP_BINARY, 'artisan', 'migrate', '--database=' . $connection, '--force',
        ], null, $projectPath));
    }

    protected function process(array $command, $input = null, ?string $directory = null): Process
    {
        return new Process($command, $directory, null, $input, config('dbreplicator.timeout', 1800));
    }

    protected function connectionArguments(array $settings): array
    {
        // Explicit arguments override host/socket settings from MySQL login files.
        $arguments = ['--user=' . ($settings['username'] ?? '')];

        if (! empty($settings['unix_socket'])) {
            $arguments[] = '--protocol=SOCKET';
            $arguments[] = '--socket=' . $settings['unix_socket'];
        } else {
            $arguments[] = '--protocol=TCP';
            $arguments[] = '--host=' . ($settings['host'] ?? '127.0.0.1');
            $arguments[] = '--port=' . ($settings['port'] ?? 3306);
        }

        if (! empty($settings['charset'])) {
            $arguments[] = '--default-character-set=' . $settings['charset'];
        }

        return $arguments;
    }

    protected function run(Process $process, ?callable $output = null): void
    {
        $errors = '';

        try {
            $process->run(function ($type, $buffer) use ($process, $output, &$errors) {
                if ($type === Process::OUT) {
                    if ($output) {
                        $output($buffer);
                    }

                    // Do not retain a potentially multi-gigabyte dump in PHP memory.
                    $process->clearOutput();
                } else {
                    $errors = substr($errors . $buffer, -8192);
                    $process->clearErrorOutput();
                }
            });
        } catch (Throwable $exception) {
            $process->stop(0);

            throw $exception;
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Database process failed: ' . trim($errors));
        }
    }

    protected function withCredentials(array $settings, callable $callback): void
    {
        $path = tempnam($this->ensureDirectory(), '.mysql-');

        if ($path === false) {
            throw new RuntimeException('Cannot create MySQL credentials file.');
        }

        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('Cannot protect MySQL credentials.');
            }

            $options = [
                'user' => $settings['username'] ?? '',
                'password' => $settings['password'] ?? '',
            ];

            if (! empty($settings['unix_socket'])) {
                $options['socket'] = $settings['unix_socket'];
            } else {
                $options['host'] = $settings['host'] ?? '127.0.0.1';
                $options['port'] = $settings['port'] ?? 3306;
            }

            if (! empty($settings['charset'])) {
                $options['default-character-set'] = $settings['charset'];
            }

            $contents = "[client]\n";

            foreach ($options as $key => $value) {
                $escaped = strtr((string) $value, [
                    '\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t',
                ]);
                $contents .= $key . '="' . $escaped . '"' . "\n";
            }

            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('Cannot write MySQL credentials.');
            }

            $callback($path);
        } finally {
            unlink($path);
        }
    }
}
