<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #912 [SALES-PLAT] P1: the audit rows #895 writes for Sales documents
 * are append-only per model, but nothing ties them together — a row can be
 * edited or deleted directly in the database and the trail still "looks"
 * intact. This suite drives the real HTTP routes that write the audit rows
 * and asserts the tamper-evident properties:
 *
 *  - every audit entry carries the hash of the previous entry for the tenant,
 *  - the chain verifies end to end through the `audit:verify-chain` command,
 *  - editing or deleting a stored row is detected and raised on the platform
 *    exception queue (#902's ExceptionService — the existing alerting path),
 *  - `audit:anchor` stores a signed digest of the chain head,
 *  - legacy rows with null hashes are skipped (documented behaviour) so the
 *    upgrade in place does not strand existing audit history.
 */
class Issue912SalesAuditChainTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'Issue 912 Audit Chain Tenant',
                'email' => 'audit912-'.substr(md5($this->tenantId), 0, 8).'@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContextWithPermissions(
            $this->tenantId,
            null,
            UserRole::ADMIN->spatieName(),
            autoAssignRole: false,
        );
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => UserRole::ADMIN->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole(UserRole::ADMIN->spatieName());

        return $user;
    }

    private function createPostedInvoice(User $admin): Invoice
    {
        $customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 100000]);
        $product = Product::factory()->create([
            'name' => 'Chained Widget',
            'price' => 500,
            'track_inventory' => false,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 2,
                'unit_price' => 500,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        $invoice = Invoice::query()->findOrFail($response->json('id'));

        $this->actingAs($admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/send")
            ->assertOk();

        return $invoice->fresh();
    }

    /**
     * Every hashed audit entry across the two #895 stores, in chain order.
     *
     * @return list<array{source: string, row: object}>
     */
    private function chainedEntries(): array
    {
        $entries = [];

        foreach (DB::table('platform_system_logs')->whereNotNull('entry_hash')->get() as $row) {
            $entries[] = ['source' => 'platform_system_logs', 'row' => $row];
        }

        foreach (DB::table('audit_logs')->whereNotNull('entry_hash')->get() as $row) {
            $entries[] = ['source' => 'audit_logs', 'row' => $row];
        }

        usort($entries, fn (array $a, array $b): int => (int) $a['row']->chain_sequence <=> (int) $b['row']->chain_sequence);

        return $entries;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function expectedAuditLogHash(object $row): string
    {
        $payload = [
            'before_state' => $row->before_state !== null ? json_decode($row->before_state, true) : null,
            'after_state' => $row->after_state !== null ? json_decode($row->after_state, true) : null,
            'metadata' => $row->metadata !== null ? json_decode($row->metadata, true) : null,
            'source_route' => $row->source_route,
            'idempotency_key' => $row->idempotency_key,
            'terminal_id' => $row->terminal_id,
            'session_id' => $row->session_id,
            'ip_address' => $row->ip_address,
            'user_agent' => $row->user_agent,
        ];

        return hash('sha256', $this->canonicalJson([
            'tenant' => $this->tenantId,
            'previous_hash' => (string) ($row->previous_hash ?? ''),
            'actor' => (string) ($row->actor_id ?? ''),
            'operation' => $row->operation,
            'subject' => $row->reference_type.'#'.$row->reference_id,
            'payload' => $payload,
            'created_at' => Carbon::parse($row->created_at)->format('Y-m-d H:i:s'),
        ]));
    }

    public function test_sales_invoice_route_writes_hash_chained_audit_entries(): void
    {
        foreach (['audit_logs', 'platform_system_logs'] as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'previous_hash'),
                "The {$table} audit store must carry previous_hash (#912)."
            );
            $this->assertTrue(
                Schema::hasColumn($table, 'entry_hash'),
                "The {$table} audit store must carry entry_hash (#912)."
            );
            $this->assertTrue(
                Schema::hasColumn($table, 'chain_sequence'),
                "The {$table} audit store must carry chain_sequence (#912)."
            );
        }

        $admin = $this->actingAsAdmin();
        $invoice = $this->createPostedInvoice($admin);

        $entries = $this->chainedEntries();

        $this->assertGreaterThanOrEqual(
            2,
            count($entries),
            'Creating and posting an invoice through the API must append hash-chained audit entries.'
        );

        $sources = array_unique(array_column($entries, 'source'));
        $this->assertContains('platform_system_logs', $sources, 'The observer-written audit rows must be chained.');
        $this->assertContains('audit_logs', $sources, 'The explicit lifecycle audit rows must be chained into the same chain.');

        // Contiguous sequence starting at 1, each entry carrying the previous
        // entry's hash (the genesis entry has none).
        foreach ($entries as $index => $entry) {
            $this->assertSame(
                $index + 1,
                (int) $entry['row']->chain_sequence,
                'The chain sequence must be contiguous per tenant.'
            );
            $this->assertNotNull($entry['row']->entry_hash, 'Every chained entry must carry its own hash.');

            if ($index === 0) {
                $this->assertNull($entry['row']->previous_hash, 'The genesis audit entry must not point at a previous hash.');
            } else {
                $this->assertSame(
                    $entries[$index - 1]['row']->entry_hash,
                    $entry['row']->previous_hash,
                    'Each audit entry must carry the hash of the previous entry.'
                );
            }
        }

        // Pin the documented hash formula for an explicit lifecycle row.
        $auditRow = DB::table('audit_logs')
            ->where('operation', 'invoice_posted')
            ->where('reference_id', $invoice->id)
            ->first();

        $this->assertNotNull($auditRow, 'Posting the invoice must write the invoice_posted audit row.');
        $this->assertSame(
            $this->expectedAuditLogHash($auditRow),
            $auditRow->entry_hash,
            'entry_hash must be sha256 over the canonical entry fields.'
        );
    }

    public function test_chain_verification_command_passes_for_an_untampered_chain(): void
    {
        $admin = $this->actingAsAdmin();
        $this->createPostedInvoice($admin);

        $this->artisan('audit:verify-chain', ['--tenant' => $this->tenantId])
            ->expectsOutputToContain('chain verified')
            ->assertExitCode(0);
    }

    public function test_editing_a_stored_audit_row_breaks_verification_and_raises_a_platform_exception(): void
    {
        $admin = $this->actingAsAdmin();
        $invoice = $this->createPostedInvoice($admin);

        $row = DB::table('audit_logs')
            ->where('operation', 'invoice_posted')
            ->where('reference_id', $invoice->id)
            ->first();
        $this->assertNotNull($row);

        // Bypass the model guard the way a direct database edit would.
        DB::table('audit_logs')->where('id', $row->id)->update([
            'after_state' => json_encode(['status' => 'posted', 'total' => 1.00]),
        ]);

        $this->artisan('audit:verify-chain', ['--tenant' => $this->tenantId])
            ->assertExitCode(1);

        $this->assertDatabaseHas('platform_exception_queue', [
            'tenant_id' => $this->tenantId,
            'module' => 'platform',
            'exception_type' => 'audit.chain.broken',
            'severity' => 'critical',
        ]);
    }

    public function test_deleting_a_stored_audit_row_breaks_verification(): void
    {
        $admin = $this->actingAsAdmin();
        $this->createPostedInvoice($admin);

        $entries = $this->chainedEntries();
        $this->assertGreaterThanOrEqual(3, count($entries), 'Need a middle entry to delete.');

        $middle = $entries[1]['row'];

        DB::table($entries[1]['source'])->where('id', $middle->id)->delete();

        $this->artisan('audit:verify-chain', ['--tenant' => $this->tenantId])
            ->assertExitCode(1);
    }

    public function test_anchor_command_stores_a_signed_digest_of_the_chain_head(): void
    {
        $admin = $this->actingAsAdmin();
        $this->createPostedInvoice($admin);

        $this->artisan('audit:anchor', [
            '--tenant' => $this->tenantId,
            '--period' => '2026-10',
        ])->assertExitCode(0);

        $head = DB::table('audit_chain_heads')->where('id', 'head')->first();
        $this->assertNotNull($head, 'The chain head must be tracked per tenant schema.');

        $anchor = DB::table('audit_chain_anchors')->orderByDesc('created_at')->first();
        $this->assertNotNull($anchor, 'audit:anchor must store the signed digest.');
        $this->assertSame('2026-10', $anchor->period);
        $this->assertSame((int) $head->chain_sequence, (int) $anchor->chain_sequence);
        $this->assertSame($head->head_hash, $anchor->head_hash);

        $expected = hash_hmac('sha256', $this->canonicalJson([
            'tenant' => $head->tenant_id,
            'chain_sequence' => (int) $head->chain_sequence,
            'head_hash' => $head->head_hash,
            'period' => '2026-10',
            'anchored_at' => Carbon::parse($anchor->anchored_at)->format('Y-m-d H:i:s'),
        ]), (string) config('app.key'));

        $this->assertSame($expected, $anchor->digest, 'The anchor digest must be an HMAC-SHA256 of the chain head with the app key.');

        // An anchored chain still verifies.
        $this->artisan('audit:verify-chain', ['--tenant' => $this->tenantId])
            ->assertExitCode(0);
    }

    public function test_null_hash_legacy_rows_are_skipped_by_verification(): void
    {
        // Two legacy rows written before the hash columns existed (nullable,
        // default null) must not break verification.
        DB::table('platform_system_logs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'level' => 'info',
            'category' => 'audit.sales.created',
            'message' => 'legacy row',
            'context' => json_encode(['legacy' => true]),
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'operation' => 'legacy_operation',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'before_state' => null,
            'after_state' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $appended = app(\App\Services\AuditLogger::class)->log(
            operation: 'invoice_created',
            referenceType: 'invoice',
            referenceId: 1,
            afterState: ['legacy_probe' => true],
        );

        $this->assertNull($appended->previous_hash, 'Legacy null-hash rows are skipped: the first chained entry is genesis.');
        $this->assertSame(1, (int) $appended->chain_sequence);
        $this->assertNotNull($appended->entry_hash);

        $this->artisan('audit:verify-chain', ['--tenant' => $this->tenantId])
            ->assertExitCode(0);
    }
}
