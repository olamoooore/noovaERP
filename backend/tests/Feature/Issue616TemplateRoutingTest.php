<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\QuotationController;
use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Modules\TemplateBuilder\Models\GeneratedDocument;
use Tests\TenantTestCase;

/**
 * Issue #616 (SALES-TPL / TP6) — no parallel hard-coded renderers.
 *
 * - The quotation PDF is served only by the Template Platform route
 *   (/quotations/{id}/pdf); QuotationController::pdf() and its Blade view are
 *   deleted.
 * - The statement comparison PDF and the legacy generate path render through
 *   PdfGenerationService like the main statement path; the built-in layout is
 *   only the seeded default template, never a controller code path.
 *
 * These are real-route tests (router → middleware → controller → data source →
 * renderer).
 */
class Issue616TemplateRoutingTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '61661616-6161-6161-6161-616161616161',
        ]));
        $this->bindTenantContext($this->tenant->id);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
    }

    private function invoice(array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'settlement_status' => 'unpaid',
            'invoice_date' => '2026-01-10',
            'due_date' => '2026-02-10',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 1000,
            'paid_amount' => 0,
            'balance' => 1000,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    /**
     * A tenant-published customer_statement template. Its marker/version can
     * never come from the deleted hard-coded Blade layouts.
     */
    private function publishedStatementTemplate(): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => 'customer_statement',
            'name' => 'Issue 616 Tenant Statement',
            'mode' => DocumentTemplate::MODE_CODE,
            'html' => '<div>ISSUE-616-TENANT-TEMPLATE {{ customer.name }}</div>',
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 7,
        ]);

        $template->versions()->create([
            'template_id' => $template->id,
            'version' => 7,
            'html' => $template->html,
            'is_published' => true,
        ]);

        DocumentTemplateAssignment::create([
            'document_type' => 'customer_statement',
            'template_id' => $template->id,
            'scope_type' => 'tenant',
            'scope_value' => null,
            'priority' => 10,
            'is_active' => true,
        ]);

        return $template;
    }

    public function test_comparison_pdf_renders_through_the_tenant_template(): void
    {
        $this->invoice();
        $template = $this->publishedStatementTemplate();

        $response = $this->actingAs($this->user)->get(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement/compare/pdf'
            .'?dates=2026-01-15,2026-01-31'
        );

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // The comparison is persisted as a statement document so the render
        // pipeline can resolve and audit the template (the old path rendered
        // the Blade layout directly and persisted nothing).
        $statement = CustomerStatement::query()
            ->where('period_type', 'comparison')
            ->latest('id')
            ->firstOrFail();
        $this->assertNotEmpty($statement->payload['comparisons']);
        $this->assertNotEmpty($statement->payload['deltas']);

        $this->assertTrue(
            GeneratedDocument::query()
                ->where('document_type', 'customer_statement')
                ->where('document_id', (string) $statement->id)
                ->where('template_id', $template->id)
                ->where('template_version', 7)
                ->exists(),
            'The comparison PDF must be rendered by the tenant template through PdfGenerationService.'
        );

        // Content-level proof: the same comparison document rendered as HTML
        // must come out of the tenant template, not a hard-coded layout.
        $html = app(PdfGenerationServiceContract::class)
            ->render('customer_statement', (string) $statement->id, ['format' => 'html'])['html'];
        $this->assertStringContainsString('ISSUE-616-TENANT-TEMPLATE', $html);
        $this->assertStringContainsString((string) $this->customer->name, $html);
    }

    public function test_legacy_generate_renders_through_the_tenant_template(): void
    {
        $this->invoice();
        $template = $this->publishedStatementTemplate();

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement',
            [
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
            ]
        );

        $response->assertOk();

        $statement = CustomerStatement::findOrFail($response->json('id'));
        $this->assertNotNull($statement->pdf_path);
        $this->assertTrue(
            Storage::disk('public')->exists($statement->pdf_path),
            'The generated statement PDF must still be persisted.'
        );

        $this->assertTrue(
            GeneratedDocument::query()
                ->where('document_type', 'customer_statement')
                ->where('document_id', (string) $statement->id)
                ->where('template_id', $template->id)
                ->where('template_version', 7)
                ->exists(),
            'The legacy generate PDF must be rendered by the tenant template through PdfGenerationService.'
        );

        $stored = Storage::disk('public')->get($statement->pdf_path);
        $this->assertStringStartsWith('%PDF', $stored);
    }

    public function test_comparison_without_a_tenant_template_uses_the_seeded_default(): void
    {
        $this->invoice();

        $response = $this->actingAs($this->user)->get(
            '/api/v1/accounting/customers/'.$this->customer->id.'/statement/compare/pdf'
            .'?dates=2026-01-15,2026-01-31'
        );

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // No tenant template existed: the render lazily provisioned the
        // system default and used it — the built-in layout is a seeded default
        // template, not a controller fallback.
        $template = DocumentTemplate::forDocumentType('customer_statement')
            ->where('is_system_default', true)
            ->first();
        $this->assertNotNull($template, 'The system-default statement template must be provisioned on first render.');

        $statement = CustomerStatement::query()
            ->where('period_type', 'comparison')
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue(
            GeneratedDocument::query()
                ->where('document_type', 'customer_statement')
                ->where('document_id', (string) $statement->id)
                ->where('template_id', $template->id)
                ->exists(),
            'The comparison must render through the seeded default template via PdfGenerationService.'
        );

        // The seeded default template covers the comparison payload variant:
        // the comparison tables render from comparison_rows and the canonical
        // transaction blocks are skipped.
        $html = app(PdfGenerationServiceContract::class)
            ->render('customer_statement', (string) $statement->id, ['format' => 'html'])['html'];
        $this->assertStringContainsString('STATEMENT OF ACCOUNT — COMPARISON', $html);
        $this->assertStringContainsString('Outstanding Balance Summary', $html);
        $this->assertStringContainsString('Aging Breakdown', $html);
        $this->assertStringContainsString('2026-01-15', $html);
        $this->assertStringContainsString('2026-01-31', $html);
        $this->assertStringNotContainsString('ACCOUNT STATEMENT', $html);
    }

    public function test_quotation_pdf_still_serves_through_the_template_platform(): void
    {
        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        $quotation->lines()->create([
            'line_type' => 'product',
            'description' => 'Issue 616 quotation line',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        $response = $this->actingAs($this->user)->get('/api/v1/quotations/'.$quotation->id.'/pdf');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->assertTrue(
            GeneratedDocument::query()
                ->where('document_type', 'quotation')
                ->where('document_id', (string) $quotation->id)
                ->exists(),
            'The quotation PDF must be rendered by the Template Platform.'
        );
    }

    public function test_quotation_pdf_action_and_blade_view_are_gone(): void
    {
        $this->assertFalse(
            method_exists(QuotationController::class, 'pdf'),
            'QuotationController::pdf is a parallel hard-coded renderer and must be deleted.'
        );

        $this->assertFalse(
            View::exists('sales.quotation-pdf'),
            'The unrouted quotation Blade layout must be deleted.'
        );

        $controllerActions = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => (string) $route->getAction('controller'));

        $this->assertFalse(
            $controllerActions->contains(fn (string $action) => str_contains($action, 'QuotationController@pdf')),
            'No route may point at the deleted QuotationController::pdf action.'
        );
    }
}
