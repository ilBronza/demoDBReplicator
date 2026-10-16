<?php

namespace IlBronza\DemoDBReplicator;

use RuntimeException;

class ImportConfiguration
{
    public function all(): array
    {
        return config('dbreplicator.imports', []);
    }

    public function get(string $name): array
    {
        $profile = $this->all()[$name] ?? null;

        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $name) || ! is_array($profile)) {
            throw new RuntimeException('Unknown database import configuration.');
        }

        foreach (['url', 'token', 'destination'] as $key) {
            if (! is_string($profile[$key] ?? null) || $profile[$key] === '') {
                throw new RuntimeException("Configure [{$key}] for database import [{$name}].");
            }
        }

        $url = parse_url($profile['url']);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new RuntimeException('The export API URL must use HTTPS, without credentials, query or fragment.');
        }

        if (strlen($profile['token']) < 32) {
            throw new RuntimeException('The export API token must contain at least 32 characters.');
        }

        return $profile;
    }

    public function assertDestination(array $profile, array $connection): void
    {
        $guard = $profile['destinationGuard'] ?? [];
        $keys = empty($connection['unix_socket']) ? ['database', 'host', 'port'] : ['database', 'unix_socket'];

        foreach ($keys as $key) {
            if (! isset($guard[$key]) || (string) $guard[$key] === ''
                || (string) $guard[$key] !== (string) ($connection[$key] ?? ($key === 'port' ? 3306 : ''))) {
                throw new RuntimeException("The destination does not match destinationGuard.{$key}; import refused.");
            }
        }
    }
}
