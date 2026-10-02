<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #486: payment methods must come from a tenant-configurable master
 * (code, label, mapping key, default journal, requires-* flags) contributed
 * by the country packs; validation, posting and the UI all read from it.
 *
 * Before: three hardcoded lists that disagreed — manual entry allowed 5
 * methods, posting allowed a 6th ('gateway'), the UI hard-coded its own 5 —
 * and no local rails (DuitNow/PayNow/FPX) could be selected at all.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentMethodMasterTest extends TenantTestCase
{
    protected User $admin;

    protected Customer $customer;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['payment_terms' => 30]);

        // Tenant "Default Accounts" configuration: gateway_receivable is not
        // an auto-seeded FALLBACK key (same as card/cheque clearing) — the
        // tenant must map it, and the posting engine then resolves it.
        if (! DB::table('account_mappings')->where('mapping_key', 'gateway_receivable')->whereNull('document_type')->exists()) {
            $gatewayGlId = DB::table('chart_of_accounts')->insertGetId([
                'code' => '1030',
                'name' => 'Gateway Receivable',
                'type' => 'asset',
                'account_type' => 'asset',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('account_mappings')->insert([
                'mapping_key' => 'gateway_receivable',
                'account_id' => $gatewayGlId,
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Generic master + MY pack rails, exactly as tenant provisioning does.
        $this->seed(\Database\Seeders\PaymentMethodsSeeder::class);
        $rails = new \Database\Seeders\PaymentMethodRailsSeeder;
        $rails->countryCode = 'MY';
        $rails->run();
    }

    private function openInvoice(float $total = 1000): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'created_by' => $this->admin->id,
        ]);
    }

    private function postPayment(array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/payments', $overrides + [
            'invoice_id' => $this->openInvoice()->id,
            'amount' => 100,
            'method' => 'bank_transfer',
        ]);
    }

    public function test_pack_rails_are_valid_manual_entry_methods(): void
    {
        // 'duitnow' only exists in the master (MY pack) — the old hardcoded
        // in:-list rejected it with 422.
        $response = $this->postPayment(['method' => 'duitnow'])->assertCreated();

        $this->assertSame('duitnow', Payment::findOrFail($response->json('id'))->method);
    }

    public function test_gateway_is_consistent_between_validation_and_posting(): void
    {
        // Posting historically allowed 'gateway' but manual entry rejected it —
        // the master is now the single source of truth for both.
        $response = $this->postPayment(['method' => 'gateway'])->assertCreated();
        $payment = Payment::findOrFail($response->json('id'));

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        $this->assertNotNull($payment->fresh()->journal_entry_id, 'gateway payments must be postable');
    }

    public function test_unknown_methods_are_still_rejected(): void
    {
        $this->postPayment(['method' => 'bitcoin'])->assertStatus(422);
    }

    public function test_cheque_requires_a_reference_from_the_master_flag(): void
    {
        $this->postPayment(['method' => 'check'])->assertStatus(422);

        $this->postPayment(['method' => 'check', 'reference' => 'CHQ-000123'])->assertCreated();
    }

    public function test_pack_rail_posts_through_the_master_mapping_key(): void
    {
        $invoice = $this->openInvoice(500);
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 500,
                'method' => 'duitnow',
                'reference' => 'DNREF-1',
            ])
            ->assertCreated();
        $payment = Payment::findOrFail($response->json('id'));

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment->id}/post")
            ->assertOk();

        $payment->refresh();
        $this->assertNotNull($payment->journal_entry_id);

        $lines = JournalEntry::find($payment->journal_entry_id)->lines()->get();
        $bankMappingAccountId = DB::table('account_mappings')
            ->where('mapping_key', 'bank')
            ->whereNull('document_type')
            ->value('account_id');
        $debit = $lines->first(fn ($l) => (float) $l->debit > 0);

        $this->assertSame(
            (int) $bankMappingAccountId,
            (int) $debit->account_id,
            "duitnow's master mapping_key (bank) must drive the debit GL",
        );
        $this->assertEqualsWithDelta(
            0.0,
            $lines->sum(fn ($l) => (float) $l->debit) - $lines->sum(fn ($l) => (float) $l->credit),
            0.001,
            'books must balance',
        );
    }

    public function test_payment_methods_endpoint_exposes_the_master(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/payment-methods')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code');
        foreach (['cash', 'card', 'bank_transfer', 'check', 'gateway', 'other', 'duitnow', 'fpx', 'grabpay'] as $code) {
            $this->assertTrue($codes->contains($code), "master must expose '{$code}'");
        }

        $cheque = collect($response->json('data'))->firstWhere('code', 'check');
        $this->assertTrue((bool) $cheque['requires_reference']);
        $this->assertSame('cheque_clearing', $cheque['mapping_key']);
    }
}
