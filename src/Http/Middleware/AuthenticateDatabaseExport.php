<?php

namespace IlBronza\DemoDBReplicator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDatabaseExport
{
    public function authorize(Request $request, string $database): array
    {
        abort_unless(app()->environment('production') && config('app.env') === 'production'
            && config('dbreplicator.export.enabled') === true, 404);
        abort_unless($request->isSecure(), 403);

        $source = config('dbreplicator.export.databases', [])[$database] ?? [];
        $token = $source['token'] ?? '';
        $provided = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32 && is_string($provided)
            && hash_equals($token, $provided), 401);
        abort_unless(is_string($source['connection'] ?? null) && $source['connection'] !== '', 404);

        return $source;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->authorize($request, (string) $request->route('database'));

        return $next($request);
    }
}
