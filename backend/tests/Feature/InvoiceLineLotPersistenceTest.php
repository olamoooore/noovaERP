<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue: selecting a lot in the invoice line item editor must persist to the
 * invoice lines (lot number, batch link, expiry) and survive the SalesOrder
 * lifecycle path used for new invoices, which historically dropped them.
 */
class InvoiceLineLotPersistenceTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'Invoice Lot Tenant',
                'email' => 'inv-lot-'.substr(md5($this->tenantId), 0, 8).'@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContextWithPermissions(
            $this->tenantId,
            null,
            UserRole::ADMIN->spatieName(),
            autoAssignRole: false,
        );
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => UserRole::ADMIN->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole(UserRole::ADMIN->spatieName());

        return $user;
    }

    public function test_lot_selection_persists_on_new_invoice_lines(): void
    {
        $admin = $this->actingAsAdmin();

        $warehouse = Warehouse::create([
            'name' => 'Lot Warehouse',
            'code' => 'LOT-'.uniqid(),
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 100000]);

        $product = Product::factory()->create([
            'name' => 'Lot Tracked Widget',
            'sku' => 'LOTW-'.uniqid(),
            'price' => 500,
            'cost' => 200,
            'track_inventory' => true,
            'tracking_method' => 'batch',
        ]);

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_number' => '2607S08',
            'quantity' => 96,
            'quantity_reserved' => 0,
            'expiry_date' => '2029-06-07',
            'status' => ProductBatch::STATUS_AVAILABLE,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 2,
                'unit_price' => 500,
                'tax_rate' => 9,
                'discount_amount' => 0,
                'lot_number' => '2607S08',
                'product_batch_id' => $batch->id,
                'expiry_date' => '2029-06-07',
            ]],
        ]);

        $response->assertCreated();

        $invoice = Invoice::query()->with('lines')->findOrFail($response->json('id'));
        $line = $invoice->lines->first();

        $this->assertNotNull($line, 'Invoice should have a line.');
        $this->assertSame('2607S08', $line->lot_number, 'The selected lot number must persist on the invoice line.');
        $this->assertSame($batch->id, (int) $line->product_batch_id, 'The batch link must persist on the invoice line.');
        $this->assertSame('2029-06-07', $line->expiry_date?->toDateString(), 'The expiry date must persist on the invoice line.');
    }

    public function test_lot_selection_persists_when_editing_a_draft_invoice(): void
    {
        $admin = $this->actingAsAdmin();

        $warehouse = Warehouse::create([
            'name' => 'Lot Warehouse Edit',
            'code' => 'LOTE-'.uniqid(),
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 100000]);

        $product = Product::factory()->create([
            'name' => 'Lot Tracked Widget Edit',
            'sku' => 'LOTWE-'.uniqid(),
            'price' => 500,
            'cost' => 200,
            'track_inventory' => true,
            'tracking_method' => 'batch',
        ]);

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_number' => '2607S08',
            'quantity' => 96,
            'quantity_reserved' => 0,
            'expiry_date' => '2029-06-07',
            'status' => ProductBatch::STATUS_AVAILABLE,
        ]);

        // Create the invoice first without a lot (the operator picks it later in edit).
        $create = $this->actingAs($admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 2,
                'unit_price' => 500,
                'tax_rate' => 9,
                'discount_amount' => 0,
            ]],
        ]);
        $create->assertCreated();
        $invoiceId = (int) $create->json('id');

        // Now edit the draft and select the lot, exactly like the invoice form.
        $update = $this->actingAs($admin)->putJson("/api/v1/invoices/{$invoiceId}", [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 2,
                'unit_price' => 500,
                'tax_rate' => 9,
                'discount_amount' => 0,
                'lot_number' => '2607S08',
                'product_batch_id' => $batch->id,
                'expiry_date' => '2029-06-07',
            ]],
        ]);
        $update->assertOk();

        $line = Invoice::query()->findOrFail($invoiceId)->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame('2607S08', $line->lot_number, 'Editing a draft must keep the selected lot number.');
        $this->assertSame($batch->id, (int) $line->product_batch_id, 'Editing a draft must keep the batch link.');
        $this->assertSame('2029-06-07', $line->expiry_date?->toDateString(), 'Editing a draft must keep the expiry date.');
    }
}
