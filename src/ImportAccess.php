<?php

namespace IlBronza\DemoDBReplicator;

use Illuminate\Http\Request;
use RuntimeException;

class ImportAccess
{
    public static function environmentAllowed(): bool
    {
        return app()->environment(['local', 'staging'])
            && in_array(config('app.env'), ['local', 'staging'], true);
    }

    public static function assertEnvironment(): void
    {
        if (! static::environmentAllowed()) {
            throw new RuntimeException('Database imports require APP_ENV=local or APP_ENV=staging.');
        }
    }

    public static function authorize(Request $request): void
    {
        abort_unless(static::environmentAllowed(), 403);
        $user = $request->user();
        abort_unless($user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['superadmin']), 403);
    }
}
