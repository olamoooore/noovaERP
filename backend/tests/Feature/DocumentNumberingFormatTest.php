<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Invoice numbers must always carry their document prefix.
 *
 * Regression (client report 2026-10-01): a prefix-less numbering pattern
 * (e.g. "#{SEQ:5}") generated bare "#09009" numbers, so the tax invoice
 * showed "09009" where "INV-2026-09009" was expected — and hand-typed
 * numbers appeared "missing" next to it.
 */
class DocumentNumberingFormatTest extends TenantTestCase
{
    private string $tenantId;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Numbering Format Tenant',
            'email' => 'numbering-format@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);

        $this->owner = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->owner->id,
            'tenant_id' => $this->tenantId,
            'role' => 'owner',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_prefix_less_format_falls_back_to_the_invoice_prefix(): void
    {
        Setting::setValue('document_format.invoice', '#{SEQ:5}');

        $number = app(DocumentNumberingService::class)
            ->generate('invoice', 'INV-{YYYY}-{SEQ:4}');

        $this->assertStringStartsWith('INV-'.date('Y').'-', $number);
        $this->assertStringNotContainsString('#', $number);
    }

    public function test_a_genuine_custom_format_is_respected(): void
    {
        Setting::setValue('document_format.invoice', 'SINV/{YYYY}/{SEQ:3}');

        $number = app(DocumentNumberingService::class)
            ->generate('invoice', 'INV-{YYYY}-{SEQ:4}');

        $this->assertStringStartsWith('SINV/'.date('Y').'/', $number);
    }

    public function test_creating_an_invoice_generates_a_prefixed_number_even_with_a_bad_setting(): void
    {
        Setting::setValue('document_format.invoice', '#{SEQ:5}');

        $customer = Customer::factory()->create();

        $response = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'description' => 'Numbering format line',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated();

        $number = (string) Invoice::find($response->json('id'))->invoice_number;
        $this->assertStringStartsWith('INV-'.date('Y').'-', $number);
    }
}
