<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #89 — backend/.env.bak with live-looking secrets was committed and
 * churned. It has since been untracked + ignored; this test is the regression
 * guard so an env backup (or a template carrying real secrets) can never be
 * re-committed silently.
 *
 * The history purge + key rotation remain ops actions; the in-repo contract is
 * enforced here.
 */
class Issue89SecretsNotTrackedTest extends TestCase
{
    /** Env files that are intentionally tracked (templates only). */
    private const ALLOWED_ENV_FILES = [
        'backend/.env.example',
        'backend/.env.production',
        'frontend/.env.example',
        'frontend/.env.production',
    ];

    private function trackedFiles(): array
    {
        $output = [];
        $exitCode = 0;

        exec('git -C '.escapeshellarg(base_path()) .' ls-files --full-name', $output, $exitCode);

        $this->assertSame(0, $exitCode, 'git ls-files must succeed');

        return $output;
    }

    public function test_no_env_backup_or_local_env_files_are_tracked(): void
    {
        $trackedEnvFiles = array_values(array_filter(
            $this->trackedFiles(),
            fn (string $path) => preg_match('#(^|/)\.env(\.|$)#', $path) === 1
        ));

        $unexpected = array_values(array_diff($trackedEnvFiles, self::ALLOWED_ENV_FILES));

        $this->assertSame(
            [],
            $unexpected,
            'Only the env templates may be tracked. Found: '.implode(', ', $unexpected)
        );
    }

    public function test_tracked_env_templates_do_not_contain_live_secrets(): void
    {
        $secretKeys = [
            'APP_KEY',
            'DB_PASSWORD',
            'REDIS_PASSWORD',
            'MAIL_PASSWORD',
            'AWS_SECRET_ACCESS_KEY',
            'STRIPE_SECRET',
            'STRIPE_KEY',
        ];

        foreach (['backend/.env.production', 'frontend/.env.production'] as $relativePath) {
            $absolute = base_path('../'.$relativePath);

            if (! is_file($absolute)) {
                continue;
            }

            foreach (file($absolute, FILE_IGNORE_NEW_LINES) as $line) {
                if (! str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = array_map('trim', explode('=', $line, 2));

                if (! in_array($key, $secretKeys, true) || $value === '') {
                    continue;
                }

                $looksLikePlaceholder = str_starts_with(strtolower($value), 'your_')
                    || str_starts_with(strtolower($value), 'changeme')
                    || strtolower($value) === 'null'
                    || str_starts_with($value, 'base64:CHANGE');

                $this->assertTrue(
                    $looksLikePlaceholder,
                    "{$relativePath} contains a non-placeholder value for {$key}; env templates must never carry live secrets."
                );
            }
        }
    }
}
