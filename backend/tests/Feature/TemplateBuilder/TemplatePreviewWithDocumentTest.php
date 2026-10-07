<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\GeneratedDocument;
use Tests\TenantTestCase;

/**
 * Issue #620 (TPL-UX3) — the template editor preview can render the DRAFT
 * template against a REAL document ("Preview with INV-2026-0412").
 *
 * Acceptance: a document picker lists recent documents of the template's
 * type that the user may see; the draft renders with the real payload; PHI
 * is masked unless the user holds the PHI permission. Document access reuses
 * the #611 render checks (source permission + legal entity, no IDOR).
 *
 * Router-level tests (router → middleware → controller).
 */
class TemplatePreviewWithDocumentTest extends TenantTestCase
{
    private string $tenantId;

    private User $cashier;

    private User $manager;

    private int $entityA;

    private int $entityB;

    private Invoice $invoiceA;

    private Invoice $invoiceB;

    private Payment $paymentA;

    private DocumentTemplate $invoiceTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Preview Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('Cashier');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('Manager');

        foreach ([[$this->cashier, 'cashier'], [$this->manager, 'manager']] as [$user, $role]) {
            DB::table('user_company_access')->insert([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Preview Group',
            'code' => 'PVG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['A', 'B'] as $suffix) {
            $this->{'entity'.$suffix} = DB::table('legal_entities')->insertGetId([
                'group_id' => $groupId,
                'name' => 'Entity '.$suffix,
                'code' => 'PV-'.$suffix,
                'base_currency' => 'SGD',
                'fiscal_year_start' => '01-01',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('user_entity_access')->insert([
            ['user_id' => $this->cashier->id, 'legal_entity_id' => $this->entityA, 'role' => 'editor', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $this->manager->id, 'legal_entity_id' => $this->entityA, 'role' => 'editor', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $this->manager->id, 'legal_entity_id' => $this->entityB, 'role' => 'editor', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $customerA = Customer::factory()->create(['code' => 'PV-CUST-A', 'legal_entity_id' => $this->entityA]);
        $customerB = Customer::factory()->create(['code' => 'PV-CUST-B', 'legal_entity_id' => $this->entityB]);

        // A foreign-currency invoice: the real-PDF pipeline appends the
        // automatic FX footer (FR-8.5), so its presence proves the preview
        // renders through the same build pipeline as real PDFs.
        $this->invoiceA = Invoice::factory()->create([
            'customer_id' => $customerA->id,
            'legal_entity_id' => $this->entityA,
            'invoice_number' => 'INV-2026-0412',
            'invoice_date' => '2026-09-30',
            'patient_name' => 'Jane Doe',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'currency_code' => 'USD',
            'base_currency_code' => 'SGD',
            'exchange_rate' => 1.35,
            'total' => 100,
        ]);
        InvoiceLine::create([
            'invoice_id' => $this->invoiceA->id,
            'description' => 'Hip implant lot L-77',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);

        $this->invoiceB = Invoice::factory()->create([
            'customer_id' => $customerB->id,
            'legal_entity_id' => $this->entityB,
            'invoice_number' => 'INV-2026-0999',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $this->paymentA = Payment::factory()->create([
            'customer_id' => $customerA->id,
            'invoice_id' => $this->invoiceA->id,
            'payment_number' => 'PAY-PV-A-0001',
        ]);

        // A published template whose saved draft differs from what the editor
        // is about to send: the preview must render the unsaved edits.
        $this->invoiceTemplate = DocumentTemplate::create([
            'document_type' => 'invoice',
            'code' => 'PV-INV-'.Str::random(6),
            'name' => 'Preview Invoice',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'status' => DocumentTemplate::STATUS_DRAFT,
            'layout_config' => ['blocks' => [['type' => 'text', 'content' => 'SAVED DRAFT']]],
        ]);
    }

    /** @return array<string,mixed> */
    private function draftEdits(): array
    {
        return [
            'mode' => 'block',
            'layout_config' => ['blocks' => [
                ['type' => 'text', 'content' => 'UNSAVED EDIT {{ invoice.number }} for {{ customer.code }}'],
                ['type' => 'text', 'content' => 'Patient: {{ lines.0.patient_name }}'],
            ]],
        ];
    }

    public function test_picker_lists_recent_documents_of_the_template_type_within_the_users_entity(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->getJson("/api/v1/template-builder/templates/{$this->invoiceTemplate->id}/preview-documents");

        $response->assertOk();
        $numbers = collect($response->json('data'))->pluck('number')->all();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->assertContains('INV-2026-0412', $numbers);
        $this->assertContains((string) $this->invoiceA->id, $ids);
        $this->assertNotContains('INV-2026-0999', $numbers, 'Another entity\'s invoice must not be offered.');
    }

    public function test_picker_is_refused_when_the_user_lacks_the_source_document_permission(): void
    {
        $receiptTemplate = DocumentTemplate::create([
            'document_type' => 'receipt',
            'code' => 'PV-RCPT-'.Str::random(6),
            'name' => 'Preview Receipt',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'status' => DocumentTemplate::STATUS_DRAFT,
            'layout_config' => ['blocks' => [['type' => 'text', 'content' => 'Receipt']]],
        ]);

        // Cashier holds template.view but not accounting.view (receipts).
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->getJson("/api/v1/template-builder/templates/{$receiptTemplate->id}/preview-documents");

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'accounting.view');
    }

    public function test_preview_renders_the_unsaved_draft_against_the_real_document_with_phi_masked(): void
    {
        $generatedBefore = GeneratedDocument::query()->count();

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->postJson(
                "/api/v1/template-builder/templates/{$this->invoiceTemplate->id}/preview",
                $this->draftEdits() + ['document_id' => (string) $this->invoiceA->id],
            );

        $response->assertOk();
        $html = (string) $response->json('html');

        $this->assertStringContainsString('UNSAVED EDIT INV-2026-0412 for PV-CUST-A', $html);
        $this->assertStringNotContainsString('SAVED DRAFT', $html);
        // PHI masked for a user without medical_cases.phi.view.
        $this->assertStringNotContainsString('Jane Doe', $html);
        $this->assertStringContainsString('J******e', $html);
        // Same pipeline as the real PDF: the automatic FX footer is appended.
        $this->assertStringContainsString('Exchange rate: 1 USD', $html);
        // A preview is not an issued document: nothing is persisted.
        $this->assertSame($generatedBefore, GeneratedDocument::query()->count());
    }

    public function test_preview_shows_phi_to_a_user_holding_the_phi_permission(): void
    {
        $response = $this->actingAs($this->manager)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->postJson(
                "/api/v1/template-builder/templates/{$this->invoiceTemplate->id}/preview",
                $this->draftEdits() + ['document_id' => (string) $this->invoiceA->id],
            );

        $response->assertOk();
        $this->assertStringContainsString('Patient: Jane Doe', (string) $response->json('html'));
    }

    public function test_preview_with_another_entitys_document_is_refused_like_a_missing_one(): void
    {
        $crossEntity = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->postJson(
                "/api/v1/template-builder/templates/{$this->invoiceTemplate->id}/preview",
                $this->draftEdits() + ['document_id' => (string) $this->invoiceB->id],
            );

        $missing = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->postJson(
                "/api/v1/template-builder/templates/{$this->invoiceTemplate->id}/preview",
                $this->draftEdits() + ['document_id' => '99999999'],
            );

        $crossEntity->assertStatus(403);
        $this->assertStringNotContainsString('INV-2026-0999', (string) $crossEntity->getContent());
        $this->assertSame($crossEntity->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($crossEntity->json('message'), $missing->json('message'));
    }

    public function test_preview_with_a_document_requires_the_source_documents_permission(): void
    {
        $receiptTemplate = DocumentTemplate::create([
            'document_type' => 'receipt',
            'code' => 'PV-RCPT-'.Str::random(6),
            'name' => 'Preview Receipt',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'status' => DocumentTemplate::STATUS_DRAFT,
            'layout_config' => ['blocks' => [['type' => 'text', 'content' => 'Receipt']]],
        ]);

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->postJson(
                "/api/v1/template-builder/templates/{$receiptTemplate->id}/preview",
                ['document_id' => (string) $this->paymentA->id],
            );

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'accounting.view');
    }

    public function test_preview_without_a_document_still_uses_sample_data(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->postJson(
                "/api/v1/template-builder/templates/{$this->invoiceTemplate->id}/preview",
                $this->draftEdits(),
            );

        $response->assertOk();
        $this->assertStringContainsString('UNSAVED EDIT INV-2026-0001', (string) $response->json('html'));
    }
}
