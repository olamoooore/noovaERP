<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * #1006: a bonus-scheme FOC line sits directly under the paid line it was
 * earned from — paid first, FOC second — linked to it, kept adjacent when the
 * draft is reordered and removed with its source line.
 */
class InvoiceFocLineOrderingTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(
            fn () => Tenant::factory()->create(['id' => '66666666-6666-6666-6666-666666666666'])
        );
        $this->bindTenantContext($tenant->id);

        $this->user = User::create([
            'name' => 'FOC Admin',
            'email' => 'admin-foc@test.local',
            'password' => bcrypt('password'),
        ]);

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create(['track_inventory' => false, 'price' => 100, 'cost' => 40]);
        $this->otherProduct = Product::factory()->create(['track_inventory' => false, 'price' => 50, 'cost' => 20]);
    }

    private function createInvoice(array $lines): Invoice
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-05-06',
            'lines' => $lines,
        ]);

        $response->assertCreated();

        return Invoice::findOrFail($response->json('id'));
    }

    private function linePayload(Product $product, string $description, float $quantity, float $unitPrice, bool $isFoc = false): array
    {
        return [
            'product_id' => $product->id,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'is_foc' => $isFoc,
        ];
    }

    /** @test */
    public function a_foc_line_submitted_before_its_paid_line_is_reordered_under_it()
    {
        $invoice = $this->createInvoice([
            $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
            $this->linePayload($this->product, 'Paid units', 5, 100),
            $this->linePayload($this->otherProduct, 'Other product', 2, 50),
        ]);

        /** @var \Illuminate\Support\Collection<int, InvoiceLine> $lines */
        $lines = $invoice->lines()->get();

        // Paid line first, its FOC line directly beneath, other lines keep order.
        $this->assertSame(['Paid units', 'FOC bonus unit', 'Other product'], $lines->pluck('description')->all());
        $this->assertSame([false, true, false], $lines->map(fn (InvoiceLine $line) => (bool) $line->is_foc)->all());
        $this->assertSame([0, 1, 2], $lines->pluck('sequence')->map(fn ($s) => (int) $s)->all());

        $paid = $lines[0];
        $foc = $lines[1];
        $this->assertNull($paid->source_line_id);
        $this->assertSame($paid->id, (int) $foc->source_line_id);

        // Amounts untouched: 5 × 100 + 2 × 50 = 600; the FOC unit is free.
        $this->assertSame(600.0, (float) $invoice->fresh()->total);
        $this->assertSame(0.0, (float) $foc->line_total);
    }

    /** @test */
    public function the_pair_stays_adjacent_when_the_draft_is_reordered()
    {
        $invoice = $this->createInvoice([
            $this->linePayload($this->product, 'Paid units', 5, 100),
            $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
        ]);

        // Reorder with the FOC line first and another product before the pair.
        $this->actingAs($this->user)->putJson("/api/v1/invoices/{$invoice->id}", [
            'lines' => [
                $this->linePayload($this->otherProduct, 'Other product', 1, 50),
                $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
                $this->linePayload($this->product, 'Paid units', 5, 100),
            ],
        ])->assertOk();

        $lines = $invoice->fresh()->lines()->get();

        $this->assertSame(['Other product', 'Paid units', 'FOC bonus unit'], $lines->pluck('description')->all());
        $this->assertSame($lines[1]->id, (int) $lines[2]->source_line_id);
    }

    /** @test */
    public function removing_the_paid_line_removes_its_orphaned_foc_line()
    {
        $invoice = $this->createInvoice([
            $this->linePayload($this->product, 'Paid units', 5, 100),
            $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
        ]);

        $this->assertSame(1, InvoiceLine::where('invoice_id', $invoice->id)->where('is_foc', true)->count());

        // The paid line is gone but the FOC line is still in the payload.
        $this->actingAs($this->user)->putJson("/api/v1/invoices/{$invoice->id}", [
            'lines' => [
                $this->linePayload($this->otherProduct, 'Other product', 1, 50),
                $this->linePayload($this->product, 'FOC bonus unit', 1, 0, true),
            ],
        ])->assertOk();

        $lines = $invoice->fresh()->lines()->get();
        $this->assertSame(['Other product'], $lines->pluck('description')->all());
        $this->assertSame(0, InvoiceLine::where('invoice_id', $invoice->id)->where('is_foc', true)->count());
    }
}
