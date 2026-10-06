<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #492 (PAY-UX6): `recorded` is the status of BOTH hand-typed drafts and
 * imported drafts, so the status label cannot describe the source. Each
 * payment must carry its own source (manual / import) so the UI can show it
 * separately from the "Draft (not yet posted)" status.
 *
 * Exercises the real router/middleware/controller chain via the HTTP API.
 */
class PaymentDraftSourceTest extends TenantTestCase
{
    protected User $admin;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'code' => 'CUST-'.strtoupper(Str::random(6)),
        ]);
    }

    private function openInvoice(float $total = 500): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_manually_recorded_draft_is_marked_as_manual(): void
    {
        $invoice = $this->openInvoice();

        $id = $this->actingAs($this->admin)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 100,
                'method' => 'cash',
            ])
            ->assertCreated()
            ->json('id');

        $payment = Payment::findOrFail($id);
        $this->assertSame('recorded', $payment->payment_status);
        $this->assertSame('manual', $payment->source);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/payments/'.$id)
            ->assertOk()
            ->assertJsonPath('source', 'manual');
    }

    public function test_imported_draft_is_marked_as_import(): void
    {
        $invoice = $this->openInvoice(1000);

        $csv = "payment_no,customer_code,invoice_no,payment_date,amount,method,reference\n";
        $csv .= ",{$this->customer->code},{$invoice->invoice_number},".now()->toDateString().",400.00,bank_transfer,TXN492\n";
        $path = tempnam(sys_get_temp_dir(), 'payimp');
        file_put_contents($path, $csv);

        $this->actingAs($this->admin)
            ->post('/api/v1/payments/import', [
                'file' => new UploadedFile($path, 'payments.csv', 'text/csv', null, true),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('created', 1);

        $payment = Payment::where('reference', 'TXN492')->firstOrFail();
        $this->assertSame('recorded', $payment->payment_status);
        $this->assertSame('import', $payment->source);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/payments?payment_status=recorded')
            ->assertOk()
            ->assertJsonPath('data.0.source', 'import');
    }
}
