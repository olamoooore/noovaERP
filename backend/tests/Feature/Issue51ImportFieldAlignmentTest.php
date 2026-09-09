<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\AccountingImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime verification for issue #51 — accounting import fields must align
 * with the canonical Bill/Invoice/Payment $fillable shape (no silently
 * dropped total_amount / payment_method / status / name fields), creation
 * must route through the owning domain where one exists, and the import
 * mutation routes must be permission-gated.
 */
class Issue51ImportFieldAlignmentTest extends TenantTestCase
{
    protected string $tenantUuid;
    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Import Test Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'plan' => 'trial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantUuid);
        $this->seed(RolePermissionSeeder::class);

        $this->writer = User::factory()->create();
        $this->writer->givePermissionTo('accounting.write');
    }

    private function service(): AccountingImportService
    {
        return app(AccountingImportService::class);
    }

    /**
     * AC2 (bill): a bill row carrying legacy `vendor_name` + `total_amount`
     * commits to a real Bill whose `total` (canonical fillable) is correct and
     * whose vendor was resolved — i.e. the amount is NOT silently dropped.
     */
    public function test_bill_import_retains_resolved_vendor_and_total(): void
    {
        $batch = $this->service()->stageBatch(
            [[
                'vendor_name' => 'Legacy Vendor '.uniqid(),
                'bill_number' => 'BILL51-'.strtoupper(Str::random(6)),
                'total_amount' => '1500.00',
                'tax_amount' => '0',
                'bill_date' => now()->toDateString(),
            ]],
            'bill',
            'bills.csv',
            hash('sha256', 'bills.csv'),
            $this->writer->id
        );
        $this->assertSame('validated', $batch->status);

        $this->service()->commitBatch($batch->id, $this->writer->id);

        $bill = Bill::where('bill_number', $batch->stagingRows()->first()->raw_data['bill_number'] ?? null)
            ->first()
            ?? Bill::latest('id')->first();

        $this->assertNotNull($bill);
        $this->assertEqualsWithDelta(1500.00, (float) $bill->total, 0.01,
            'Bill.total must retain the imported total_amount (was being dropped pre-#51)');
        $this->assertNotNull($bill->vendor_id, 'Vendor must be resolved for the imported bill');
    }

    /**
     * AC2 (invoice): legacy customer_name + total_amount commits to an Invoice
     * with correct total + resolved customer (via CanonicalCustomerService).
     */
    public function test_invoice_import_retains_resolved_customer_and_total(): void
    {
        $batch = $this->service()->stageBatch(
            [[
                'customer_name' => 'Legacy Customer '.uniqid(),
                'invoice_number' => 'INV51-'.strtoupper(Str::random(6)),
                'total_amount' => '1200.00',
                'tax_amount' => '0',
                'invoice_date' => now()->toDateString(),
            ]],
            'invoice',
            'invoices.csv',
            hash('sha256', 'invoices.csv'),
            $this->writer->id
        );

        $this->service()->commitBatch($batch->id, $this->writer->id);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertEqualsWithDelta(1200.00, (float) $invoice->total, 0.01,
            'Invoice.total must retain the imported total_amount');
        $this->assertNotNull($invoice->customer_id, 'Customer must be resolved for the imported invoice');
    }

    /**
     * AC2 (payment): legacy `payment_method` maps to the canonical `method`,
     * and status is persisted as payment_status (draft). Pre-#51 both keys were
     * non-fillable-mismatched and silently dropped.
     */
    public function test_payment_import_maps_method_and_status(): void
    {
        $customer = \App\Models\Customer::firstOrCreate(
            ['name' => 'Payment Customer '.uniqid()],
            ['name' => 'Payment Customer '.uniqid()]
        );

        $batch = $this->service()->stageBatch(
            [[
                'payment_number' => 'PAY51-'.strtoupper(Str::random(6)),
                'amount' => '750.00',
                'currency' => 'SGD',
                'payment_method' => 'cash', // legacy alias → canonical `method`
                'payment_date' => now()->toDateString(),
                'customer_id' => (string) $customer->id,
            ]],
            'payment',
            'payments.csv',
            hash('sha256', 'payments.csv'),
            $this->writer->id
        );

        $this->service()->commitBatch($batch->id, $this->writer->id);

        $payment = Payment::latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertSame('cash', $payment->method,
            "Payment.method must be set from legacy payment_method (was dropped pre-#51)");
        $this->assertSame('draft', $payment->payment_status);
        $this->assertEqualsWithDelta(750.00, (float) $payment->amount, 0.01);
    }

    /**
     * AC4: the accounting/import commit route is gated by accounting.write, and
     * a user WITHOUT that permission is blocked by the live RequirePermission
     * middleware (403).
     */
    public function test_import_commit_route_is_permission_gated(): void
    {
        // (a) Route introspection: the import group carries RequirePermission.
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/accounting/import/{batchId}/commit'
                && in_array('POST', $r->methods())
        );
        $this->assertNotNull($route, 'commit route must exist');
        $hasGate = collect($route->gatherMiddleware())->contains(
            fn ($m) => is_string($m) && str_starts_with($m, 'App\Http\Middleware\RequirePermission:')
        );
        $this->assertTrue($hasGate, 'commit route must carry RequirePermission middleware');

        // (b) Live middleware: user without accounting.write → 403.
        $noPermUser = User::factory()->create(); // no accounting.write

        $this->actingAs($noPermUser, 'sanctum');
        $request = \Illuminate\Http\Request::create('/test', 'POST');
        $request->setUserResolver(fn () => $noPermUser);

        $response = app(\App\Http\Middleware\RequirePermission::class)
            ->handle($request, fn ($r) => response()->json(['ok' => true]), 'accounting.write');

        $this->assertSame(403, $response->getStatusCode(),
            'A user without accounting.write must be denied on the import commit route');
    }
}
