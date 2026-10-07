<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #338 ([SALES-ODOO]): quotation templates, optional products and
 * margin display — driven through the real HTTP routes.
 *
 * Acceptance criteria:
 *  - Templates: create a quote from a template (lines, notes, terms, validity days).
 *  - Optional lines, shown separately and excluded from the total until accepted.
 *  - Margin column and total, visible only with the cost permission (`pricelists.cost`).
 */
class Issue338QuotationTemplatesOptionalMarginTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $widget;

    private Product $addon;

    private Tax $gst;

    private Tax $zeroRated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();
        $this->mockFeatureGate();

        $this->tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 338 Quotation Templates Tenant',
            'email' => 'issue338-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $this->admin = $this->userWithRole(UserRole::ADMIN);

        $this->customer = Customer::factory()->create([
            'email' => 'issue338-customer@example.com',
            'payment_terms' => 30,
            'credit_limit' => 1000000,
        ]);
        $this->widget = Product::factory()->create([
            'name' => 'Issue 338 Widget',
            'sku' => 'I338W-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
        $this->addon = Product::factory()->create([
            'name' => 'Issue 338 Add-on',
            'sku' => 'I338A-'.Str::upper(Str::random(6)),
            'price' => 50,
            'cost' => 30,
            'track_inventory' => false,
        ]);
        Warehouse::factory()->create(['is_default' => true]);

        // A real tax-pack row (SG GST 9%), taken verbatim from the shipped
        // pack config — the optional line carries it explicitly so the test
        // proves the optional line's TAX is also excluded until selected.
        $rules = [];
        foreach (['SG-GST-9', 'SG-GST-ZERO'] as $code) {
            $rule = collect((array) config('tax_packs.SG.rules', []))->firstWhere('code', $code);
            $rules[$code] = Tax::create([
                'name' => $rule['name'],
                'code' => $rule['code'],
                'type' => $rule['type'],
                'regime' => $rule['regime'],
                'jurisdiction' => $rule['jurisdiction'],
                'scope' => $rule['scope'],
                'treatment' => $rule['treatment'],
                'rate' => $rule['rate'],
                'is_inclusive' => (bool) $rule['is_inclusive'],
                'effective_from' => $rule['effective_from'],
                'effective_to' => $rule['effective_to'],
                'is_active' => true,
                'is_default' => false,
            ]);
        }
        $this->gst = $rules['SG-GST-9'];
        $this->zeroRated = $rules['SG-GST-ZERO'];

        $this->actingAs($this->admin);
    }

    // ── Templates ───────────────────────────────────────────────────────

    public function test_quotation_is_created_from_a_template_with_its_lines_notes_terms_and_validity(): void
    {
        $template = $this->postJson('/api/v1/quotation-templates', [
            'name' => 'Standard widget bundle',
            'notes' => 'Template notes',
            'terms' => 'Template terms: 50% upfront',
            'validity_days' => 21,
            'lines' => [
                ['line_type' => 'section', 'description' => 'Main items'],
                ['line_type' => 'product', 'product_id' => $this->widget->id, 'description' => 'Widget', 'quantity' => 2],
                ['line_type' => 'product', 'product_id' => $this->addon->id, 'description' => 'Optional add-on', 'quantity' => 1, 'is_optional' => true],
            ],
        ])->assertCreated();

        $templateId = (int) $template->json('data.id');
        $this->getJson('/api/v1/quotation-templates')->assertOk()->assertJsonPath('data.0.id', $templateId);

        $created = $this->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'quotation_template_id' => $templateId,
        ])->assertCreated();

        $quotation = Quotation::with('lines')->findOrFail((int) $created->json('data.id'));

        $this->assertSame('Template notes', $quotation->notes);
        $this->assertSame('Template terms: 50% upfront', $quotation->terms);
        $this->assertSame(now()->addDays(21)->toDateString(), Carbon::parse($quotation->valid_until)->toDateString());
        $this->assertSame($templateId, (int) $quotation->quotation_template_id);

        $lines = $quotation->lines->sortBy('sequence')->values();
        $this->assertCount(3, $lines);
        $this->assertSame('section', $lines[0]->line_type);
        // Template lines carry no price: the line is priced through the
        // PricingService like any other auto-priced quotation line.
        $this->assertEquals(100.0, (float) $lines[1]->unit_price);
        $this->assertFalse((bool) $lines[1]->is_optional);
        $this->assertEquals(50.0, (float) $lines[2]->unit_price);
        $this->assertTrue((bool) $lines[2]->is_optional);

        // The optional template line is not part of the quote total.
        $this->assertEquals(200.0, round((float) $quotation->subtotal, 2));
    }

    public function test_explicit_request_values_win_over_the_template(): void
    {
        $templateId = $this->makeTemplate();

        $created = $this->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'quotation_template_id' => $templateId,
            'notes' => 'Typed notes',
            'valid_until' => now()->addDays(5)->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->widget->id,
                'description' => 'Widget',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
            ]],
        ])->assertCreated();

        $quotation = Quotation::with('lines')->findOrFail((int) $created->json('data.id'));
        $this->assertSame('Typed notes', $quotation->notes);
        $this->assertSame('Template terms', $quotation->terms, 'Blank fields still take the template value.');
        $this->assertSame(now()->addDays(5)->toDateString(), Carbon::parse($quotation->valid_until)->toDateString());
        $this->assertCount(1, $quotation->lines, 'Explicit lines replace the template lines.');
    }

    public function test_quotation_requires_lines_or_a_template(): void
    {
        $this->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
        ])->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_template_management_requires_the_quotation_write_permission(): void
    {
        $viewer = $this->userWithRole(UserRole::VIEWER);
        $templateId = $this->makeTemplate();

        $this->actingAs($viewer);
        $this->getJson('/api/v1/quotation-templates')->assertOk();
        $this->postJson('/api/v1/quotation-templates', [
            'name' => 'Nope',
            'lines' => [['line_type' => 'note', 'description' => 'x']],
        ])->assertForbidden();
        $this->deleteJson("/api/v1/quotation-templates/{$templateId}")->assertForbidden();
    }

    // ── Optional lines ──────────────────────────────────────────────────

    public function test_optional_lines_are_excluded_from_totals_tax_and_the_released_order_and_invoice(): void
    {
        $created = $this->storeQuotationWithOptional()->assertCreated();
        $id = (int) $created->json('data.id');

        $quotation = Quotation::findOrFail($id);
        $this->assertEquals(200.0, round((float) $quotation->subtotal, 2), 'balance = sum of non-optional lines only');
        $this->assertEquals(0.0, round((float) $quotation->tax_amount, 2), 'The optional line\'s tax is not counted.');
        $this->assertEquals(200.0, round((float) $quotation->total, 2));

        $this->postJson("/api/v1/quotations/{$id}/confirm")->assertOk();

        $order = SalesOrder::with('lines')->findOrFail((int) Quotation::findOrFail($id)->sales_order_id);
        $this->assertCount(1, $order->lines, 'An unselected optional line never reaches the sales order.');
        $this->assertEquals(200.0, round((float) $order->total, 2));

        $this->postJson("/api/v1/quotations/{$id}/create-invoice")->assertSuccessful();
        $invoice = Invoice::with('lines')->where('quotation_id', $id)->firstOrFail();
        $this->assertCount(1, $invoice->lines, 'The invoice (and so any e-invoice payload) carries no optional line.');
        $this->assertEquals(200.0, round((float) $invoice->total, 2));
    }

    public function test_internal_edit_selects_an_optional_line_into_the_total(): void
    {
        $created = $this->storeQuotationWithOptional()->assertCreated();
        $id = (int) $created->json('data.id');

        $this->putJson("/api/v1/quotations/{$id}", ['lines' => [
            $this->productLine($this->widget, 2, 100, false),
            $this->productLine($this->addon, 1, 50, true, false),
        ]])->assertOk();

        $quotation = Quotation::findOrFail($id);
        $this->assertEquals(250.0, round((float) $quotation->subtotal, 2));
        $this->assertEquals(4.5, round((float) $quotation->tax_amount, 2));
        $this->assertEquals(254.5, round((float) $quotation->total, 2));
    }

    public function test_portal_shows_optional_lines_separately_and_the_customer_can_select_them(): void
    {
        Mail::fake();

        $created = $this->storeQuotationWithOptional()->assertCreated();
        $id = (int) $created->json('data.id');
        $this->postJson("/api/v1/quotations/{$id}/send")->assertOk();
        $quotation = Quotation::with('lines')->findOrFail($id);
        $token = $quotation->acceptance_token;
        $optionalLineId = (int) $quotation->lines->firstWhere('is_optional', true)->id;

        $show = $this->getJson("/api/v1/quotation-accept/{$token}")->assertOk();
        $this->assertCount(1, $show->json('lines'));
        $this->assertCount(1, $show->json('optional_lines'));
        $this->assertSame($optionalLineId, (int) $show->json('optional_lines.0.id'));
        $this->assertEquals(200.0, (float) $show->json('total'));

        // A line that is not an optional line of this quotation is refused.
        $mandatoryLineId = (int) $quotation->lines->firstWhere('is_optional', false)->id;
        $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
            'optional_line_ids' => [$mandatoryLineId],
        ])->assertStatus(422)->assertJsonValidationErrors('optional_line_ids');
        $this->assertNull($quotation->fresh()->accepted_at);

        $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
            'optional_line_ids' => [$optionalLineId],
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $quotation = Quotation::findOrFail($id);
        $this->assertEquals(254.5, round((float) $quotation->total, 2), 'The selected optional line now counts (50 + 9% GST).');

        $order = SalesOrder::with('lines')->findOrFail((int) $quotation->sales_order_id);
        $this->assertCount(2, $order->lines);
        $this->assertEquals(254.5, round((float) $order->total, 2));
    }

    // ── Margin ──────────────────────────────────────────────────────────

    public function test_margin_is_shown_only_to_roles_with_the_cost_permission(): void
    {
        $created = $this->storeQuotationWithOptional()->assertCreated();
        $id = (int) $created->json('data.id');

        // Manager holds pricelists.cost.
        $this->actingAs($this->userWithRole(UserRole::MANAGER));
        $response = $this->getJson("/api/v1/quotations/{$id}")->assertOk();

        // Widget: 2 × 100 revenue, 2 × 40 cost → margin 120 (60%).
        $widgetLine = collect($response->json('lines'))->firstWhere('product_id', $this->widget->id);
        $this->assertEquals(80.0, (float) $widgetLine['cost_amount']);
        $this->assertEquals(120.0, (float) $widgetLine['margin_amount']);
        $this->assertEquals(60.0, (float) $widgetLine['margin_percent']);

        // The optional line shows its own margin but is not in the document total.
        $addonLine = collect($response->json('lines'))->firstWhere('product_id', $this->addon->id);
        $this->assertEquals(20.0, (float) $addonLine['margin_amount']);

        $this->assertEquals(200.0, (float) $response->json('margin.revenue'));
        $this->assertEquals(80.0, (float) $response->json('margin.cost'));
        $this->assertEquals(120.0, (float) $response->json('margin.margin'));
        $this->assertEquals(60.0, (float) $response->json('margin.margin_percent'));

        // Sales role can read the quotation but not cost.
        $this->actingAs($this->userWithRole(UserRole::SALES));
        $masked = $this->getJson("/api/v1/quotations/{$id}")->assertOk();
        $this->assertNull($masked->json('margin'));
        foreach ($masked->json('lines') as $line) {
            $this->assertArrayNotHasKey('cost_amount', $line);
            $this->assertArrayNotHasKey('margin_amount', $line);
            $this->assertArrayNotHasKey('margin_percent', $line);
            if (is_array($line['product'] ?? null)) {
                $this->assertArrayNotHasKey('cost', $line['product'], 'Product cost is not leaked through the line product either.');
                $this->assertArrayNotHasKey('last_cost', $line['product']);
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function makeTemplate(): int
    {
        return (int) $this->postJson('/api/v1/quotation-templates', [
            'name' => 'Template '.Str::random(6),
            'notes' => 'Template notes',
            'terms' => 'Template terms',
            'validity_days' => 30,
            'lines' => [
                ['line_type' => 'product', 'product_id' => $this->widget->id, 'description' => 'Widget', 'quantity' => 3],
            ],
        ])->assertCreated()->json('data.id');
    }

    private function productLine(Product $product, float $qty, float $price, bool $taxed, bool $optional = false): array
    {
        return [
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => $qty,
            'unit_price' => $price,
            'tax_rule_id' => $taxed ? $this->gst->id : $this->zeroRated->id,
            'is_optional' => $optional,
        ];
    }

    private function storeQuotationWithOptional()
    {
        return $this->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [
                $this->productLine($this->widget, 2, 100, false),
                $this->productLine($this->addon, 1, 50, true, true),
            ],
        ]);
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role->value,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function mockFeatureGate(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => true,
                'source' => 'tier_included',
                'name' => strtoupper($key),
            ]);
        $mock->shouldReceive('all')->andReturn([['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']]);
        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        Feature::query()->updateOrCreate(
            ['key' => 'sales'],
            ['name' => 'Sales', 'description' => 'Sales module feature gate for tests', 'is_core' => false, 'price' => 0],
        );
    }
}
