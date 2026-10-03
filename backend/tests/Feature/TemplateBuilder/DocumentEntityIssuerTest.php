<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Tests\TenantTestCase;

/**
 * Issue #612 (TP2) — printed documents must use the SELLING legal entity as
 * the issuer, and template resolution must receive the document's resolution
 * context (entity/language/currency/customer group/…) instead of resolving
 * the tenant-scope default blindly.
 *
 * These are router-level tests (router → middleware → controller → data
 * source → renderer), not source-string or service-direct tests.
 */
class DocumentEntityIssuerTest extends TenantTestCase
{
    private string $tenantId;

    private User $manager;

    private int $entityA;

    private int $entityB;

    private Invoice $invoiceA;

    private Invoice $invoiceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Entity Issuer Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->manager = User::factory()->create();
        $this->manager->assignRole('Manager');

        DB::table('user_company_access')->insert([
            'user_id' => $this->manager->id,
            'tenant_id' => $this->tenantId,
            'role' => 'manager',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Entity Issuer Group',
            'code' => 'EIG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A Sdn Bhd',
            'code' => 'EIG-A',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            // Issue #612: per-entity letterhead fields.
            'registration_number' => 'REG-A-123',
            'tax_id' => 'TIN-A-456',
            'sst_registration_number' => 'SST-A-789',
            'address' => "1 Entity A Road\nKuala Lumpur",
            'logo_url' => 'https://cdn.example/entity-a.png',
            'email' => 'billing@entity-a.example',
            'phone' => '+60 3-1111 1111',
            'website' => 'https://entity-a.example',
            'bank_details' => json_encode([
                'bank_name' => 'Bank A',
                'account_name' => 'Entity A Sdn Bhd',
                'account_number' => 'ACC-A-001',
                'swift_code' => 'AAAAMYKL',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B Sdn Bhd',
            'code' => 'EIG-B',
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'registration_number' => 'REG-B-321',
            'tax_id' => 'TIN-B-654',
            'sst_registration_number' => 'SST-B-987',
            'address' => "2 Entity B Avenue\nSingapore",
            'logo_url' => 'https://cdn.example/entity-b.png',
            'email' => 'billing@entity-b.example',
            'phone' => '+65 6222 2222',
            'website' => 'https://entity-b.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$this->entityA, $this->entityB] as $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $this->manager->id,
                'legal_entity_id' => $entityId,
                'role' => 'editor',
                'is_default' => $entityId === $this->entityA,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $customerA = Customer::factory()->create([
            'code' => 'EIG-CUST-A',
            'legal_entity_id' => $this->entityA,
        ]);
        $customerB = Customer::factory()->create([
            'code' => 'EIG-CUST-B',
            'legal_entity_id' => $this->entityB,
        ]);

        $this->invoiceA = Invoice::factory()->create([
            'customer_id' => $customerA->id,
            'invoice_number' => 'INV-EIG-A-0001',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $this->invoiceB = Invoice::factory()->create([
            'customer_id' => $customerB->id,
            'invoice_number' => 'INV-EIG-B-0001',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);
    }

    public function test_each_entities_invoice_prints_its_own_letterhead(): void
    {
        // Entity B carries no bank details: that single field must fall back
        // to the tenant's bank (per-field fallback), not leak A's bank.
        Setting::setValue('bank_details', [
            'bank_name' => 'Tenant Fallback Bank',
            'account_name' => 'Tenant HQ',
            'account_number' => 'ACC-TENANT',
        ]);

        $template = $this->issuerProbeTemplate(implode('|', [
            '{{ company.name }}',
            '{{ company.registration_number }}',
            '{{ company.tax_id }}',
            '{{ company.sst_registration_number }}',
            '{{ company.address }}',
            '{{ company.logo_url }}',
            '{{ company.email }}',
            '{{ company.bank_name }}',
            '{{ company.bank_account_name }}',
            '{{ company.bank_account_number }}',
            '{{ company.bank_swift_code }}',
            '{{ company.currency_code }}',
        ]));

        $responseA = $this->actingAs($this->manager)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get("/api/v1/invoices/{$this->invoiceA->id}/html?template={$template->id}");

        $responseB = $this->actingAs($this->manager)
            ->withHeader('X-Entity-Id', (string) $this->entityB)
            ->get("/api/v1/invoices/{$this->invoiceB->id}/html?template={$template->id}");

        $responseA->assertOk();
        $responseB->assertOk();

        // Entity A prints its own full letterhead…
        foreach ([
            'Entity A Sdn Bhd',
            'REG-A-123',
            'TIN-A-456',
            'SST-A-789',
            '1 Entity A Road',
            'https://cdn.example/entity-a.png',
            'billing@entity-a.example',
            'Bank A',
            'Entity A Sdn Bhd', // bank account name
            'ACC-A-001',
            'AAAAMYKL',
            'SGD',
        ] as $expected) {
            $this->assertStringContainsString($expected, $responseA->getContent());
        }
        $this->assertStringNotContainsString('Bank A', $responseB->getContent());
        $this->assertStringNotContainsString('ACC-A-001', $responseB->getContent());
        $this->assertStringNotContainsString('Tenant Fallback Bank', $responseA->getContent());

        // …Entity B prints its own letterhead, with the tenant bank fallback.
        foreach ([
            'Entity B Sdn Bhd',
            'REG-B-321',
            'TIN-B-654',
            'SST-B-987',
            '2 Entity B Avenue',
            'https://cdn.example/entity-b.png',
            'billing@entity-b.example',
            'Tenant Fallback Bank',
            'ACC-TENANT',
            'USD',
        ] as $expected) {
            $this->assertStringContainsString($expected, $responseB->getContent());
        }
        foreach ([
            'Entity A Sdn Bhd',
            'REG-A-123',
            'TIN-A-456',
            'SST-A-789',
        ] as $unexpected) {
            $this->assertStringNotContainsString($unexpected, $responseB->getContent());
        }
    }

    public function test_document_without_legal_entity_falls_back_to_tenant_settings(): void
    {
        CompanySettings::create([
            'company_name' => 'Tenant HQ Sdn Bhd',
            'tax_id' => 'TIN-TENANT',
            'gst_registration_number' => 'SST-TENANT',
            'address' => 'Tenant HQ Address, Kuala Lumpur',
            'base_currency' => 'MYR',
        ]);

        DB::table('invoices')->where('id', $this->invoiceA->id)->update(['legal_entity_id' => null]);
        DB::table('customers')->where('id', $this->invoiceA->customer_id)->update(['legal_entity_id' => null]);

        $template = $this->issuerProbeTemplate(implode('|', [
            '{{ company.name }}',
            '{{ company.tax_id }}',
            '{{ company.sst_registration_number }}',
            '{{ company.address }}',
            '{{ company.currency_code }}',
        ]));

        $response = $this->actingAs($this->manager)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get("/api/v1/invoices/{$this->invoiceA->id}/html?template={$template->id}");

        $response->assertOk();

        foreach ([
            'Tenant HQ Sdn Bhd',
            'TIN-TENANT',
            'SST-TENANT',
            'Tenant HQ Address, Kuala Lumpur',
            'MYR',
        ] as $expected) {
            $this->assertStringContainsString($expected, $response->getContent());
        }

        $this->assertStringNotContainsString('Entity A Sdn Bhd', $response->getContent());
    }

    public function test_entity_scoped_template_assignment_wins_for_that_entities_documents(): void
    {
        $default = $this->publishedTemplate('invoice', 'Tenant Default', 'TENANT-DEFAULT-TEMPLATE');
        $entityB = $this->publishedTemplate('invoice', 'Entity B Letterhead', 'ENTITY-B-LETTERHEAD');

        DocumentTemplateAssignment::create([
            'document_type' => 'invoice',
            'template_id' => $default->id,
            'scope_type' => 'tenant',
            'priority' => 10,
            'is_active' => true,
        ]);

        DocumentTemplateAssignment::create([
            'document_type' => 'invoice',
            'template_id' => $entityB->id,
            'entity_id' => (string) $this->entityB,
            'priority' => 100,
            'is_active' => true,
        ]);

        $responseA = $this->actingAs($this->manager)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get("/api/v1/invoices/{$this->invoiceA->id}/html");

        $responseB = $this->actingAs($this->manager)
            ->withHeader('X-Entity-Id', (string) $this->entityB)
            ->get("/api/v1/invoices/{$this->invoiceB->id}/html");

        $responseA->assertOk();
        $responseB->assertOk();

        $this->assertStringContainsString('TENANT-DEFAULT-TEMPLATE', $responseA->getContent());
        $this->assertStringNotContainsString('ENTITY-B-LETTERHEAD', $responseA->getContent());

        $this->assertStringContainsString('ENTITY-B-LETTERHEAD', $responseB->getContent());
        $this->assertStringNotContainsString('TENANT-DEFAULT-TEMPLATE', $responseB->getContent());
    }

    private function issuerProbeTemplate(string $body): DocumentTemplate
    {
        return $this->publishedTemplate('invoice', 'Issuer Probe', $body);
    }

    private function publishedTemplate(string $documentType, string $name, string $body): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => $name,
            'mode' => DocumentTemplate::MODE_CODE,
            'html' => '<div class="probe">'.$body.'</div>',
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);

        $template->versions()->create([
            'template_id' => $template->id,
            'version' => 1,
            'html' => $template->html,
            'is_published' => true,
        ]);

        return $template;
    }
}
