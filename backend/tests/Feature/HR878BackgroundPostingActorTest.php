<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #878 (SA4) — background postings were attributed to the lowest-ID
 * user because TenantUserHelper::resolveTenantUserId() fell back to
 * DB::table('users')->orderBy('id')->value('id').
 *
 * Contract asserted here:
 *  - a queued/scheduled/webhook posting (no authenticated user) is recorded
 *    against the tenant's dedicated SYSTEM actor, with the originating
 *    trigger (job/schedule/webhook id) recorded on the entry and the audit
 *    log — never user #1;
 *  - an interactive posting whose authenticated human cannot be resolved to
 *    a tenant user fails closed instead of borrowing another user's identity;
 *  - a resolvable authenticated human is still recorded as themselves.
 */
class HR878BackgroundPostingActorTest extends TenantTestCase
{
    private User $lowestUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());

        $this->lowestUser = User::factory()->create([
            'name' => 'Owner (lowest id)',
            'email' => 'owner-878@example.test',
        ]);

        // Fixture sanity: the owner really is the lowest-ID row — the exact
        // user the old fallback would have picked for every background post.
        $this->assertSame(
            (int) $this->lowestUser->id,
            (int) DB::table('users')->min('id'),
            'fixture must provide a lowest-ID user',
        );

        Auth::logout();
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function balancedRequest(?int $userId = null, array $metadata = []): PostingRequest
    {
        $debit = ChartOfAccount::factory()->create(['type' => 'expense', 'is_active' => true]);
        $credit = ChartOfAccount::factory()->create(['type' => 'liability', 'is_active' => true]);

        return new PostingRequest(
            documentType: 'fx_revaluation',
            documentId: null,
            postingDate: now()->addDays(30)->toDateString(),
            description: 'HR878 queued posting',
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'expense',
                    accountId: $debit->id,
                    debit: 100.00,
                    credit: 0.00,
                    description: 'Queued debit line',
                ),
                new PostingRequestLine(
                    accountMappingKey: 'liability',
                    accountId: $credit->id,
                    debit: 0.00,
                    credit: 100.00,
                    description: 'Queued credit line',
                ),
            ],
            userId: $userId,
            requiredPermission: 'accounting.write',
            metadata: $metadata,
        );
    }

    public function test_queued_posting_records_the_system_actor_and_its_trigger_never_user_one(): void
    {
        $this->assertTrue(Auth::guest(), 'a queued posting runs without an authenticated user');

        $result = app(PostingEngineInterface::class)->post(
            $this->balancedRequest(metadata: ['trigger' => 'job:GenerateRecurringSalesOrders'])
        );

        $this->assertTrue($result->success, "queued posting failed: {$result->error}");

        $entry = JournalEntry::findOrFail($result->journalEntryId);

        // RED on main: this is user #1 (the lowest-ID fallback).
        $this->assertNotSame(
            (int) $this->lowestUser->id,
            (int) $entry->posted_by,
            'a queued posting must never be attributed to the lowest-ID user',
        );

        $systemActorId = (int) DB::table('users')->where('is_system', true)->value('id');

        $this->assertGreaterThan(0, $systemActorId, 'a dedicated system actor must exist for the tenant');
        $this->assertSame($systemActorId, (int) $entry->posted_by, 'posted_by must be the system actor');
        $this->assertSame($systemActorId, (int) $entry->created_by, 'created_by must be the system actor');

        $this->assertSame('system', $entry->origin_type);
        $this->assertSame(
            'job:GenerateRecurringSalesOrders',
            $entry->origin_context['trigger'] ?? null,
            'the originating trigger must be recorded on the entry',
        );
        $this->assertSame('job', $entry->origin_context['trigger_type'] ?? null);

        $audit = DB::table('posting_audit_logs')->where('journal_entry_id', $entry->id)->first();
        $this->assertNotNull($audit, 'the posting audit log must exist');
        $this->assertSame($systemActorId, (int) $audit->actor_user_id, 'the audit log must name the system actor');

        $payload = json_decode((string) $audit->payload_snapshot, true);
        $this->assertSame('SYSTEM', $payload['actor_type'] ?? null);
        $this->assertSame('job:GenerateRecurringSalesOrders', $payload['posting_trigger'] ?? null);
    }

    public function test_interactive_posting_with_an_unresolvable_user_fails_closed_not_user_one(): void
    {
        // The authenticated human has no matching tenant user row (stale or
        // unsynced) and no preferred user is supplied.
        $ghost = new User(['name' => 'Ghost', 'email' => 'ghost-878@example.test']);
        $ghost->id = $this->lowestUser->id + 1000;
        Auth::login($ghost);

        $result = app(PostingEngineInterface::class)->post($this->balancedRequest());

        $this->assertFalse(
            $result->success,
            'an interactive posting with no resolvable tenant user must fail closed, not borrow the lowest-ID user',
        );
    }

    public function test_interactive_posting_records_the_real_authenticated_user(): void
    {
        $poster = User::factory()->create([
            'name' => 'Poster',
            'email' => 'poster-878@example.test',
        ]);

        $this->actingAs($poster);

        $result = app(PostingEngineInterface::class)->post($this->balancedRequest());

        $this->assertTrue($result->success, "interactive posting failed: {$result->error}");

        $entry = JournalEntry::findOrFail($result->journalEntryId);

        $this->assertSame((int) $poster->id, (int) $entry->posted_by, 'the real authenticated user must be recorded');
        $this->assertSame((int) $poster->id, (int) $entry->created_by);
        $this->assertNotSame('system', $entry->origin_type);
        $this->assertNull(
            DB::table('users')->where('is_system', true)->value('id'),
            'an interactive posting must not mint a system actor',
        );
    }

    public function test_system_actor_is_created_once_per_tenant(): void
    {
        $first = \App\Support\TenantUserHelper::systemActorId();
        $second = \App\Support\TenantUserHelper::systemActorId();

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('users')->where('is_system', true)->count());
        $this->assertTrue(\App\Support\TenantUserHelper::isSystemActor($first));
        $this->assertFalse(\App\Support\TenantUserHelper::isSystemActor($this->lowestUser->id));
    }
}
