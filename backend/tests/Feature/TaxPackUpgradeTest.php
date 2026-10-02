<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Tax;
use App\Models\TaxDeterminationResult;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #739 T6: country packs are versioned. A corrected rate or end date in
 * config/tax_packs.php must be VISIBLE (status reports new / changed with a
 * field-level diff / tenant-modified / removed-from-pack) and APPLICABLE
 * through an explicit upgrade that never rewrites a rule a posted document
 * already used and never silently overwrites a tenant edit.
 *
 * The pack mutations in this file are TEST-ONLY config changes: they prove the
 * versioning mechanism, and no value here ships in config/tax_packs.php.
 */
class TaxPackUpgradeTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@packfix.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── pack mutation helpers (test-only) ─────────────────────────────

    private function setPackRule(string $jurisdiction, string $code, array $overrides): void
    {
        $rules = config("tax_packs.{$jurisdiction}.rules");

        foreach ($rules as $i => $rule) {
            if ($rule['code'] === $code) {
                $rules[$i] = array_merge($rule, $overrides);
                config(["tax_packs.{$jurisdiction}.rules" => $rules]);

                return;
            }
        }

        $this->fail("Pack rule {$code} not found in {$jurisdiction}.");
    }

    private function removePackRule(string $jurisdiction, string $code): void
    {
        $rules = array_values(array_filter(
            config("tax_packs.{$jurisdiction}.rules"),
            fn (array $rule) => $rule['code'] !== $code,
        ));
        config(["tax_packs.{$jurisdiction}.rules" => $rules]);
    }

    private function activateSg(): void
    {
        $this->postJson('/api/v1/accounting/tax-engine/packs/SG/activate')->assertOk();
    }

    private function packStatus(string $jurisdiction = 'SG'): array
    {
        $response = $this->getJson('/api/v1/accounting/tax-engine/packs')->assertOk();

        return (array) $response->json("data.{$jurisdiction}");
    }

    /**
     * A determination that actually resolved a pack rule, so the persisted
     * tax_rule_refs carry that rule id and the version it resolved at. HTTP
     * invoice lines carry no rule identity (line tax_rate only), so the rule
     * reference chain is produced through the determination service itself —
     * the same shape SgServiceTaxResolutionTest uses.
     *
     * @return array<int, array<string, mixed>>
     */
    private function determinationReferencing(int $ruleId, string $date = '2025-08-15'): array
    {
        $customer = Customer::factory()->create();
        DB::table('customer_addresses')->insert([
            'customer_id' => $customer->id, 'type' => 'billing', 'country' => 'SG',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::factory()->create([
            'name' => 'Rule-bound consulting', 'type' => 'service', 'track_inventory' => false,
            'price' => 500, 'tax_id' => $ruleId,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'PACK-'.Str::random(8),
            'invoice_date' => $date,
            'due_date' => '2025-09-15',
            'status' => 'draft',
            'subtotal' => 500.0,
            'tax_amount' => 0.0,
            'total' => 500.0,
            'created_by' => $this->user->id,
        ]);

        $line = new InvoiceLine([
            'product_id' => $product->id,
            'description' => 'Rule-bound consulting',
            'quantity' => 1,
            'unit_price' => 500.0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);
        $line->setRelation('product', $product);
        $invoice->setRelation('lines', collect([$line]));

        app(\App\Services\TaxDeterminationService::class)->determineForInvoice($invoice);

        $result = TaxDeterminationResult::query()
            ->where('source_record_type', 'invoice')->where('source_record_id', $invoice->id)
            ->orderByDesc('result_version')->firstOrFail();

        return (array) $result->tax_rule_refs;
    }

    /**
     * A real POSTED document that charged the pack rule's rate (HTTP path).
     */
    private function postInvoiceChargingNinePercent(): int
    {
        $customer = Customer::factory()->create();
        DB::table('customer_addresses')->insert([
            'customer_id' => $customer->id, 'type' => 'billing', 'country' => 'SG',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::factory()->create([
            'name' => 'Plain consulting', 'type' => 'service', 'track_inventory' => false, 'price' => 1000,
        ]);

        $create = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => '2025-08-15',
            'due_date' => '2025-09-15',
            'lines' => [[
                'product_id' => $product->id, 'description' => 'Plain consulting',
                'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 0, 'tax_rate' => 9,
            ]],
        ])->assertCreated();

        $invoiceId = (int) $create->json('id');
        $this->postJson("/api/v1/invoices/{$invoiceId}/send")->assertOk();

        return $invoiceId;
    }

    private function ruleRow(string $code): Tax
    {
        return Tax::withTrashed()->where('code', $code)->orderBy('id')->firstOrFail();
    }

    // ── tests ─────────────────────────────────────────────────────────

    public function test_activation_records_the_pack_version_and_status_reports_no_drift(): void
    {
        $activate = $this->postJson('/api/v1/accounting/tax-engine/packs/SG/activate')
            ->assertOk()
            ->assertJsonPath('data.activated', true);

        $this->assertSame(1, (int) $activate->json('data.version'), 'The pack must carry a version.');
        $this->assertSame(1, (int) $activate->json('data.installed_version'));

        $row = $this->ruleRow('SG-GST-9');
        $this->assertSame(1, (int) $row->pack_version, 'Each tenant rule must record the pack version it came from.');
        $this->assertSame(9.0, (float) data_get($row->pack_snapshot, 'rate'));

        $status = $this->packStatus();
        $this->assertSame(1, (int) $status['version']);
        $this->assertSame(1, (int) $status['installed_version']);
        $this->assertTrue($status['up_to_date']);
        $this->assertSame([], $status['changed']);
        $this->assertSame([], $status['new']);

        foreach ((array) $status['rules'] as $entry) {
            $this->assertSame('ok', $entry['state'], "Rule {$entry['code']} should report no drift.");
        }
    }

    public function test_status_reports_field_level_changes_and_removals(): void
    {
        $this->activateSg();

        // TEST-ONLY pack edit: a correction to the end date, and a rule that
        // the pack no longer ships.
        $this->setPackRule('SG', 'SG-GST-9', ['effective_to' => '2025-12-31']);
        $this->removePackRule('SG', 'SG-GST-7');

        $status = $this->packStatus();
        $this->assertFalse($status['up_to_date'], 'A changed pack rule must not report up_to_date.');

        $this->assertSame(['SG-GST-9'], $status['changed']);
        $byCode = collect((array) $status['rules'])->keyBy('code');
        $this->assertSame('changed', $byCode['SG-GST-9']['state']);
        $this->assertSame(
            ['from' => null, 'to' => '2025-12-31'],
            $byCode['SG-GST-9']['changes']['effective_to'] ?? null,
            'status() must report a field-level diff, not just a boolean.',
        );

        $this->assertSame(['SG-GST-7'], $status['removed_from_pack'], 'Rules the tenant got from a pack but the pack dropped must be reported.');
    }

    public function test_status_flags_a_tenant_modified_rule_separately_from_pack_drift(): void
    {
        $this->activateSg();

        $this->ruleRow('SG-GST-9')->update(['rate' => 8.25]);

        $status = $this->packStatus();
        $this->assertSame(['SG-GST-9'], $status['tenant_modified']);

        $entry = collect((array) $status['rules'])->firstWhere('code', 'SG-GST-9');
        $this->assertSame('tenant_modified', $entry['state']);
        $this->assertSame(
            ['from' => 9.0, 'to' => 8.25],
            array_map(fn ($v) => (float) $v, $entry['tenant_changes']['rate'] ?? []),
            'A tenant edit must be distinguished from a pack change.',
        );
    }

    public function test_upgrade_end_dates_a_rule_documents_already_used(): void
    {
        $this->activateSg();
        $rule = $this->ruleRow('SG-GST-9');

        // A posted document that charged this rule's rate, plus a persisted
        // determination that resolved the rule itself (id + version).
        $postedInvoiceId = $this->postInvoiceChargingNinePercent();
        $refs = $this->determinationReferencing($rule->id);
        $this->assertSame($rule->id, $refs[0]['tax_rule_id'] ?? null);
        $this->assertSame(1, (int) ($refs[0]['rule_version'] ?? 0));

        // TEST-ONLY pack correction: the open-ended window now ends.
        $this->setPackRule('SG', 'SG-GST-9', ['effective_to' => '2025-12-31']);

        $upgrade = $this->postJson('/api/v1/accounting/tax-engine/packs/SG/upgrade', [
            'apply' => ['SG-GST-9'],
        ])->assertOk();

        $this->assertSame('end_dated', $upgrade->json('data.applied.0.mode'), 'A rule documents already used must be end-dated, not rewritten.');

        $row = $this->ruleRow('SG-GST-9');
        $this->assertSame($rule->id, $row->id, 'The end-dated rule keeps its identity.');
        $this->assertSame('2025-12-31', $row->effective_to?->toDateString());
        $this->assertSame(9.0, (float) $row->rate, 'An end-date correction must not touch the rate documents were posted with.');

        // Documents still reference the rule version they were determined at.
        $this->assertSame($rule->id, $refs[0]['tax_rule_id'] ?? null);
        $this->assertSame(1, (int) ($refs[0]['rule_version'] ?? 0));
        $this->assertSame(90.0, (float) Invoice::findOrFail($postedInvoiceId)->tax_amount, 'A posted document must be untouched by the upgrade.');

        // AC: one audit entry per applied change.
        $audit = AuditLog::query()
            ->where('reference_type', 'tax_rule')
            ->where('reference_id', $rule->id)
            ->where('operation', 'tax_pack_upgrade')
            ->orderByDesc('id')->first();
        $this->assertNotNull($audit, 'Each applied pack change must write an audit entry.');
        $this->assertNull(data_get($audit->before_state, 'effective_to'));
        // Audit states store the model's cast values (ISO datetime for dates).
        $this->assertSame('2025-12-31', \Carbon\Carbon::parse(data_get($audit->after_state, 'effective_to'))->toDateString());
        $this->assertSame('SG', data_get($audit->metadata, 'jurisdiction'));
        $this->assertSame(1, (int) data_get($audit->metadata, 'pack_version'));
    }

    public function test_upgrade_never_silently_overwrites_a_tenant_edited_rule(): void
    {
        $this->activateSg();
        $rule = $this->ruleRow('SG-GST-9');
        $rule->update(['rate' => 8.25]);

        $this->setPackRule('SG', 'SG-GST-9', ['rate' => 9.5]);

        $upgrade = $this->postJson('/api/v1/accounting/tax-engine/packs/SG/upgrade', [
            'apply' => ['SG-GST-9'],
        ])->assertOk();

        $this->assertSame([], $upgrade->json('data.applied'));
        $this->assertSame('tenant_modified', $upgrade->json('data.skipped.0.reason'));

        $row = $this->ruleRow('SG-GST-9');
        $this->assertSame(8.25, (float) $row->rate, 'The tenant edit must survive an upgrade untouched.');
        $this->assertSame(1, Tax::withTrashed()->where('code', 'SG-GST-9')->count(), 'No replacement row may be created for a tenant-edited rule.');
    }

    public function test_upgrade_versions_a_rule_documents_use_when_a_rate_change_needs_a_new_row(): void
    {
        $this->activateSg();
        $rule = $this->ruleRow('SG-GST-9');

        $postedInvoiceId = $this->postInvoiceChargingNinePercent();
        $refs = $this->determinationReferencing($rule->id);
        $this->assertSame($rule->id, $refs[0]['tax_rule_id'] ?? null);
        $this->assertSame(1, (int) ($refs[0]['rule_version'] ?? 0));

        // TEST-ONLY pack correction: a corrected rate for future documents.
        $this->setPackRule('SG', 'SG-GST-9', ['rate' => 9.5]);

        $upgrade = $this->postJson('/api/v1/accounting/tax-engine/packs/SG/upgrade', [
            'apply' => ['SG-GST-9'],
        ])->assertOk();

        $this->assertSame('versioned', $upgrade->json('data.applied.0.mode'));

        $rows = Tax::withTrashed()->where('code', 'SG-GST-9')->orderBy('id')->get();
        $this->assertCount(2, $rows, 'A rate change to a rule documents use must create a new version row.');

        $old = $rows->firstWhere('id', $rule->id);
        $new = $rows->first(fn (Tax $row) => $row->id !== $rule->id);
        $this->assertSame(9.0, (float) $old->rate, 'The historical row keeps the rate the document was determined at.');
        $this->assertNotNull($old->effective_to, 'The historical row must be end-dated.');
        $this->assertTrue($old->is_active, 'The closed row stays resolvable for documents dated before the change.');

        $this->assertSame(9.5, (float) $new->rate);
        // Disclosed (pre-existing #274 behaviour, unchanged here): the
        // replacement row's optimistic version token restarts at 1. The
        // history-preserving mechanism is the end-dated old row plus the
        // tax_rule_versioned lineage audit, not the token.
        $this->assertSame(1, (int) $new->version);
        $this->assertSame(1, (int) $new->pack_version, 'The new row records the pack version it came from.');
        $this->assertTrue($new->is_active);

        // Documents are untouched: same rule id, same version, same booked tax.
        $this->assertSame($rule->id, $refs[0]['tax_rule_id'] ?? null);
        $this->assertSame(1, (int) ($refs[0]['rule_version'] ?? 0));
        $this->assertSame(90.0, (float) Invoice::findOrFail($postedInvoiceId)->tax_amount);

        // The DoD property: documents dated either side of the change resolve
        // DIFFERENT correct rules — the past keeps the old version, a later
        // document gets the new one.
        // Forward from the change, documents resolve the NEW version. (What a
        // NEW backdated document resolves after a supersede is deliberately
        // not asserted here: the engine resolves ACTIVE rules only, and
        // backdated-document policy in a superseded window is its own issue.)
        $future = $this->determinationReferencing($new->id, '2026-11-01');
        $this->assertSame($new->id, $future[0]['tax_rule_id'] ?? null, 'A document after the change must resolve the new rule version.');
    }
}
