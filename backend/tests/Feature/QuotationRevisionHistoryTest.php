<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #336 (Q12) — quotation Reject / New revision and visible version
 * history.
 *
 * `POST /quotations/{id}/reject` (#178/#179) already voids the original and
 * creates an auto-versioned replacement draft in the same correction group,
 * but nothing on the quotation read path exposes the earlier revisions, so the
 * detail page cannot show "v1, v2 …" or how the totals changed between them.
 *
 * Acceptance criterion under test: every version of the quotation (v1, v2 …)
 * is listed on the quotation read, in version order, with each version's
 * totals and the change in total against the previous version — visible from
 * any version in the chain. Router-level (repo SOP §3): reject and show both go
 * through the real HTTP route chain.
 */
class QuotationRevisionHistoryTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'quotations', 'quotation_lines', 'customers', 'products', 'document_correction_links',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
    }

    private function quotation(): Quotation
    {
        $quotation = Quotation::create([
            'quotation_number' => 'QT-336-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'status' => 'sent',
            'subtotal' => 200,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 200,
            'created_by' => $this->user->id,
        ]);

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'description' => 'Q336 line',
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => 200,
        ]);

        return $quotation;
    }

    private function reject(int $id, string $code, string $text): int
    {
        $response = $this->actingAs($this->user)->postJson("/api/v1/quotations/{$id}/reject", [
            'reason_code' => $code,
            'reason_text' => $text,
        ]);
        $response->assertStatus(201);

        return (int) $response->json('quotation.id');
    }

    public function test_quotation_read_lists_every_version_with_total_changes(): void
    {
        $v1 = $this->quotation();

        $v2Id = $this->reject($v1->id, 'customer_rejected', 'Customer wants a lower price');
        // The revised draft is re-priced before being re-issued.
        Quotation::whereKey($v2Id)->update(['subtotal' => 180, 'total' => 180]);

        $v3Id = $this->reject($v2Id, 'revision', 'Add installation');
        Quotation::whereKey($v3Id)->update(['subtotal' => 230, 'tax_amount' => 20, 'total' => 250]);

        // Visible from the newest version …
        $history = $this->actingAs($this->user)
            ->getJson("/api/v1/quotations/{$v3Id}")
            ->assertOk()
            ->json('version_history');

        $this->assertIsArray($history, 'The quotation read must expose its version history.');
        $this->assertCount(3, $history);
        $this->assertSame([1, 2, 3], array_map(fn ($v) => (int) $v['version_number'], $history));
        $this->assertSame([$v1->id, $v2Id, $v3Id], array_map(fn ($v) => (int) $v['id'], $history));
        $this->assertSame(['cancelled', 'cancelled', 'draft'], array_column($history, 'status'));
        $this->assertSame([false, false, true], array_map(fn ($v) => (bool) $v['is_active_version'], $history));

        $this->assertEqualsWithDelta(200.0, (float) $history[0]['total'], 0.001);
        $this->assertEqualsWithDelta(180.0, (float) $history[1]['total'], 0.001);
        $this->assertEqualsWithDelta(250.0, (float) $history[2]['total'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $history[2]['tax_amount'], 0.001);

        // Diff of totals: each version against the one it replaced.
        $this->assertNull($history[0]['total_change'], 'v1 has no previous version to compare against.');
        $this->assertEqualsWithDelta(-20.0, (float) $history[1]['total_change'], 0.001);
        $this->assertEqualsWithDelta(70.0, (float) $history[2]['total_change'], 0.001);

        // The reason each revision was created is shown with it.
        $this->assertSame('customer_rejected', $history[1]['correction_reason_code']);
        $this->assertSame('Customer wants a lower price', $history[1]['correction_reason_text']);
        $this->assertSame('revision', $history[2]['correction_reason_code']);

        // … and from the superseded original, which must point at the same chain.
        $fromOriginal = $this->actingAs($this->user)
            ->getJson("/api/v1/quotations/{$v1->id}")
            ->assertOk()
            ->json('version_history');
        $this->assertSame([$v1->id, $v2Id, $v3Id], array_map(fn ($v) => (int) $v['id'], $fromOriginal));
    }

    public function test_a_never_revised_quotation_has_an_empty_version_history(): void
    {
        $quotation = $this->quotation();

        $this->actingAs($this->user)
            ->getJson("/api/v1/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('version_history', []);
    }

    public function test_version_history_does_not_leak_other_correction_groups(): void
    {
        $a = $this->quotation();
        $b = $this->quotation();

        $aV2 = $this->reject($a->id, 'revision', 'A revised');
        $this->reject($b->id, 'revision', 'B revised');

        $history = $this->actingAs($this->user)
            ->getJson("/api/v1/quotations/{$aV2}")
            ->assertOk()
            ->json('version_history');

        $this->assertSame([$a->id, $aV2], array_map(fn ($v) => (int) $v['id'], $history));
    }
}
