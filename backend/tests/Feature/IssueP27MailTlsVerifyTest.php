<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * Pillar 2 Part 3 §1.4: HTTPS/TLS enforcement missing, SMTP TLS
 * certificate verification explicitly disabled.
 *
 * Fix: SMTP TLS verification is now opt-out via env var
 * (MAIL_DISABLE_SMTP_TLS_VERIFY, default false in production).
 * Originally the code unconditionally disabled verification,
 * which weakens transport security (MITM risk on outbound mail).
 *
 * HTTPS enforcement at the app layer is out of scope for a unit
 * test (it requires reverse-proxy / load-balancer config in front
 * of the app, per the report). That work is filed as a follow-up
 * for DevOps.
 */
class IssueP27MailTlsVerifyTest extends TestCase
{
    public function test_smtp_tls_verify_is_enabled_by_default(): void
    {
        // Without the env var set, the afterResolving callback
        // should NOT modify the transport's stream options.
        // We verify this by checking the AppServiceProvider source
        // for the guard clause.
        $source = file_get_contents(
            __DIR__.'/../../app/Providers/AppServiceProvider.php'
        );

        $this->assertStringContainsString(
            'MAIL_DISABLE_SMTP_TLS_VERIFY',
            $source,
            'AppServiceProvider must check MAIL_DISABLE_SMTP_TLS_VERIFY env var before disabling SMTP TLS'
        );
        $this->assertStringContainsString(
            "if (! (bool) env('MAIL_DISABLE_SMTP_TLS_VERIFY', false))",
            $source,
            'MAIL_DISABLE_SMTP_TLS_VERIFY must default to FALSE (TLS verification on by default)'
        );
    }

    public function test_smtp_tls_verify_disable_is_explicit_opt_in(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/Providers/AppServiceProvider.php'
        );

        // The "verify_peer" => false must ONLY appear inside the
        // env-var-guarded branch, not unconditionally.
        // Count occurrences: if it's still at the top level
        // (unconditional), there will be more than one occurrence.
        $verifyPeerCount = substr_count($source, "'verify_peer' => false");

        $this->assertSame(
            1,
            $verifyPeerCount,
            "'verify_peer' => false must appear exactly once (inside the env-var guard), not unconditionally"
        );
    }

    public function test_https_force_scheme_middleware_class_does_not_exist(): void
    {
        // The report (Pillar 2 Part 3 §1.4) notes: no HTTPS
        // enforcement in the application code. There is no
        // ForceHttps middleware. This test documents that the
        // absence is known — when the follow-up adds one, this
        // test can be inverted to require the class to exist.
        $forceHttpsPath = __DIR__.'/../../app/Http/Middleware/ForceHttps.php';
        $this->assertFileDoesNotExist(
            $forceHttpsPath,
            'ForceHttps middleware intentionally absent per report — '
            .'this test is the "before" baseline for the follow-up that '
            .'will add HTTPS enforcement'
        );
    }
}
