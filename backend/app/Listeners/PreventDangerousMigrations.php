<?php

namespace App\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Log;

class PreventDangerousMigrations
{
    /**
     * Blocked commands that can destroy data.
     */
    private const BLOCKED_COMMANDS = [
        'migrate:fresh',
        'db:wipe',
    ];

    /**
     * Handle the event.
     */
    public function handle(CommandFinished $event): void
    {
        $command = $event->command;

        // Check if a dangerous command was run
        foreach (self::BLOCKED_COMMANDS as $blocked) {
            if (str_starts_with($command, $blocked)) {
                $this->logDangerousMigration($command);
                break;
            }
        }
    }

    private function logDangerousMigration(string $command): void
    {
        Log::critical('DANGEROUS MIGRATION EXECUTED', [
            'command' => $command,
            'user' => auth()->user()?->email ?? 'CLI',
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
