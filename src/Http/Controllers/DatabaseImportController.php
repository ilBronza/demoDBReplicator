<?php

namespace IlBronza\DemoDBReplicator\Http\Controllers;

use App\Http\Controllers\Controller;
use IlBronza\DemoDBReplicator\ApiDatabaseImporter;
use IlBronza\DemoDBReplicator\ImportAccess;
use IlBronza\DemoDBReplicator\ImportConfiguration;
use IlBronza\DemoDBReplicator\MySqlClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class DatabaseImportController extends Controller
{
    protected function profile(Request $request, string $database, ImportConfiguration $configuration): array
    {
        ImportAccess::authorize($request);
        abort_unless(array_key_exists($database, $configuration->all()), 404);

        return $configuration->get($database);
    }

    public function show(Request $request, string $database, ImportConfiguration $configuration, MySqlClient $client)
    {
        $profile = $this->profile($request, $database, $configuration);
        $settings = $client->connectionConfig($profile['destination']);
        $configuration->assertDestination($profile, $settings);

        return response()->view('dbReplicator::import', [
            'database' => $database,
            'label' => $profile['label'] ?? $database,
            'destination' => $settings['database'],
            'host' => ($settings['unix_socket'] ?? '') ?: ($settings['host'] ?? '127.0.0.1'),
            'sourceHost' => parse_url($profile['url'], PHP_URL_HOST),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function import(Request $request, string $database, ImportConfiguration $configuration,
        ApiDatabaseImporter $importer)
    {
        $profile = $this->profile($request, $database, $configuration);
        abort_unless($request->input('confirm') === $database, 422);
        abort_if(config('session.driver') === 'database', 422,
            'Use file or Redis sessions before importing a database from the web.');

        try {
            // Finish replacement even if the administrator closes the browser.
            $previous = ignore_user_abort(true);
            $backup = $importer->import($database);

            return response()->view('dbReplicator::result', [
                'label' => $profile['label'] ?? $database, 'backup' => $backup, 'success' => true,
            ])->header('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            // Keep database/process details out of browser responses and session storage.
            Log::error('Database import request failed.', [
                'profile' => $database, 'exception_type' => get_class($exception),
            ]);
            return response()->view('dbReplicator::result', [
                'label' => $profile['label'] ?? $database, 'success' => false,
            ], 500)->header('Cache-Control', 'private, no-store');
        } finally {
            ignore_user_abort((bool) $previous);
            // A partial import can also replace users and invalidate their old identities.
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }
    }
}
