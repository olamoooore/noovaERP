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
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Tests\TenantTestCase;

/**
 * Issue #611 — Document render endpoints were gated only by document.view, so
 * any user holding that permission could fetch any invoice / statement /
 * receipt PDF by id across every legal entity, with PHI (patient_name) in
 * plaintext.
 *
 * These are router-level tests (router → middleware → controller).
 */
class DocumentRenderAccessTest extends TenantTestCase
{
    private string $tenantId;

    private User $cashier;

    private User $manager;

    private int $entityA;

    private int $entityB;

    private Invoice $invoiceA;

    private Invoice $invoiceB;

    private Payment $paymentA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Render Access Tenant',
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
            'name' => 'Render Access Group',
            'code' => 'RAG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => 'RA-A',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => 'RA-B',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cashier is scoped to entity A only; manager can see both.
        DB::table('user_entity_access')->insert([
            [
                'user_id' => $this->cashier->id,
                'legal_entity_id' => $this->entityA,
                'role' => 'editor',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $this->manager->id,
                'legal_entity_id' => $this->entityA,
                'role' => 'editor',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $this->manager->id,
                'legal_entity_id' => $this->entityB,
                'role' => 'editor',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $customerA = Customer::factory()->create([
            'code' => 'RA-CUST-A',
            'legal_entity_id' => $this->entityA,
        ]);
        $customerB = Customer::factory()->create([
            'code' => 'RA-CUST-B',
            'legal_entity_id' => $this->entityB,
        ]);

        $this->invoiceA = Invoice::factory()->create([
            'customer_id' => $customerA->id,
            'invoice_number' => 'INV-RA-A-0001',
            'patient_name' => 'Jane Doe',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);
        InvoiceLine::create([
            'invoice_id' => $this->invoiceA->id,
            'description' => 'Consultation',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);

        $this->invoiceB = Invoice::factory()->create([
            'customer_id' => $customerB->id,
            'invoice_number' => 'INV-RA-B-0001',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $this->paymentA = Payment::factory()->create([
            'customer_id' => $customerA->id,
            'invoice_id' => $this->invoiceA->id,
            'payment_number' => 'PAY-RA-A-0001',
        ]);
    }

    private function phiProbeTemplate(): DocumentTemplate
    {
        return DocumentTemplate::create([
            'document_type' => 'invoice',
            'code' => 'PHI-PROBE-'.Str::random(6),
            'name' => 'PHI Probe',
            'mode' => DocumentTemplate::MODE_BLOCK,
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'layout_config' => [
                'blocks' => [
                    ['type' => 'text', 'content' => 'Patient: {{ lines.0.patient_name }}'],
                ],
            ],
        ]);
    }

    public function test_cashier_cannot_render_an_invoice_from_another_entity(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get("/api/v1/invoices/{$this->invoiceB->id}/html");

        $response->assertStatus(403);
    }

    public function test_missing_and_cross_entity_invoice_ids_are_indistinguishable(): void
    {
        $crossEntity = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get("/api/v1/invoices/{$this->invoiceB->id}/html");

        $missing = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get('/api/v1/invoices/99999999/html');

        $this->assertSame(
            $crossEntity->getStatusCode(),
            $missing->getStatusCode(),
            'A cross-entity id and a non-existent id must not be distinguishable.'
        );
        $this->assertSame(403, $crossEntity->getStatusCode());
        $this->assertSame($crossEntity->json('message'), $missing->json('message'));
    }

    public function test_cashier_sees_masked_patient_name_on_invoice_render(): void
    {
        $template = $this->phiProbeTemplate();

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get("/api/v1/invoices/{$this->invoiceA->id}/html?template={$template->id}");

        $response->assertOk();
        $this->assertStringNotContainsString('Jane Doe', $response->getContent());
        $this->assertStringContainsString('J******e', $response->getContent());
    }

    public function test_user_with_phi_permission_sees_patient_name_on_invoice_render(): void
    {
        $template = $this->phiProbeTemplate();

        $response = $this->actingAs($this->manager)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get("/api/v1/invoices/{$this->invoiceA->id}/html?template={$template->id}");

        $response->assertOk();
        $this->assertStringContainsString('Jane Doe', $response->getContent());
    }

    public function test_receipt_render_requires_the_accounting_view_permission(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get("/api/v1/payments/{$this->paymentA->id}/pdf");

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'accounting.view');
    }

    public function test_purchase_order_render_requires_the_inventory_view_permission(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get('/api/v1/purchase-orders/99999999/pdf');

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'inventory.view');
    }

    public function test_delivery_order_render_requires_the_sales_view_permission(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get('/api/v1/delivery-orders/99999999/pdf');

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'sales.view');
    }

    public function test_customer_statement_render_requires_the_customers_view_permission(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get('/api/v1/customer-statements/99999999/pdf');

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'customers.view');
    }

    // ─── Issue #618: shared print/send menu resolution preview ──────────────

    private function entityScopedTemplate(string $documentType, string $name): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'code' => 'RES-'.Str::random(8),
            'name' => $name,
            'mode' => DocumentTemplate::MODE_BLOCK,
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
            'layout_config' => [
                'blocks' => [
                    ['type' => 'text', 'content' => 'Resolved template'],
                ],
            ],
        ]);

        DocumentTemplateAssignment::create([
            'document_type' => $documentType,
            'template_id' => $template->id,
            'scope_type' => 'entity',
            'scope_value' => (string) $this->entityA,
            'priority' => 20,
            'is_active' => true,
        ]);

        return $template;
    }

    public function test_resolution_reports_the_resolved_template_and_rule_for_a_document(): void
    {
        $template = $this->entityScopedTemplate('invoice', 'Entity A Invoice');

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->getJson("/api/v1/documents/invoice/resolution?document_id={$this->invoiceA->id}");

        $response->assertOk();
        $response->assertJsonPath('data.template_id', $template->id);
        $response->assertJsonPath('data.template_name', 'Entity A Invoice');
        $response->assertJsonPath('data.rule.scope_type', 'entity');
        $response->assertJsonPath('data.rule_label', 'entity rule');
    }

    public function test_resolution_reuses_the_source_documents_permission_and_entity_scope(): void
    {
        $this->entityScopedTemplate('invoice', 'Entity A Invoice');

        // The cashier holds document.view (route gate) but not accounting.view,
        // so receipt resolution must be denied exactly like receipt rendering.
        $receiptDenied = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->getJson("/api/v1/documents/receipt/resolution?document_id={$this->paymentA->id}");

        $receiptDenied->assertStatus(403);
        $receiptDenied->assertJsonPath('required_permission', 'accounting.view');

        // Cross-entity invoice resolution answers like a missing document.
        $crossEntity = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->getJson("/api/v1/documents/invoice/resolution?document_id={$this->invoiceB->id}");

        $crossEntity->assertStatus(403);
    }

    public function test_resolution_accepts_explicit_context_for_documents_not_persisted_yet(): void
    {
        $template = $this->entityScopedTemplate('customer_statement', 'Entity A Statement');

        $response = $this->actingAs($this->manager)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->getJson("/api/v1/documents/customer_statement/resolution?entity_id={$this->entityA}");

        $response->assertOk();
        $response->assertJsonPath('data.template_id', $template->id);
        $response->assertJsonPath('data.template_name', 'Entity A Statement');
        $response->assertJsonPath('data.rule_label', 'entity rule');
    }
}
