<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #746 T13: a product pinned to an expired tax rule must follow its successor
 * (same treatment) — never silently fall through to the tenant default or a
 * "newest rule" that may have a different treatment. Without a successor the
 * line resolves blocked, and posting is stopped, rather than charging a
 * rate the tenant never configured for that product.
 */
class ExpiredTaxRuleSuccessorTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@expiredrule.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rule(array $attributes): Tax
    {
        return Tax::create(array_merge([
            'name' => 'Test rule', 'code' => 'TEST-'.Str::random(6), 'type' => 'gst',
            'regime' => 'GST', 'jurisdiction' => 'SG', 'scope' => 'both',
            'treatment' => 'standard_rated', 'rate' => 0, 'is_active' => true,
        ], $attributes));
    }

    private function productPinnedTo(int $ruleId): Product
    {
        return Product::create([
            'name' => 'Pinned product', 'sku' => 'PIN-'.Str::random(6),
            'is_active' => true, 'tax_id' => $ruleId, 'track_inventory' => false,
        ]);
    }

    public function test_expired_rule_follows_successor_keeping_zero_rating(): void
    {
        // The dangerous fallback: a live standard-rated rule the engine would
        // otherwise silently pick for a zero-rated product (G-24 prefers
        // standard-rated rules).
        $this->rule([
            'name' => 'Standard 9%', 'code' => 'TEST-STD', 'rate' => 9,
            'treatment' => 'standard_rated', 'effective_from' => '2024-01-01',
        ]);

        $zeroRated = $this->rule([
            'name' => 'Zero-rated', 'code' => 'TEST-ZR', 'rate' => 0,
            'treatment' => 'zero_rated', 'effective_from' => '2024-01-01',
        ]);
        $product = $this->productPinnedTo($zeroRated->id);

        // A real replacement through the platform's versioning path (identity
        // change on a live rule) — this is the "standard rate changes" event.
        $versioned = $this->putJson("/api/v1/taxes/{$zeroRated->id}", ['scope' => 'output']);
        $versioned->assertOk()->assertJsonPath('versioned', true);
        $newId = (int) $versioned->json('id');

        $old = Tax::query()->findOrFail($zeroRated->id);
        $this->assertSame($newId, (int) $old->superseded_by_id, 'The closed rule must name its successor.');

        $engine = app(TaxEngineService::class);
        $today = now()->toDateString();

        $resolved = $engine->resolve($product, 'SG', 'output', null, $today);
        $this->assertSame('zero_rated', $resolved['treatment'], 'A zero-rated product must keep zero rating via its successor, not pick up the standard rate.');
        $this->assertSame($newId, (int) $resolved['tax_id'], 'The resolution must come from the successor rule.');
        $this->assertSame('product_successor', $resolved['source']);

        // History is untouched: a document dated before the change still
        // resolves the rule effective on its date.
        $past = $engine->resolve($product, 'SG', 'output', null, now()->subDay()->toDateString());
        $this->assertSame($zeroRated->id, (int) $past['tax_id']);
    }

    public function test_expired_rule_without_successor_resolves_blocked_and_stops_posting(): void
    {
        $this->rule([
            'name' => 'Standard 9%', 'code' => 'TEST-STD2', 'rate' => 9,
            'treatment' => 'standard_rated', 'effective_from' => '2024-01-01',
        ]);

        $expired = $this->rule([
            'name' => 'Expired zero-rated', 'code' => 'TEST-ZR-OLD', 'rate' => 0,
            'treatment' => 'zero_rated', 'effective_from' => '2024-01-01', 'effective_to' => '2024-12-31',
        ]);
        $product = $this->productPinnedTo($expired->id);

        $resolved = app(TaxEngineService::class)->resolve($product, 'SG', 'output', null, now()->toDateString());
        $this->assertTrue((bool) ($resolved['blocked'] ?? false), 'No successor must resolve blocked, never a silent fallback.');
        $this->assertStringContainsString('TAX_RULE_EXPIRED_NO_SUCCESSOR', (string) ($resolved['warning'] ?? ''));
        $this->assertNotSame('standard_rated', $resolved['treatment'], 'The standard-rate fallback must not apply.');

        // Real posting path: the blocked determination stops the posting.
        $customer = Customer::factory()->create();
        DB::table('customer_addresses')->insert([
            'customer_id' => $customer->id, 'type' => 'billing', 'country' => 'SG',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => '2025-07-01',
            'due_date' => '2025-07-31',
            'lines' => [[
                'product_id' => $product->id, 'description' => 'Pinned product',
                'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 0,
            ]],
        ])->assertCreated();
        $invoiceId = (int) $create->json('id');

        $send = $this->postJson("/api/v1/invoices/{$invoiceId}/send");
        $this->assertGreaterThanOrEqual(400, $send->status(), 'A determination blocked by an expired rule must not post.');

        // The posting transaction rolls the determination back on the block,
        // so the status payload is asserted through the determination service
        // on the (still draft) invoice: it is TAX_RULE_EXPIRED with the
        // warning, not DETERMINED at a fallback rate.
        $determination = app(\App\Services\TaxDeterminationService::class)
            ->determineForInvoice(\App\Models\Invoice::findOrFail($invoiceId));
        $this->assertSame('TAX_RULE_EXPIRED', $determination['result_status'], 'The determination must carry the blocking status, not DETERMINED.');
        $this->assertSame(0.0, (float) $determination['tax_amount']);
        $this->assertStringContainsString('TAX_RULE_EXPIRED_NO_SUCCESSOR', implode(' ', (array) ($determination['warnings'] ?? [])));
    }

    public function test_expired_rule_products_report_flags_whether_a_successor_exists(): void
    {
        $successor = $this->rule([
            'name' => 'Zero-rated successor', 'code' => 'TEST-ZR-NEW', 'rate' => 0,
            'treatment' => 'zero_rated', 'effective_from' => '2025-01-01',
        ]);
        $withSuccessor = $this->rule([
            'name' => 'Zero-rated old', 'code' => 'TEST-ZR-OLD', 'rate' => 0,
            'treatment' => 'zero_rated', 'effective_from' => '2024-01-01', 'effective_to' => '2024-12-31',
            'superseded_by_id' => $successor->id,
        ]);
        $withoutSuccessor = $this->rule([
            'name' => 'Orphan expired', 'code' => 'TEST-ORPHAN', 'rate' => 0,
            'treatment' => 'zero_rated', 'effective_from' => '2024-01-01', 'effective_to' => '2024-12-31',
        ]);

        $productA = $this->productPinnedTo($withSuccessor->id);
        $productB = $this->productPinnedTo($withoutSuccessor->id);

        $response = $this->getJson('/api/v1/accounting/tax-engine/expired-rule-products')->assertOk();
        $rows = collect((array) $response->json('data'));

        $rowA = $rows->firstWhere('product_id', $productA->id);
        $rowB = $rows->firstWhere('product_id', $productB->id);
        $this->assertNotNull($rowA, 'A product pinned to an expired rule must be reported.');
        $this->assertTrue((bool) $rowA['has_successor']);
        $this->assertSame($successor->id, (int) $rowA['successor_rule_id']);

        $this->assertNotNull($rowB);
        $this->assertFalse((bool) $rowB['has_successor']);
    }
}
