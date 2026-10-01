<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Services\PosPostingService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #268 acceptance: a PARTIAL POS return must move exactly the returned tax on
 * the return period (e.g. half of an 18.00 output tax sale → −9.00), never the
 * whole document's tax; full returns keep the full reversal.
 */
class PosPartialReturnTaxTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([
            'cash' => ['P268-CASH', 'asset'],
            'sales_revenue' => ['P268-REV', 'revenue'],
            'sales_tax_payable' => ['P268-TAX', 'liability'],
            'cogs' => ['P268-COGS', 'expense'],
            'inventory' => ['P268-INV', 'asset'],
        ] as $key => [$code, $type]) {
            $account = ChartOfAccount::create([
                'code' => $code, 'name' => "P268 {$key}", 'type' => $type,
                'account_type' => $type, 'is_active' => true,
            ]);
            AccountMapping::create(['mapping_key' => $key, 'account_id' => $account->id, 'is_active' => true]);
        }

        Tax::create([
            'name' => 'P268 GST 9', 'code' => 'P268-GST-9', 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => 'SG', 'scope' => 'output', 'treatment' => 'standard_rated',
            'rate' => 9, 'is_active' => true,
        ]);
    }

    private function makeOrder(): PosOrder
    {
        $product = Product::create([
            'name' => 'P268 Product', 'sku' => 'P268-'.Str::random(6),
            'is_active' => true, 'track_inventory' => false,
        ]);

        $terminal = PosTerminal::create(['name' => 'P268 T', 'code' => 'P268T-'.Str::random(5), 'is_active' => true]);
        $session = PosSession::create([
            'terminal_id' => $terminal->id, 'user_id' => $this->user->id,
            'session_number' => 'P268S-'.Str::random(5), 'status' => 'open',
            'opening_balance' => 0, 'opened_at' => now(),
        ]);

        // Qty 2 × 109.00 (100 net + 9 tax) = 218.00, output tax 18.00.
        $order = PosOrder::create([
            'external_id' => Str::uuid()->toString(),
            'order_number' => 'P268-'.Str::random(8),
            'session_id' => $session->id,
            'status' => 'draft',
            'subtotal' => 200,
            'tax_amount' => 18,
            'total' => 218,
            'created_by' => $this->user->id,
        ]);

        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'P268 Product',
            'sku' => $product->sku,
            'quantity' => 2,
            'unit_price' => 100,
            'tax_rate' => 9,
            'tax_amount' => 18,
            'line_total' => 218,
        ]);

        return $order->load('items.product', 'items.variant');
    }

    public function test_partial_return_reverses_only_the_returned_tax(): void
    {
        $order = $this->makeOrder();
        $entry = app(PosPostingService::class)->postPaidOrder($order, 218.00, 'cash');
        $this->assertNotNull($entry);

        $item = $order->items->first();
        $returnLines = [['item_id' => $item->id, 'quantity' => 1]];
        $returnOrder = $order->createReturn($returnLines, 'partial test');

        $reversal = app(PosPostingService::class)->reverseOrder($order, $returnOrder, 'partial test', $returnLines);

        $this->assertNotNull($reversal, 'The partial return must post a reversal entry.');

        $reversalTax = (float) DB::table('tax_transaction_details')
            ->where('journal_entry_id', $reversal->id)
            ->sum('tax_amount');

        $this->assertEqualsWithDelta(-9.00, $reversalTax, 0.001, 'A half return must move −9.00 output tax, not −18.00.');

        $debit = (float) $reversal->lines()->sum('debit');
        $credit = (float) $reversal->lines()->sum('credit');
        $this->assertEqualsWithDelta($debit, $credit, 0.001, 'The partial reversal entry must balance.');

        $revenue = (float) $reversal->lines()
            ->where('account_id', (int) AccountMapping::where('mapping_key', 'sales_revenue')->value('account_id'))
            ->sum('debit');
        $this->assertEqualsWithDelta(100.00, $revenue, 0.01, 'Revenue must fall by the returned net only.');
    }

    public function test_full_return_still_reverses_the_whole_document(): void
    {
        $order = $this->makeOrder();
        $entry = app(PosPostingService::class)->postPaidOrder($order, 218.00, 'cash');

        $item = $order->items->first();
        $returnLines = [['item_id' => $item->id, 'quantity' => 2]];
        $returnOrder = $order->createReturn($returnLines, 'full test');

        $reversal = app(PosPostingService::class)->reverseOrder($order, $returnOrder, 'full test', $returnLines);

        $this->assertNotNull($reversal);

        $reversalTax = (float) DB::table('tax_transaction_details')
            ->where('journal_entry_id', $reversal->id)
            ->sum('tax_amount');

        $this->assertEqualsWithDelta(-18.00, $reversalTax, 0.001, 'A full return must reverse the entire output tax.');
    }
}
