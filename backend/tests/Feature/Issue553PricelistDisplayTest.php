<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Tests\TenantTestCase;

/**
 * Issue #553 (SALES-ODOO): the pricelist display mode decides whether a
 * document prints the list price with the discount broken out, or the net
 * price with the discount already included. Presentation only — the stored
 * line figures never change.
 *
 * These are router-level tests: an invoice is rendered through the real
 * GET /invoices/{id}/html route (router → middleware → controller → data
 * source → renderer) under both display modes.
 */
class Issue553PricelistDisplayTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Pricelist Display Tenant',
            'email' => $this->tenantId.'@example.test',
        ]));

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

        $this->customer = Customer::factory()->create(['name' => 'Display Customer']);
        $this->product = Product::factory()->create([
            'track_inventory' => false,
            'name' => 'Discounted Widget',
            'price' => 100,
            'cost' => 60,
        ]);
    }

    public function test_net_price_mode_changes_the_rendered_lines_and_leaves_stored_data_untouched(): void
    {
        $invoice = $this->invoiceWithDiscountedLine();
        $this->publishedTemplate(
            'invoice',
            '<div>UNIT {{ lines.0.unit_price | round:2 }} DISC {{ lines.0.discount | round:2 }}</div>',
        );

        // Default mode: list price with the discount broken out.
        $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$invoice->id}/html")
            ->assertOk()
            ->assertSee('UNIT 100.00 DISC 10.00', false);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/sales', ['pricelist' => ['display' => 'net_price']])
            ->assertOk();

        // Net-price mode: the unit price already includes the discount, so the
        // discount breaks out as zero.
        $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$invoice->id}/html")
            ->assertOk()
            ->assertSee('UNIT 90.00 DISC 0.00', false);

        // Presentation only: the stored line keeps its list price + discount.
        $line = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->first();
        $this->assertSame(100.0, (float) $line->unit_price, 'stored unit price must not change');
        $this->assertSame(10.0, (float) $line->discount_amount, 'stored discount must not change');
        $this->assertSame('net_price', Setting::getValue('sales.pricelist.display'));
    }

    public function test_show_discount_mode_is_the_default_and_keeps_list_prices(): void
    {
        $invoice = $this->invoiceWithDiscountedLine();
        $this->publishedTemplate(
            'invoice',
            '<div>UNIT {{ lines.0.unit_price | round:2 }} DISC {{ lines.0.discount | round:2 }}</div>',
        );

        $this->actingAs($this->admin)
            ->getJson('/api/v1/settings/sales')
            ->assertOk()
            ->assertJsonPath('data.pricelist.display', 'show_discount');

        $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$invoice->id}/html")
            ->assertOk()
            ->assertSee('UNIT 100.00 DISC 10.00', false);
    }

    private function invoiceWithDiscountedLine(): Invoice
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'subtotal' => 90,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 90,
            'paid_amount' => 0,
            'balance' => 90,
            'created_by' => $this->admin->id,
        ]);

        $invoice->lines()->create([
            'line_type' => 'product',
            'product_id' => $this->product->id,
            'description' => 'Discounted widget',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 10,
            'tax_rate' => 0,
        ]);

        return $invoice->fresh(['lines']);
    }

    private function publishedTemplate(string $documentType, string $html): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => 'Display '.$documentType.' Template',
            'mode' => DocumentTemplate::MODE_CODE,
            'html' => $html,
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
            'template_type_code' => null,
            'template_id' => $template->id,
            'scope_type' => 'tenant',
            'scope_value' => null,
            'priority' => 10,
            'is_active' => true,
        ]);

        return $template;
    }
}
