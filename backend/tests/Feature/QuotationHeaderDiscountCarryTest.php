<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #291: a quotation's HEADER discount must carry quote → Sales Order → Invoice.
 *
 * Worked example (line 10 × 100 = 1,000 net, 10% tax, 10% header discount):
 *   net subtotal        = 1,000
 *   header discount      =   100   (10% of the 1,000 gross line subtotal)
 *   taxable after disc.  =   900
 *   tax (pro-rated)      =    90   (100 line tax × 900/1000)
 *   document total       =   990
 *
 * The quotation screen/engine already produces 990. Before this fix the Sales
 * Order dropped the discount entirely (and double-counted tax), and the invoice
 * was raised at the full 1,100 — so an accepted 990 quote billed 1,100.
 *
 * Exercises the real router/middleware/controller chain via postJson.
 */
class QuotationHeaderDiscountCarryTest extends TenantTestCase
{
    protected User $admin;

    protected Customer $customer;

    protected Product $product;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = $this->makeUser('admin', UserRole::ADMIN);
        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->product = Product::factory()->create([
            'name' => 'Discount Widget',
            'sku' => 'DW-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Warehouse::factory()->create(['is_default' => true]);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function makeDiscountedQuotation(): Quotation
    {
        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-'.uniqid(),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'created_by' => $this->admin->id,
        ]);

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 10,
            'unit_price' => 100,
            'tax_rate' => 10,
            'tax_inclusive' => false,
            'discount_amount' => 0,
            'line_total' => 0,
            'tax_amount' => 0,
        ]);

        $quotation->load('lines');
        $quotation->calculateTotals();

        return $quotation->fresh();
    }

    public function test_header_discount_carries_quote_to_sales_order_and_full_invoice(): void
    {
        $quotation = $this->makeDiscountedQuotation();

        // Sanity: the quotation itself prices to the worked example.
        $this->assertSame(1000.0, (float) $quotation->subtotal);
        $this->assertSame(100.0, (float) $quotation->discount_amount);
        $this->assertSame(90.0, (float) $quotation->tax_amount);
        $this->assertSame(990.0, (float) $quotation->total);

        // Quote → Sales Order (confirm).
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $order = DB::table('sales_orders')->where('id', $quotation->fresh()->sales_order_id)->first();
        $this->assertNotNull($order);
        $this->assertSame(
            990.0,
            (float) $order->total,
            'Sales Order total must equal the accepted quote total (header discount carried).',
        );
        $this->assertSame(100.0, (float) $order->discount_amount, 'Sales Order must carry the header discount.');

        // Sales Order → Invoice (full).
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        $invoice = DB::table('invoices')->where('quotation_id', $quotation->id)->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertSame(100.0, (float) $invoice->discount_amount, 'Invoice must carry the header discount.');
        $this->assertSame(
            990.0,
            (float) $invoice->total,
            'Invoice total must equal the accepted quote total for a full invoice.',
        );

        // Money path: posting the discounted invoice must keep the books
        // balanced, and AR must be the DISCOUNTED total (990), not 1,100.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $posted = \App\Models\Invoice::find($invoice->id);
        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->journal_entry_id, 'A journal entry must be posted.');

        $entry = \App\Models\JournalEntry::with('lines')->find($posted->journal_entry_id);
        $debits = round((float) $entry->lines->sum('debit'), 2);
        $credits = round((float) $entry->lines->sum('credit'), 2);
        $this->assertSame($debits, $credits, 'Journal entry must balance (debits == credits).');

        // The discount reached the ledger: AR is the discounted 990 (not 1,100)
        // and net revenue is 900 (not 1,000). (COGS/inventory lines also exist
        // and net to zero within the balanced entry.)
        $this->assertTrue(
            $entry->lines->contains(fn ($l) => round((float) $l->debit, 2) === 990.0),
            'AR debit must be the discounted invoice total (990), proving the discount reached AR.',
        );
        $this->assertTrue(
            $entry->lines->contains(fn ($l) => round((float) $l->credit, 2) === 900.0),
            'Net revenue credit must be 900 (1,000 less the 100 header discount).',
        );
        $this->assertFalse(
            $entry->lines->contains(fn ($l) => round((float) $l->debit, 2) === 1100.0
                || round((float) $l->credit, 2) === 1000.0),
            'No line may carry the pre-discount amounts (1,100 AR or 1,000 revenue).',
        );
    }
}
