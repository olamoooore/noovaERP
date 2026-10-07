<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * Issue #1007 (VC-007): selecting a customer defaults the bill-to (company,
 * billing contact, billing address); the user may override it; the invoice
 * keeps what was saved. A later change to the customer master (rename, new
 * address) must not rewrite a saved invoice's bill-to, and a draft edit that
 * does not send contact fields must leave the saved billing contact alone.
 *
 * All checks go through the real HTTP routes.
 */
class Issue1007InvoiceBillToSnapshotTest extends TenantTestCase
{
    private string $tenantId;

    private Customer $customer;

    private CustomerAddress $billingAddress;

    private CustomerContact $billingContact;

    private CoreContact $billingPerson;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContextWithPermissions($this->tenantId);

        $admin = User::factory()->create(['email' => 'tmp1007-'.uniqid().'@example.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($admin);

        CompanySettings::query()->create([
            'company_name' => 'TMP1007 Pte Ltd',
            'base_currency' => 'SGD',
        ]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'SGD'],
            ['name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->customer = Customer::factory()->create([
            'name' => 'Elisa Koh Clinic and Surgery Pte Ltd',
            'company' => 'Elisa Koh Clinic and Surgery Pte Ltd',
            'type' => 'company',
            'payment_terms' => 30,
        ]);

        $this->billingAddress = CustomerAddress::create([
            'customer_id' => $this->customer->id,
            'type' => 'billing',
            'street' => '10 Sinaran Drive',
            'city' => 'Singapore',
            'zip' => '307506',
            'country' => 'Singapore',
            'is_default' => true,
        ]);

        $this->billingPerson = CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_type' => 'individual',
            'first_name' => 'Elisa',
            'last_name' => 'Koh',
            'display_name' => 'Dr Elisa Koh',
            'source' => 'crm_customer_contact',
            'is_active' => true,
        ]);

        $this->billingContact = CustomerContact::create([
            'customer_id' => $this->customer->id,
            'name' => 'Dr Elisa Koh',
            'contact_category' => 'billing',
            'core_contact_uuid' => $this->billingPerson->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function invoicePayload(array $extra = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'description' => 'Consultation',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $extra);
    }

    public function test_bill_to_is_snapshotted_on_save_and_survives_customer_master_changes_and_draft_edits(): void
    {
        // The user kept the default billing contact but typed an override
        // into the billing address (e.g. "Attn: Accounts, Level 3").
        $snapshot = "Elisa Koh Clinic and Surgery Pte Ltd\nAttn: Accounts, Level 3\n10 Sinaran Drive, Singapore 307506";

        $create = $this->postJson('/api/v1/invoices', $this->invoicePayload([
            'billing_customer_contact_id' => $this->billingContact->id,
            'attention_to' => 'Dr Elisa Koh',
            'billing_address_snapshot' => $snapshot,
        ]));
        $create->assertCreated($create->getContent());
        $invoiceId = $create->json('id');

        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame($this->billingPerson->id, $invoice->billing_contact_id, 'The selected billing contact must be stored.');
        $this->assertSame('Dr Elisa Koh', $invoice->attention_to);
        $this->assertSame($snapshot, $invoice->billing_address_snapshot, 'The (overridden) bill-to address must be stored as typed.');

        // The customer master changes afterwards: renamed + new billing address.
        $this->putJson("/api/v1/customers/{$this->customer->id}", [
            'name' => 'EKC Medical Holdings Pte Ltd',
            'company' => 'EKC Medical Holdings Pte Ltd',
        ])->assertOk();
        $this->putJson("/api/v1/customers/{$this->customer->id}/addresses/{$this->billingAddress->id}", [
            'street' => '1 New Address Road',
            'city' => 'Singapore',
            'zip' => '100001',
        ])->assertOk();

        // A draft edit that does not send any contact / bill-to field.
        $update = $this->putJson("/api/v1/invoices/{$invoiceId}", [
            'notes' => 'Edited after the customer was renamed',
        ]);
        $update->assertOk($update->getContent());

        $invoice->refresh();
        $this->assertSame('Edited after the customer was renamed', $invoice->notes);
        $this->assertSame($this->billingPerson->id, $invoice->billing_contact_id, 'A draft edit without contact fields must keep the saved billing contact.');
        $this->assertSame('Dr Elisa Koh', $invoice->attention_to, 'The saved attention-to must not change.');
        $this->assertSame($snapshot, $invoice->billing_address_snapshot, 'A customer master change must not rewrite the saved bill-to.');
    }

    public function test_customer_search_reports_the_matched_contact_id_for_the_billing_preselect(): void
    {
        $response = $this->getJson('/api/v1/customers?search=Elisa%20Koh&per_page=50');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $this->customer->id);
        $this->assertNotNull($row, 'The customer must be found by its contact person.');
        $this->assertSame(['Dr Elisa Koh'], $row['matched_contacts']);
        $this->assertSame(
            [(int) $this->billingContact->id],
            array_map('intval', $row['matched_contact_ids'] ?? []),
            'The picker needs the matched contact id to pre-select it as the billing contact.'
        );
    }
}
