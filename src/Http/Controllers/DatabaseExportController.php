<?php

namespace IlBronza\DemoDBReplicator\Http\Controllers;

use App\Http\Controllers\Controller;
use IlBronza\DemoDBReplicator\DatabaseReplicator;
use IlBronza\DemoDBReplicator\Http\Middleware\AuthenticateDatabaseExport;
use IlBronza\DemoDBReplicator\MySqlClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DatabaseExportController extends Controller
{
    public function export(Request $request, string $database, MySqlClient $client,
        DatabaseReplicator $replicator): StreamedResponse
    {
        // Also protect direct controller calls, independently of route middleware.
        $source = (new AuthenticateDatabaseExport)->authorize($request, $database);
        $path = $client->ensureDirectory() . '/api-export-' . bin2hex(random_bytes(16)) . '.sql';

        try {
            $identity = $client->databaseIdentity($source['connection']);
            $replicator->withDestinationLock($source['connection'], function () use ($client, $source, $path) {
                $client->dump($source['connection'], $path);
            });
            clearstatcache(true, $path);
            if (! is_file($path) || ! ($size = filesize($path)) || ! ($checksum = hash_file('sha256', $path))) {
                throw new \RuntimeException('The production dump is missing or empty.');
            }
        } catch (Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            Log::error('Database export failed.', [
                'database' => $database, 'exception_type' => get_class($exception),
            ]);
            // Never return SQL, connection configuration or process output to the client.
            abort(503, 'Database export failed.');
        }

        return response()->streamDownload(function () use ($path) {
            $previous = ignore_user_abort(true);
            $input = null;
            try {
                $input = fopen($path, 'rb');
                if ($input !== false) {
                    fpassthru($input);
                }
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_file($path)) {
                    unlink($path);
                }
                ignore_user_abort((bool) $previous);
            }
        }, 'database.sql', [
            'Content-Type' => 'application/sql',
            'Content-Length' => (string) $size,
            'X-DBReplicator-Size' => (string) $size,
            'X-DBReplicator-SHA256' => $checksum,
            'X-DBReplicator-Source' => $identity,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
