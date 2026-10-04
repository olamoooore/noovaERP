<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Modules\TemplateBuilder\Models\GeneratedDocument;
use Tests\TenantTestCase;

/**
 * Issue #613 (TP3) — a posted document must be reproducible exactly as it was
 * issued. Today every render resolves the CURRENT published template and
 * current company data, so editing a template after posting changes the
 * reprint that a customer/e-invoice submission already received.
 *
 * These are router-level tests (router → middleware → controller → posting
 * service → snapshot) so they fail through the real HTTP reprint/download
 * endpoints, not through direct service calls.
 */
class PostedDocumentImmutableSnapshotTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Invoice $invoice;

    private DocumentTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Immutable Snapshot Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::factory()->create(['name' => 'Snapshot Customer']);
        $product = Product::factory()->create([
            'track_inventory' => false,
            'name' => 'Snapshot Item',
            'price' => 100,
            'cost' => 60,
        ]);

        // A concrete, active invoice template so the post-posting edit is
        // unambiguous (and so the lazy default seeding does not take over).
        $this->template = $this->publishedTemplate('invoice', 'V1-MARKER');

        $created = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Snapshot line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $created->assertCreated();
        $this->invoice = Invoice::findOrFail($created->json('id'));
    }

    public function test_reprint_of_a_posted_invoice_is_byte_identical_after_the_template_changes(): void
    {
        $posted = $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$this->invoice->id}/post");
        $posted->assertOk();

        // The reprint/download of the posted document.
        $first = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$this->invoice->id}/pdf");
        $first->assertOk();
        $firstBytes = $first->getContent();
        $this->assertStringStartsWith('%PDF', $firstBytes);

        // The template is edited and re-published AFTER posting.
        $this->template->update(['html' => '<div class="snapshot">V2-MARKER</div>']);
        app(DocumentTemplateServiceContract::class)
            ->publish($this->template->id, 'changed after posting');

        $second = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$this->invoice->id}/pdf");
        $second->assertOk();

        $this->assertSame(
            hash('sha256', $firstBytes),
            hash('sha256', $second->getContent()),
            'A reprint of a posted document must be byte-identical to the copy issued at posting.'
        );

        // Posting must have stored an immutable, checksummed copy linked to
        // the document.
        $snapshot = GeneratedDocument::query()
            ->where('document_type', 'invoice')
            ->where('document_id', (string) $this->invoice->id)
            ->where('is_immutable', true)
            ->whereNull('superseded_at')
            ->firstOrFail();

        $this->assertNotNull($snapshot->checksum, 'The stored copy must carry a checksum.');
        $this->assertSame(hash('sha256', $firstBytes), $snapshot->checksum);
        $this->assertTrue(
            Storage::disk($snapshot->disk)->exists($snapshot->disk_path),
            'The issued PDF bytes must be stored on disk.'
        );
    }

    public function test_regenerate_with_current_template_creates_a_new_version_and_keeps_the_original(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$this->invoice->id}/post")
            ->assertOk();

        $original = GeneratedDocument::query()
            ->where('document_type', 'invoice')
            ->where('document_id', (string) $this->invoice->id)
            ->where('is_immutable', true)
            ->whereNull('superseded_at')
            ->firstOrFail();
        $originalBytes = (string) Storage::disk($original->disk)->get($original->disk_path);
        $this->assertSame(1, $original->template_version);

        // Change + republish the template, then explicitly regenerate.
        $this->template->update(['html' => '<div class="snapshot">V2-MARKER</div>']);
        app(DocumentTemplateServiceContract::class)
            ->publish($this->template->id, 'regenerate with v2');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/documents/invoice/{$this->invoice->id}/regenerate");
        $response->assertCreated();

        $regenerated = GeneratedDocument::findOrFail($response->json('id'));
        $this->assertNotSame($original->id, $regenerated->id);

        // The original is kept (superseded, file intact) — not overwritten.
        $original->refresh();
        $this->assertNotNull($original->superseded_at, 'The original copy must be retained when regenerating.');
        $this->assertTrue(Storage::disk($original->disk)->exists($original->disk_path));

        // The new version reflects the current template.
        $this->assertSame(2, $regenerated->template_version);
        $regeneratedBytes = (string) Storage::disk($regenerated->disk)->get($regenerated->disk_path);
        $this->assertSame(hash('sha256', $regeneratedBytes), $regenerated->checksum);
        $this->assertNotSame(
            hash('sha256', $originalBytes),
            hash('sha256', $regeneratedBytes),
            'Regenerating after a template change must produce new bytes.'
        );

        // Default download now serves the regenerated copy.
        $download = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$this->invoice->id}/pdf");
        $download->assertOk();
        $this->assertSame(
            hash('sha256', $regeneratedBytes),
            hash('sha256', $download->getContent())
        );
    }

    public function test_regenerate_requires_the_seeded_document_regenerate_permission(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$this->invoice->id}/post")
            ->assertOk();

        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');
        DB::table('user_company_access')->insert([
            'user_id' => $cashier->id,
            'tenant_id' => $this->tenantId,
            'role' => 'cashier',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A cashier can view invoices (invoices.view) but document.regenerate
        // is withheld (Seeder: OWNER/ADMIN/MANAGER only).
        $response = $this->actingAs($cashier)
            ->postJson("/api/v1/documents/invoice/{$this->invoice->id}/regenerate");

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'document.regenerate');
    }

    public function test_posted_receipt_is_also_reprinted_from_its_stored_copy(): void
    {
        $this->seedPostingFixtures();

        $receiptTemplate = $this->publishedTemplate('receipt', 'V1-RECEIPT');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$this->invoice->id}/post")
            ->assertOk();

        $created = $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $this->invoice->id,
            'amount' => 50,
            'method' => 'cash',
        ]);
        $created->assertCreated();
        $paymentId = $created->json('id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$paymentId}/post")
            ->assertOk();

        $snapshot = GeneratedDocument::query()
            ->where('document_type', 'receipt')
            ->where('document_id', (string) $paymentId)
            ->where('is_immutable', true)
            ->whereNull('superseded_at')
            ->firstOrFail();

        $first = $this->actingAs($this->admin)->get("/api/v1/payments/{$paymentId}/pdf");
        $first->assertOk();
        $this->assertSame(hash('sha256', $first->getContent()), $snapshot->checksum);

        // Template changes after posting must not alter the issued receipt.
        $receiptTemplate->update(['html' => '<div class="snapshot">V2-RECEIPT</div>']);
        app(DocumentTemplateServiceContract::class)
            ->publish($receiptTemplate->id, 'changed after posting');

        $second = $this->actingAs($this->admin)->get("/api/v1/payments/{$paymentId}/pdf");
        $second->assertOk();
        $this->assertSame(
            hash('sha256', $first->getContent()),
            hash('sha256', $second->getContent()),
            'A reprint of a posted receipt must be byte-identical to the issued copy.'
        );
    }

    public function test_confirmed_delivery_order_is_reprinted_from_its_stored_copy(): void
    {
        $orderTemplate = $this->publishedTemplate('delivery_order', 'V1-DO');

        $warehouse = Warehouse::create(['name' => 'Snapshot Warehouse']);
        $order = DeliveryOrder::create([
            'delivery_order_number' => DeliveryOrder::generateNumber(),
            'sales_order_id' => null,
            'customer_id' => $this->invoice->customer_id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'source' => 'standalone',
            'delivery_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$order->id}/confirm", [
                'method' => 'digital',
                'channel' => 'email',
            ])
            ->assertOk();

        $snapshot = GeneratedDocument::query()
            ->where('document_type', 'delivery_order')
            ->where('document_id', (string) $order->id)
            ->where('is_immutable', true)
            ->whereNull('superseded_at')
            ->firstOrFail();

        $first = $this->actingAs($this->admin)->get("/api/v1/delivery-orders/{$order->id}/pdf");
        $first->assertOk();
        $this->assertSame(hash('sha256', $first->getContent()), $snapshot->checksum);

        // Template changes after confirmation must not alter the issued DO.
        $orderTemplate->update(['html' => '<div class="snapshot">V2-DO</div>']);
        app(DocumentTemplateServiceContract::class)
            ->publish($orderTemplate->id, 'changed after confirmation');

        $second = $this->actingAs($this->admin)->get("/api/v1/delivery-orders/{$order->id}/pdf");
        $second->assertOk();
        $this->assertSame(
            hash('sha256', $first->getContent()),
            hash('sha256', $second->getContent()),
            'A reprint of a confirmed delivery order must be byte-identical to the issued copy.'
        );
    }

    private function publishedTemplate(string $documentType, string $marker): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => 'Snapshot '.$documentType.' Template',
            'mode' => DocumentTemplate::MODE_CODE,
            'html' => '<div class="snapshot">'.$marker.'</div>',
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);

        $template->versions()->create([
            'template_id' => $template->id,
            'version' => 1,
            'html' => $template->html,
            'is_published' => true,
        ]);

        DocumentTemplateAssignment::create([
            'document_type' => $documentType,
            'template_id' => $template->id,
            'scope_type' => 'tenant',
            'scope_value' => null,
            'priority' => 10,
            'is_active' => true,
        ]);

        return $template;
    }

    /**
     * GL fixtures a posted payment needs (mirrors PaymentDraftSettlementTest).
     */
    private function seedPostingFixtures(): void
    {
        $code = now()->format('Y-m');

        if (! DB::table('fiscal_periods')->where('code', $code)->exists()) {
            DB::table('fiscal_periods')->insert([
                'name' => now()->format('F Y'),
                'code' => $code,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['cash' => '1010', 'bank' => '1020', 'accounts_receivable' => '1100'] as $key => $accountCode) {
            if (DB::table('account_mappings')->where('mapping_key', $key)->whereNull('document_type')->exists()) {
                continue;
            }

            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => DB::table('chart_of_accounts')->where('code', $accountCode)->value('id'),
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
