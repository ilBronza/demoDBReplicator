<?php

namespace IlBronza\DemoDBReplicator;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use RuntimeException;

/**
 * Sent to the remote PHP CLI over SSH; requires no package installation there.
 * Keep this class independent of other classes in this package.
 */
class RemoteLaravelDatabaseExporter
{
    public function export(string $root, string $connection, string $binary, array $options): int
    {
        // Bootstrap/config output must never contaminate SQL or expose secrets.
        ob_start();

        try {
            $settings = $this->connectionConfig($root, $connection);
        } finally {
            ob_end_clean();
        }

        $credentials = $this->credentialsFile($settings);

        try {
            $arguments = [$binary, '--defaults-file=' . $credentials, '--user=' . ($settings['username'] ?? '')];

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

            return $this->streamDump(array_merge($arguments, $options, [$settings['database']]));
        } finally {
            unlink($credentials);
        }
    }

    public function connectionConfig(string $root, string $connection = ''): array
    {
        $root = rtrim($root, '/');

        if (! is_file($root . '/vendor/autoload.php') || ! is_file($root . '/bootstrap/app.php')) {
            throw new RuntimeException('The remote project root must contain vendor/autoload.php and bootstrap/app.php.');
        }

        if (! chdir($root)) {
            throw new RuntimeException('Cannot access the remote project root.');
        }

        require_once $root . '/vendor/autoload.php';
        $app = require $root . '/bootstrap/app.php';

        // Load only environment/configuration: do not boot providers, jobs or commands.
        (new LoadEnvironmentVariables)->bootstrap($app);
        (new LoadConfiguration)->bootstrap($app);
        $name = $connection !== '' ? $connection : $app['config']->get('database.default');
        $settings = $app['config']->get('database.connections.' . $name);

        if (! is_array($settings)) {
            throw new RuntimeException('The requested remote database connection is not configured.');
        }

        $settings = (new ConfigurationUrlParser)->parseConfiguration($settings);

        if (isset($settings['write'])) {
            if (! is_array($settings['write']) || isset($settings['write'][0])) {
                throw new RuntimeException('The remote connection must specify a single write configuration.');
            }

            $settings = array_replace($settings, $settings['write']);
        }

        if (! in_array($settings['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('The remote database must use MySQL or MariaDB.');
        }

        $database = $settings['database'] ?? '';

        if (! is_string($database) || $database === '' || $database[0] === '-'
            || preg_match('/[\x00-\x1f\x7f]/', $database)) {
            throw new RuntimeException('The remote connection must specify a valid database name.');
        }

        if (isset($settings['host']) && ! is_string($settings['host'])) {
            throw new RuntimeException('The remote connection must specify a single database host.');
        }

        return $settings;
    }

    protected function credentialsDirectory(): string
    {
        return sys_get_temp_dir();
    }

    protected function credentialsFile(array $settings): string
    {
        $path = tempnam($this->credentialsDirectory(), '.dbreplicator-');

        if ($path === false) {
            throw new RuntimeException('Cannot create remote MySQL credentials file.');
        }

        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('Cannot protect remote MySQL credentials.');
            }

            $options = ['password' => $settings['password'] ?? ''];

            foreach (['SSL_CA' => 'ssl-ca', 'SSL_CERT' => 'ssl-cert', 'SSL_KEY' => 'ssl-key'] as $attribute => $option) {
                $modern = 'Pdo\\Mysql::ATTR_' . $attribute;
                $legacy = 'PDO::MYSQL_ATTR_' . $attribute;
                $constant = defined($modern) ? $modern : $legacy;

                if (defined($constant) && isset($settings['options'][constant($constant)])) {
                    $options[$option] = $settings['options'][constant($constant)];
                }
            }

            $contents = "[client]\n";

            foreach ($options as $key => $value) {
                $escaped = strtr((string) $value, [
                    '\\' => '\\\\', '"' => '\\"', "\n" => '\n', "\r" => '\r', "\t" => '\t',
                ]);
                $contents .= $key . '="' . $escaped . '"' . "\n";
            }

            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('Cannot write remote MySQL credentials.');
            }

            return $path;
        } catch (\Throwable $exception) {
            unlink($path);

            throw $exception;
        }
    }

    protected function streamDump(array $command): int
    {
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR,
        ], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start the remote mysqldump process.');
        }

        return proc_close($process);
    }
}
