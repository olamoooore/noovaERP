<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SearchPathGuard
{
    /**
     * Set the database search path with audit logging and authorization.
     *
     *
     * @throws \RuntimeException
     */
    public static function setSearchPath(string $schemaName, string $reason): void
    {
        if (! Auth::check() || ! Auth::user()->isSuperAdmin()) {
            Log::critical('UNAUTHORIZED_SEARCH_PATH_CHANGE_ATTEMPT', [
                'schema' => $schemaName,
                'user_id' => Auth::id(),
                'reason' => $reason,
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
            ]);
            throw new \RuntimeException('Unauthorized search_path change attempt.');
        }

        Log::warning('SEARCH_PATH_OVERRIDE', [
            'schema' => $schemaName,
            'user_id' => Auth::id(),
            'reason' => $reason,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ]);

        // Sanitize schema name to prevent SQL injection
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $schemaName)) {
            throw new \InvalidArgumentException('Invalid schema name format.');
        }

        DB::statement("SET search_path TO \"{$schemaName}\"");
    }

    /**
     * Reset the database search path to the default.
     */
    public static function resetSearchPath(): void
    {
        DB::statement('SET search_path TO public');
    }
}
