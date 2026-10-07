<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Support\DefaultTemplates;
use Tests\TenantTestCase;

/**
 * Issue #621 (TPL-UX4) — merge-field catalog gaps on the invoice:
 * per-line FOC marker + FOC list value, per-line medical case number, linked
 * SO/DO numbers per line, and the e-invoice status; the default invoice
 * template renders FOC lines as "FOC" with the list value struck through.
 *
 * Until #560 adds `invoice_lines.is_foc`, the FOC flag comes from the live
 * medical case line that bills the invoice line (`medical_case_items.is_foc`),
 * the same source the #600 analytics read model uses.
 *
 * Router-level: router → middleware → controller → data source → renderer.
 */
class InvoiceLineMergeFieldsTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Line Merge Fields Tenant',
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

        $customer = Customer::factory()->create(['name' => 'Hospital 621']);
        $paidProduct = Product::factory()->create(['price' => 100]);
        $focProduct = Product::factory()->create(['price' => 120]);

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-621-0001',
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
        ]);
        $paidSoLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Paid implant',
            'product_id' => $paidProduct->id,
            'quantity' => 2,
            'unit_price' => 100,
        ]);
        $focSoLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Bonus implant',
            'product_id' => $focProduct->id,
            'quantity' => 1,
            'unit_price' => 120,
        ]);

        $deliveryOrder = DeliveryOrder::create([
            'delivery_order_number' => 'DO-621-0001',
            'sales_order_id' => $order->id,
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
        ]);
        $paidDoLine = DeliveryOrderLine::create([
            'delivery_order_id' => $deliveryOrder->id,
            'sales_order_line_id' => $paidSoLine->id,
            'product_id' => $paidProduct->id,
            'description' => 'Paid implant',
            'quantity_ordered' => 2,
            'quantity_delivered' => 2,
            'unit_price' => 100,
        ]);

        $this->invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'sales_order_id' => $order->id,
            'invoice_number' => 'INV-621-0001',
            'case_number' => 'HEADER-CASE',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'created_by' => $this->admin->id,
        ]);
        $this->invoice->deliveryOrders()->attach($deliveryOrder->id);

        $paidLine = InvoiceLine::create([
            'invoice_id' => $this->invoice->id,
            'sales_order_line_id' => $paidSoLine->id,
            'product_id' => $paidProduct->id,
            'description' => 'Paid implant',
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);
        // FOC line: list price 120, fully discounted, net 0.
        $focLine = InvoiceLine::create([
            'invoice_id' => $this->invoice->id,
            'sales_order_line_id' => $focSoLine->id,
            'product_id' => $focProduct->id,
            'description' => 'Bonus implant',
            'quantity' => 1,
            'unit_price' => 120,
            'discount_amount' => 120,
            'tax_rate' => 0,
        ]);

        $case = MedicalCase::create([
            'case_no' => 'CASE-621-0001',
            'customer_id' => $customer->id,
            'case_date' => '2026-09-10',
            'invoice_id' => $this->invoice->id,
            'status' => 'invoiced',
            'created_by' => $this->admin->id,
        ]);
        MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $paidSoLine->id,
            'invoice_item_id' => $paidLine->id,
            'delivery_order_item_id' => $paidDoLine->id,
            'product_id' => $paidProduct->id,
            'allocated_qty' => 2,
            'delivered_qty' => 2,
            'invoiced_qty' => 2,
            'unit_price' => 100,
            'is_foc' => false,
        ]);
        MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $focSoLine->id,
            'invoice_item_id' => $focLine->id,
            'product_id' => $focProduct->id,
            'allocated_qty' => 1,
            'delivered_qty' => 1,
            'invoiced_qty' => 1,
            'unit_price' => 0,
            'is_foc' => true,
        ]);
    }

    public function test_invoice_catalog_lists_the_line_and_einvoice_tokens(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/template-builder/merge-fields/invoice');

        $response->assertOk();
        $tokens = collect($response->json('data'))->flatMap(fn ($group) => collect($group['fields'])->pluck('token'))->all();

        foreach (['lines.is_foc', 'lines.list_value', 'lines.case_number', 'lines.so_number', 'lines.do_number', 'einvoice.status'] as $token) {
            $this->assertContains($token, $tokens, "The invoice merge-field catalog must offer '{$token}'.");
        }
    }

    public function test_line_tokens_resolve_from_the_linked_case_so_and_do(): void
    {
        $template = $this->probeTemplate(
            'L0|{{ lines.0.is_foc }}|{{ lines.0.list_value }}|{{ lines.0.case_number }}|{{ lines.0.so_number }}|{{ lines.0.do_number }}'
            .' L1|{{ lines.1.is_foc }}|{{ lines.1.list_value }}|{{ lines.1.case_number }}|{{ lines.1.so_number }}'
        );

        $response = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$this->invoice->id}/html?template={$template->id}");

        $response->assertOk();
        $html = (string) $response->getContent();

        // Paid line: not FOC, no FOC list value, its own case/SO/DO numbers.
        $this->assertStringContainsString('L0||0|CASE-621-0001|SO-621-0001|DO-621-0001', $html);
        // FOC line: flagged, list value = quantity × list price (1 × 120).
        $this->assertStringContainsString('L1|1|120|CASE-621-0001|SO-621-0001', $html);
    }

    public function test_default_invoice_template_prints_foc_lines_as_foc_with_the_list_value_struck_through(): void
    {
        $definition = DefaultTemplates::invoice();
        $template = DocumentTemplate::create(array_merge($definition, [
            'code' => 'INV-621-'.Str::random(6),
            'is_system_default' => false,
            'published_version' => 0,
        ]));

        $response = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$this->invoice->id}/html?template={$template->id}");

        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertMatchesRegularExpression('/<s>[^<]*120\.00<\/s>\s*FOC/u', $html, 'The FOC line must show its list value struck through, then "FOC".');
        // The paid line keeps its normal amount, not struck through.
        $this->assertDoesNotMatchRegularExpression('/<s>[^<]*200\.00<\/s>/u', $html);
        $this->assertSame(1, substr_count($html, '<s>'), 'Only the FOC line is struck through.');
    }

    private function probeTemplate(string $body): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => 'invoice',
            'name' => 'Line Probe '.Str::random(6),
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
