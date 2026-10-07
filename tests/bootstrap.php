<?php

$root = dirname(__DIR__);
$runtime = __DIR__ . '/.runtime';

// Console tests must not invoke stty in the WebAssembly runtime.
putenv('COLUMNS=120');
putenv('LINES=40');

if (! is_dir($runtime)) {
    mkdir($runtime, 0700, true);
}

foreach (['APP_SERVICES_CACHE' => 'services.php', 'APP_PACKAGES_CACHE' => 'packages.php', 'APP_CONFIG_CACHE' => 'config.php', 'APP_ROUTES_CACHE' => 'routes.php', 'APP_EVENTS_CACHE' => 'events.php'] as $key => $file) {
    $_ENV[$key] = $_SERVER[$key] = $runtime . '/' . $file;
    putenv($key . '=' . $runtime . '/' . $file);
}

if (is_file($root . '/vendor/autoload.php')) {
    $loader = require $root . '/vendor/autoload.php';
} else {
    $loader = require dirname($root) . '/.ibtest/vendor/autoload.php';

    foreach (glob(dirname($root) . '/*/composer.json') as $manifest) {
        $directory = dirname($manifest);
        $composer = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);

        foreach ($composer['autoload']['psr-4'] ?? [] as $namespace => $paths) {
            $loader->addPsr4($namespace, array_map(static function ($path) use ($directory) {
                return $directory . '/' . $path;
            }, (array) $paths), true);
        }
    }
}

$loader->addPsr4('IlBronza\\DemoDBReplicator\\Tests\\', __DIR__, true);
$loader->addPsr4('App\\Http\\Controllers\\', __DIR__ . '/Fixtures/App/Http/Controllers', true);
