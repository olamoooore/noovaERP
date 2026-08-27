<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureEntityAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Pillar 2 Part 1 §2.1 / Pillar 1 P0-5 / P0-13:
 *
 * `EnsureEntityAccess` historically failed OPEN when no entity
 * context was set (line 24: `if (! $entityId || ! $user) { return
 * $next($request); }`). The report calls this out as a real
 * entity-isolation gap: in a multi-entity tenant, a user who has
 * access to Entity A but not Entity B could see Entity B's data
 * simply by not sending an `X-Entity-Id` header, because the
 * middleware lets the request through unfiltered.
 *
 * This work item adds an opt-in `?strict=true` mode that fails
 * closed when the entity context is missing. Existing routes stay
 * fails-open (backward compat); routes that opt into `strict` get
 * the safe default.
 */
class IssueP22EnsureEntityAccessStrictModeTest extends TestCase
{
    /**
     * Default (no strict argument): backward-compat fails-open.
     */
    public function test_default_mode_fails_open_when_no_entity_context(): void
    {
        $middleware = app(EnsureEntityAccess::class);

        $request = Request::create('/api/v1/whatever', 'GET');
        // No legal_entity_id attribute, no user.

        $ran = false;
        $response = $middleware->handle($request, function () use (&$ran) {
            $ran = true;
            return response('next', 200);
        });

        $this->assertTrue($ran, 'Default mode must invoke next() (fails open)');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_default_mode_fails_open_when_user_present_but_no_entity(): void
    {
        $middleware = app(EnsureEntityAccess::class);

        $request = Request::create('/api/v1/whatever', 'GET');
        $user = new \App\Models\User;
        $user->id = 123;
        $request->setUserResolver(fn () => $user);
        // No legal_entity_id attribute.

        $ran = false;
        $response = $middleware->handle($request, function () use (&$ran) {
            $ran = true;
            return response('next', 200);
        });

        $this->assertTrue($ran, 'Default mode must invoke next() even with a user (fails open)');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Strict mode: fails CLOSED when no entity context.
     */
    public function test_strict_mode_blocks_request_without_entity_context(): void
    {
        $middleware = app(EnsureEntityAccess::class);

        $request = Request::create('/api/v1/whatever', 'GET');
        $user = new \App\Models\User;
        $user->id = 123;
        $request->setUserResolver(fn () => $user);

        $ran = false;
        $response = $middleware->handle($request, function () use (&$ran) {
            $ran = true;
            return response('next', 200);
        }, 'viewer', 'strict');

        $this->assertFalse($ran, 'Strict mode must NOT invoke next() when no entity is resolved');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            EnsureEntityAccess::REASON_MISSING_CONTEXT,
            json_decode($response->getContent(), true)['reason_code'] ?? null
        );
    }

    public function test_strict_mode_audits_the_block(): void
    {
        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            $captured[] = $message;
        });

        $middleware = app(EnsureEntityAccess::class);
        $request = Request::create('/api/v1/whatever', 'GET');

        $middleware->handle($request, fn () => response('x', 200), 'admin', 'strict');

        $matching = array_filter($captured, fn ($m) => str_contains($m->message, 'EnsureEntityAccess'));
        $this->assertNotEmpty($matching, 'strict-mode block must emit a log entry');

        $entry = collect($matching)->first();
        $this->assertSame(EnsureEntityAccess::REASON_MISSING_CONTEXT, $entry->context['reason']);
        $this->assertSame('admin', $entry->context['minimum_role']);
    }

    /**
     * Strict mode accepts "true", "1", "yes", "on" (Laravel string
     * middleware syntax). Negatives accept "false", "0", etc.
     */
    public function test_strict_mode_accepts_laravel_string_middleware_syntax(): void
    {
        $middleware = app(EnsureEntityAccess::class);

        foreach (['strict', '1', 'true', 'yes', 'on', 'STRICT'] as $truthy) {
            $request = Request::create('/api/v1/x', 'GET');
            $response = $middleware->handle($request, fn () => response('x', 200), 'viewer', $truthy);
            $this->assertSame(
                403,
                $response->getStatusCode(),
                "Expected strict behaviour for input '{$truthy}'"
            );
        }

        foreach (['false', '0', 'no', 'off', ''] as $falsy) {
            $request = Request::create('/api/v1/x', 'GET');
            $ran = false;
            $response = $middleware->handle($request, function () use (&$ran) {
                $ran = true;
                return response('x', 200);
            }, 'viewer', $falsy);
            $this->assertTrue($ran, "Expected fails-open for input '{$falsy}'");
            $this->assertSame(200, $response->getStatusCode());
        }
    }

    public function test_strict_mode_still_blocks_when_user_missing(): void
    {
        $middleware = app(EnsureEntityAccess::class);
        $request = Request::create('/api/v1/x', 'GET');
        $response = $middleware->handle($request, fn () => response('x', 200), 'viewer', 'strict');
        $this->assertSame(403, $response->getStatusCode());
    }
}
