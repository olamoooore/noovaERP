<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * Issue #300 (F13): the invoice form picks contacts from the customer's own
 * contact list (numeric customer_contacts ids). The screen dropped those ids
 * and sent the contact NAME, and the server resolved/created a core contact
 * by name — so a same-name person of another customer was linked, or a new
 * duplicate contact was created. The invoice must store the core contact
 * that belongs to the selected customer contact (a stable id, not a name).
 */
class Issue300InvoiceContactStableIdTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Customer $otherCustomer;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->admin = User::factory()->create(['email' => 'tmp300-'.uniqid().'@example.local']);
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        CompanySettings::query()->create([
            'company_name' => 'TMP300 Sdn Bhd',
            'base_currency' => 'MYR',
        ]);

        $entityGroupId = DB::table('entity_groups')->insertGetId([
            'name' => 'TMP300 Group',
            'code' => 'TMP300-G-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legalEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $entityGroupId,
            'name' => 'TMP300 Entity',
            'code' => 'TMP300-E-'.Str::upper(Str::random(5)),
            'country_code' => 'MY',
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['legal_entity_id' => $legalEntityId, 'payment_terms' => 30]);
        $this->otherCustomer = Customer::factory()->create(['legal_entity_id' => $legalEntityId, 'payment_terms' => 30]);

        DB::table('currencies')->updateOrInsert(
            ['code' => 'MYR'],
            ['name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'is_active' => true, 'is_sellable' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function person(string $displayName): CoreContact
    {
        return CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_type' => 'individual',
            'first_name' => $displayName,
            'display_name' => $displayName,
            'source' => 'crm_customer_contact',
            'is_active' => true,
        ]);
    }

    private function customerContact(Customer $customer, string $name, ?CoreContact $person): CustomerContact
    {
        return CustomerContact::create([
            'customer_id' => $customer->id,
            'name' => $name,
            'core_contact_uuid' => $person?->id,
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
                'description' => 'Consulting',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $extra);
    }

    public function test_create_stores_the_selected_customer_contacts_person_not_a_same_name_contact(): void
    {
        // Another customer's "Lim Wei" exists first in the contact master.
        $otherLim = $this->person('Lim Wei');
        $this->customerContact($this->otherCustomer, 'Lim Wei', $otherLim);

        // This customer's own "Lim Wei" is a different person.
        $ourLimPerson = $this->person('Dr. Lim Wei');
        $ourLim = $this->customerContact($this->customer, 'Lim Wei', $ourLimPerson);

        $ourTanPerson = $this->person('Tan Ah Kow (Purchasing)');
        $ourTan = $this->customerContact($this->customer, 'Tan Ah Kow', $ourTanPerson);

        $coreBefore = CoreContact::count();

        $response = $this->postJson('/api/v1/invoices', $this->invoicePayload([
            'billing_customer_contact_id' => $ourLim->id,
            'doctor_customer_contact_id' => $ourLim->id,
            'shipping_customer_contact_id' => $ourTan->id,
            'requestor_customer_contact_id' => $ourTan->id,
            // The names the screen used to send alongside (and instead of) ids.
            'billing_contact_name' => 'Lim Wei',
            'doctor_contact_name' => 'Lim Wei',
            'shipping_contact_name' => 'Tan Ah Kow',
            'requestor_contact_name' => 'Tan Ah Kow',
        ]));
        $response->assertCreated($response->getContent());

        $invoice = Invoice::findOrFail($response->json('id'));
        $this->assertSame($ourLimPerson->id, $invoice->billing_contact_id, 'Billing contact must be the selected customer contact, not a same-name person of another customer.');
        $this->assertSame($ourLimPerson->id, $invoice->doctor_contact_id, 'Doctor contact must be the selected customer contact.');
        $this->assertSame($ourTanPerson->id, $invoice->shipping_contact_id, 'Shipping contact must be the selected customer contact.');
        $this->assertSame($ourTanPerson->id, $invoice->requestor_contact_id, 'Requestor contact must be the selected customer contact.');
        $this->assertSame($coreBefore, CoreContact::count(), 'Saving an invoice must not create duplicate contacts.');
    }

    public function test_an_explicit_contact_id_is_not_overridden_by_a_name(): void
    {
        $otherLim = $this->person('Lim Wei');
        $ourLimPerson = $this->person('Dr. Lim Wei');
        $this->customerContact($this->customer, 'Lim Wei', $ourLimPerson);

        $response = $this->postJson('/api/v1/invoices', $this->invoicePayload([
            'billing_contact_id' => $ourLimPerson->id,
            'billing_contact_name' => 'Lim Wei',
        ]));
        $response->assertCreated($response->getContent());

        $invoice = Invoice::findOrFail($response->json('id'));
        $this->assertSame($ourLimPerson->id, $invoice->billing_contact_id, 'A sent contact id must win over name-based resolution.');
        $this->assertNotSame($otherLim->id, $invoice->billing_contact_id);
    }

    public function test_update_stores_the_selected_customer_contacts_person(): void
    {
        $otherLim = $this->person('Lim Wei');
        $this->customerContact($this->otherCustomer, 'Lim Wei', $otherLim);
        $ourLimPerson = $this->person('Dr. Lim Wei');
        $ourLim = $this->customerContact($this->customer, 'Lim Wei', $ourLimPerson);

        $create = $this->postJson('/api/v1/invoices', $this->invoicePayload());
        $create->assertCreated($create->getContent());
        $invoiceId = $create->json('id');

        $coreBefore = CoreContact::count();
        $update = $this->putJson("/api/v1/invoices/{$invoiceId}", [
            'billing_customer_contact_id' => $ourLim->id,
            'shipping_customer_contact_id' => $ourLim->id,
        ]);
        $update->assertOk($update->getContent());

        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame($ourLimPerson->id, $invoice->billing_contact_id);
        $this->assertSame($ourLimPerson->id, $invoice->shipping_contact_id);
        $this->assertSame($coreBefore, CoreContact::count(), 'Editing an invoice must not create duplicate contacts.');
    }

    public function test_a_customer_contact_of_another_customer_is_rejected(): void
    {
        $otherLim = $this->customerContact($this->otherCustomer, 'Lim Wei', $this->person('Lim Wei'));

        $response = $this->postJson('/api/v1/invoices', $this->invoicePayload([
            'billing_customer_contact_id' => $otherLim->id,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['billing_customer_contact_id']);
    }

    public function test_an_unlinked_customer_contact_gets_one_stable_person_reused_on_the_next_save(): void
    {
        $unlinked = $this->customerContact($this->customer, 'Siti Aminah', null);

        $first = $this->postJson('/api/v1/invoices', $this->invoicePayload([
            'billing_customer_contact_id' => $unlinked->id,
        ]));
        $first->assertCreated($first->getContent());

        $personId = Invoice::findOrFail($first->json('id'))->billing_contact_id;
        $this->assertNotNull($personId, 'An unlinked customer contact must still be stored as a contact link.');
        $this->assertSame($personId, $unlinked->fresh()->core_contact_uuid, 'The customer contact must be linked to the stored person.');

        $coreBefore = CoreContact::count();
        $second = $this->postJson('/api/v1/invoices', $this->invoicePayload([
            'billing_customer_contact_id' => $unlinked->id,
        ]));
        $second->assertCreated($second->getContent());

        $this->assertSame($personId, Invoice::findOrFail($second->json('id'))->billing_contact_id);
        $this->assertSame($coreBefore, CoreContact::count(), 'A second invoice for the same customer contact must reuse the person.');
    }
}
