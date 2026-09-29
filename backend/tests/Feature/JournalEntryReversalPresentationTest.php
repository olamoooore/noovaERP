<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Journal-entry reversal presentation (mirrors the customer-payment flow):
 *
 *   1. the ORIGINAL entry is no longer listed as posted once reversed
 *      (status enum has no 'reversed'; lifecycle_status is the flag),
 *   2. the reversal entry references the entry number it cancels
 *      ("Reversal: JE-…") and says so in its description,
 *   3. the Posted tab excludes both the reversed original and the reversal.
 */
class JournalEntryReversalPresentationTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected ChartOfAccount $accountDebit;

    protected ChartOfAccount $accountCredit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'JE Reversal Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $suffix = Str::lower(Str::random(6));
        $this->accountDebit = ChartOfAccount::factory()->create([
            'code' => "5100-{$suffix}",
            'name' => 'Reversal Test Expense',
            'type' => 'expense',
            'is_active' => true,
        ]);
        $this->accountCredit = ChartOfAccount::factory()->create([
            'code' => "2100-{$suffix}",
            'name' => 'Reversal Test Payable',
            'type' => 'liability',
            'is_active' => true,
        ]);
    }

    public function test_reversal_references_the_original_entry_and_hides_it_from_posted(): void
    {
        $create = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/accounting/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'To be reversed in the presentation test',
            'lines' => [
                ['account_id' => $this->accountDebit->id, 'debit' => 250.00, 'credit' => 0],
                ['account_id' => $this->accountCredit->id, 'debit' => 0, 'credit' => 250.00],
            ],
        ]);
        $create->assertCreated();

        $entryId = (int) ($create->json('entry.id') ?? $create->json('id'));
        $originalNumber = JournalEntry::findOrFail($entryId)->entry_number;

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/accounting/journal-entries/{$entryId}/post")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/accounting/journal-entries/{$entryId}/reverse", [
                'reversal_date' => now()->toDateString(),
                'reason' => 'Presentation test reversal',
            ])
            ->assertOk();

        $original = JournalEntry::findOrFail($entryId);
        $this->assertSame('reversed', $original->lifecycle_status, 'The original must be flagged reversed.');
        $this->assertFalse((bool) $original->is_active_version, 'The original must leave the active version set.');

        $reversal = JournalEntry::where('reversed_entry_id', $entryId)->where('is_reversal', true)->firstOrFail();
        $this->assertSame(
            'Reversal: '.$originalNumber,
            $reversal->reference,
            'The reversal must reference the entry number it cancels (same convention as payment reversals).',
        );
        $this->assertStringContainsString('of '.$originalNumber, (string) $reversal->description);

        // Posted tab: neither the reversed original nor the reversal row.
        $posted = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/accounting/journal-entries?status=posted&per_page=100')
            ->assertOk();
        $postedIds = collect($posted->json('data'))->pluck('id')->all();
        $this->assertNotContains($entryId, $postedIds, 'Reversed originals must not appear under Posted.');
        $this->assertNotContains($reversal->id, $postedIds, 'Reversal rows belong to the Reversed tab.');

        // Reversed tab: the reversal row is present.
        $reversedTab = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/accounting/journal-entries?is_reversal=1&per_page=100')
            ->assertOk();
        $reversedIds = collect($reversedTab->json('data'))->pluck('id')->all();
        $this->assertContains($reversal->id, $reversedIds);
    }
}