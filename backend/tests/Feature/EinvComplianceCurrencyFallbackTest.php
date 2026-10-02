<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #669 EI7 (currency half): a compliance document records the invoice's own
 * currency, then its base currency, then the tenant document currency — never
 * a literal 'SGD' for a Malaysian invoice.
 */
class EinvComplianceCurrencyFallbackTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@einv669.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function invoiceWithoutCurrency(string $base): Invoice
    {
        $customer = Customer::factory()->create();

        return Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'CUR-'.Str::random(8),
            'invoice_date' => '2025-08-15',
            'due_date' => '2025-09-15',
            'status' => 'draft',
            'subtotal' => 100.0,
            'tax_amount' => 0.0,
            'total' => 100.0,
            'currency_code' => null,
            'base_currency_code' => $base,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_compliance_document_uses_the_base_currency_not_a_literal(): void
    {
        $invoice = $this->invoiceWithoutCurrency('MYR');

        // The Invoice creating hook auto-fills currency_code from the tenant
        // resolver; force the reported state (no explicit currency) so the
        // compliance fallback is exercised.
        $invoice->forceFill(['currency_code' => null])->save();

        app(ComplianceService::class)->submitInvoice($invoice->fresh(), 'MY');

        $document = ComplianceDocument::query()
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame('MYR', $document->currency_code, 'An MY invoice must not be recorded as SGD.');
    }

    public function test_an_explicit_invoice_currency_wins(): void
    {
        $invoice = $this->invoiceWithoutCurrency('MYR');
        $invoice->forceFill(['currency_code' => 'USD'])->save();

        app(ComplianceService::class)->submitInvoice($invoice, 'MY');

        $document = ComplianceDocument::query()
            ->where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame('USD', $document->currency_code);
    }
}
