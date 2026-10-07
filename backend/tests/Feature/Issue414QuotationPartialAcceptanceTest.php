<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #414 (DX4): partial acceptance — the customer accepts a subset of the
 * quotation's lines on the public acceptance portal. Driven through the real
 * public route (`POST /api/v1/quotation-accept/{token}/accept`).
 *
 * Acceptance criteria:
 *  - Line-level acceptance recorded with signer and time; an order is created
 *    for the accepted lines; a revision is created for the remainder.
 *  - Totals and discounts recalculated through the totals path.
 *  - Ids that are not lines of this quotation → 422 with nothing recorded.
 */
class Issue414QuotationPartialAcceptanceTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $widget;

    private Product $addon;

    private Product $extra;

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
            'name' => 'Issue 414 Partial Acceptance Tenant',
            'email' => 'issue414-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $this->admin = $this->userWithRole(UserRole::ADMIN);

        $this->customer = Customer::factory()->create([
            'email' => 'issue414-customer@example.com',
            'payment_terms' => 30,
            'credit_limit' => 1000000,
        ]);
        $this->widget = Product::factory()->create([
            'name' => 'Issue 414 Widget',
            'sku' => 'I414W-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
        $this->addon = Product::factory()->create([
            'name' => 'Issue 414 Add-on',
            'sku' => 'I414A-'.Str::upper(Str::random(6)),
            'price' => 50,
            'cost' => 30,
            'track_inventory' => false,
        ]);
        $this->extra = Product::factory()->create([
            'name' => 'Issue 414 Extra',
            'sku' => 'I414E-'.Str::upper(Str::random(6)),
            'price' => 10,
            'cost' => 5,
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

    public function test_customer_accepts_a_subset_one_order_for_those_lines_and_the_remainder_becomes_an_open_revision(): void
    {
        Mail::fake();

        // Widget 2 × 100 (zero-rated), Add-on 1 × 50 (+9% GST), Extra 3 × 10
        // (+9% GST), with a 10% header discount.
        $id = $this->createQuotation(['discount_type' => 'percentage', 'discount_value' => 10]);
        [$token, $quotation] = $this->send($id);
        [$widgetLine, $addonLine, $extraLine] = $this->lineIds($quotation);

        $show = $this->getJson("/api/v1/quotation-accept/{$token}")->assertOk();
        $this->assertSame([$widgetLine, $addonLine, $extraLine], array_map('intval', array_column($show->json('lines'), 'id')),
            'The portal lists every line with the id the customer accepts it by.');

        $response = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
            'accepted_line_ids' => [$widgetLine, $addonLine],
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        // The accepted quotation: exactly the accepted lines, totals recomputed
        // (subtotal 250, 10% discount 25, tax 4.5 × 225/250 = 4.05).
        $accepted = Quotation::with('lines')->findOrFail($id);
        $this->assertSame('confirmed', $accepted->status);
        $this->assertEqualsCanonicalizing([$widgetLine, $addonLine], $accepted->lines->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertEquals(250.0, round((float) $accepted->subtotal, 2));
        $this->assertEquals(25.0, round((float) $accepted->discount_amount, 2));
        $this->assertEquals(4.05, round((float) $accepted->tax_amount, 2));
        $this->assertEquals(229.05, round((float) $accepted->total, 2));

        // Line-level acceptance with signer and time.
        $this->assertSame('Carla Customer', $accepted->signer_name);
        $this->assertSame('carla@example.com', $accepted->signer_email);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertEqualsCanonicalizing([$widgetLine, $addonLine], array_map('intval', (array) $response->json('accepted_line_ids')));

        // One sales order with exactly those lines and the same totals/tax.
        $orders = SalesOrder::with('lines')->where('id', (int) $accepted->sales_order_id)->get();
        $this->assertCount(1, $orders);
        $order = $orders->first();
        $this->assertSame((int) $order->id, (int) $response->json('sales_order_id'));
        $this->assertEqualsCanonicalizing(
            [$this->widget->id, $this->addon->id],
            $order->lines->pluck('product_id')->map(fn ($v) => (int) $v)->all(),
        );
        $this->assertEquals(229.05, round((float) $order->total, 2));
        $this->assertEquals(4.05, round((float) $order->tax_amount, 2));

        // The remainder: an open quotation version linked to the original,
        // holding only the unaccepted line, its totals recomputed
        // (subtotal 30, 10% discount 3, tax 2.7 × 27/30 = 2.43).
        $remainderId = (int) $response->json('remainder_quotation_id');
        $this->assertGreaterThan(0, $remainderId);
        $remainder = Quotation::with('lines')->findOrFail($remainderId);
        $this->assertSame('draft', $remainder->status);
        $this->assertNull($remainder->sales_order_id);
        $this->assertNull($remainder->accepted_at);
        $this->assertTrue((bool) $remainder->is_active_version);
        $this->assertSame((int) $id, (int) $remainder->original_document_id);
        $this->assertNotNull($remainder->correction_group_id);
        $this->assertSame($accepted->correction_group_id, $remainder->correction_group_id);
        $this->assertSame(2, (int) $remainder->version_number);
        $this->assertSame((int) $this->customer->id, (int) $remainder->customer_id);
        $this->assertSame([$this->extra->id], $remainder->lines->pluck('product_id')->map(fn ($v) => (int) $v)->all());
        $this->assertEquals(30.0, round((float) $remainder->subtotal, 2));
        $this->assertEquals(3.0, round((float) $remainder->discount_amount, 2));
        $this->assertEquals(2.43, round((float) $remainder->tax_amount, 2));
        $this->assertEquals(29.43, round((float) $remainder->total, 2));
        $this->assertSame($remainder->quotation_number, $response->json('remainder_quotation_number'));

        // The remainder stays open for follow-up: it shows in the original's
        // version history and is itself confirmable.
        $history = $this->getJson("/api/v1/quotations/{$id}")->assertOk()->json('version_history');
        $this->assertEqualsCanonicalizing([(int) $id, $remainderId], array_map('intval', array_column($history, 'id')));
        $this->postJson("/api/v1/quotations/{$remainderId}/confirm")->assertOk();
        $this->assertEquals(29.43, round((float) SalesOrder::findOrFail((int) Quotation::findOrFail($remainderId)->sales_order_id)->total, 2));

        // Issue #336: the confirmed original (live sales order) still cannot be rejected.
        $this->postJson("/api/v1/quotations/{$id}/reject", ['reason_text' => 'Try'])->assertStatus(422);
    }

    public function test_a_fixed_header_discount_is_split_between_the_accepted_lines_and_the_remainder(): void
    {
        Mail::fake();

        // Gross 200 + 50 + 30 = 280; fixed discount 28 → 25 on the accepted
        // 250 and 3 on the remaining 30 (never 28 twice).
        $id = $this->createQuotation(['discount_type' => 'fixed', 'discount_value' => 28]);
        [$token, $quotation] = $this->send($id);
        [$widgetLine, $addonLine] = $this->lineIds($quotation);

        $response = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
            'accepted_line_ids' => [$widgetLine, $addonLine],
        ])->assertOk();

        $accepted = Quotation::findOrFail($id);
        $remainder = Quotation::findOrFail((int) $response->json('remainder_quotation_id'));
        $this->assertEquals(25.0, round((float) $accepted->discount_amount, 2));
        $this->assertEquals(229.05, round((float) $accepted->total, 2));
        $this->assertEquals(3.0, round((float) $remainder->discount_amount, 2));
        $this->assertEquals(29.43, round((float) $remainder->total, 2));
        $this->assertEquals(229.05, round((float) SalesOrder::findOrFail((int) $accepted->sales_order_id)->total, 2));
    }

    public function test_ids_that_are_not_lines_of_this_quotation_are_refused_with_nothing_recorded(): void
    {
        Mail::fake();

        $id = $this->createQuotation();
        [$token, $quotation] = $this->send($id);
        [$widgetLine] = $this->lineIds($quotation);

        $otherId = $this->createQuotation();
        $foreignLine = (int) Quotation::with('lines')->findOrFail($otherId)->lines->first()->id;

        $quotationCount = Quotation::count();
        $orderCount = SalesOrder::count();

        foreach ([[$widgetLine, $foreignLine], [999999999], []] as $ids) {
            $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
                'signer_name' => 'Carla Customer',
                'signer_email' => 'carla@example.com',
                'accepted_line_ids' => $ids,
            ])->assertStatus(422)->assertJsonValidationErrors('accepted_line_ids');
        }

        $fresh = Quotation::with('lines')->findOrFail($id);
        $this->assertNull($fresh->accepted_at);
        $this->assertNull($fresh->signer_name);
        $this->assertSame('sent', $fresh->status);
        $this->assertCount(3, $fresh->lines);
        $this->assertCount(3, Quotation::with('lines')->findOrFail($otherId)->lines);
        $this->assertSame($quotationCount, Quotation::count(), 'No remainder revision is created.');
        $this->assertSame($orderCount, SalesOrder::count(), 'No sales order is created.');
    }

    public function test_prepayment_requirement_applies_to_the_accepted_subset(): void
    {
        Mail::fake();

        $id = $this->createQuotation(['require_payment' => true, 'prepayment_percent' => 50]);
        [$token, $quotation] = $this->send($id);
        [$widgetLine] = $this->lineIds($quotation);

        $response = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
            'accepted_line_ids' => [$widgetLine],
        ])->assertStatus(202)->assertJsonPath('requires_payment', true);

        // Only the accepted widget line (200, zero-rated) counts: 50% = 100.
        $this->assertEquals(100.0, (float) $response->json('prepayment_amount'));
        $this->assertEquals(200.0, round((float) Quotation::findOrFail($id)->total, 2));
        $this->assertNull(Quotation::findOrFail($id)->sales_order_id);

        $remainder = Quotation::with('lines')->findOrFail((int) $response->json('remainder_quotation_id'));
        $this->assertCount(2, $remainder->lines);
        $this->assertEquals(87.2, round((float) $remainder->total, 2), '50 + 30 + 9% GST on both.');
    }

    public function test_accepting_every_line_creates_no_remainder(): void
    {
        Mail::fake();

        $id = $this->createQuotation();
        [$token, $quotation] = $this->send($id);
        $count = Quotation::count();

        $response = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
            'accepted_line_ids' => $this->lineIds($quotation),
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $this->assertNull($response->json('remainder_quotation_id'));
        $this->assertSame($count, Quotation::count());
        $this->assertCount(3, SalesOrder::with('lines')->findOrFail((int) $response->json('sales_order_id'))->lines);
    }

    private function createQuotation(array $extra = []): int
    {
        return (int) $this->postJson('/api/v1/quotations', array_merge([
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'require_signature' => false,
            'lines' => [
                $this->productLine($this->widget, 2, 100, false),
                $this->productLine($this->addon, 1, 50, true),
                $this->productLine($this->extra, 3, 10, true),
            ],
        ], $extra))->assertCreated()->json('data.id');
    }

    /** @return array{0: string, 1: Quotation} */
    private function send(int $id): array
    {
        $this->postJson("/api/v1/quotations/{$id}/send")->assertOk();
        $quotation = Quotation::with('lines')->findOrFail($id);

        return [(string) $quotation->acceptance_token, $quotation];
    }

    /** @return array<int, int> */
    private function lineIds(Quotation $quotation): array
    {
        return $quotation->lines->sortBy('id')->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
    }

    private function productLine(Product $product, float $qty, float $price, bool $taxed): array
    {
        return [
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => $qty,
            'unit_price' => $price,
            'tax_rule_id' => $taxed ? $this->gst->id : $this->zeroRated->id,
        ];
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
