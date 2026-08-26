<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

/**
 * Pillar 2 Part 3 §4.2: IoT agents.api_token is stored in plaintext and
 * compared in plaintext. The plaintext comparison existed in two
 * call sites: AuthenticateAgent.php and AuthenticateAgentOrUser.php.
 * Additionally, AuthenticateAgentOrUser logged the first 20 chars of
 * the bearer token on every request.
 *
 * Fixes:
 *  1. Migration 2026_08_26_000001_add_api_token_hash_to_agents adds
 *     an api_token_hash column and backfills it from existing plaintext.
 *  2. Both middleware files now compare hash('sha256', $token) instead
 *     of plaintext.
 *  3. The token-preview log line was removed.
 *
 * This test pins the fixes at the source level (full HTTP tests require
 * a properly-provisioned IoT agent fixture, which is filed as a
 * follow-up).
 */
class IssueP28IoTAgentTokenHashTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) \Illuminate\Support\Str::uuid());
    }

    public function test_migration_adds_api_token_hash_column(): void
    {
        $migrationPath = __DIR__.'/../../modules/IoT/database/migrations/tenant/2026_08_26_000001_add_api_token_hash_to_agents.php';
        $this->assertFileExists(
            $migrationPath,
            'api_token_hash migration must exist'
        );

        $source = file_get_contents($migrationPath);
        $this->assertStringContainsString(
            'api_token_hash',
            $source,
            'Migration must add api_token_hash column'
        );
        $this->assertStringContainsString(
            'sha256',
            $source,
            'Migration must use SHA-256 for the hash'
        );
    }

    public function test_authenticate_agent_uses_hash_not_plaintext(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/Http/Middleware/AuthenticateAgent.php'
        );

        // The plaintext "where api_token, $token" pattern must be
        // gone — replaced with hash comparison.
        $this->assertStringNotContainsString(
            "->where('api_token',",
            $source,
            'AuthenticateAgent must NOT compare plaintext api_token'
        );
        $this->assertStringContainsString(
            "->where('api_token_hash',",
            $source,
            'AuthenticateAgent must compare api_token_hash'
        );
        $this->assertStringContainsString(
            "hash('sha256'",
            $source,
            'AuthenticateAgent must use SHA-256 for comparison'
        );
    }

    public function test_authenticate_agent_or_user_uses_hash_not_plaintext(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/Http/Middleware/AuthenticateAgentOrUser.php'
        );

        // The plaintext comparison pattern must be gone.
        $this->assertStringNotContainsString(
            "->where('api_token',",
            $source,
            'AuthenticateAgentOrUser must NOT compare plaintext api_token'
        );
        $this->assertStringContainsString(
            "->where('api_token_hash',",
            $source,
            'AuthenticateAgentOrUser must compare api_token_hash'
        );
    }

    public function test_token_preview_log_removed_from_authenticate_agent_or_user(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../app/Http/Middleware/AuthenticateAgentOrUser.php'
        );

        // The actual log call should not include a token_preview key
        // or any substring of the token.
        $this->assertStringNotContainsString(
            "'token_preview'",
            $source,
            'AuthenticateAgentOrUser must NOT log token_preview (partial token material)'
        );
        // substr() is not safe to test as a literal because it appears
        // in a comment block describing the fix. Instead, check that the
        // 'has_token' log entry is a simple boolean, not a substring.
        $this->assertStringContainsString(
            "'has_token' => ! empty(\$token)",
            $source,
            'token preview log was removed; the has_token log should be a simple boolean'
        );
    }

    public function test_hashed_agent_token_authenticates_without_plaintext_column(): void
    {
        if (! Schema::hasTable('agents') || ! Schema::hasColumn('agents', 'api_token_hash')) {
            $this->markTestSkipped('IoT agent migration is not loaded in this test schema.');
        }

        $rawToken = 'agent-test-token-'.bin2hex(random_bytes(16));
        $agentId = 'agent-test-'.bin2hex(random_bytes(8));

        DB::table('agents')->insert([
            'id' => $agentId,
            'name' => 'Hash Test Agent',
            'api_token_hash' => hash('sha256', $rawToken),
            'status' => 'offline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/v1/iot/agents/'.$agentId.'/heartbeat', 'POST');
        $request->headers->set('Authorization', 'Bearer '.$rawToken);

        $response = app(AuthenticateAgent::class)->handle(
            $request,
            fn () => response()->json(['ok' => true])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($agentId, $request->attributes->get('authenticated_agent')->id);
        $this->assertFalse(
            Schema::hasColumn('agents', 'api_token'),
            'The raw agents.api_token column must be removed after hash migration.'
        );
    }
}
