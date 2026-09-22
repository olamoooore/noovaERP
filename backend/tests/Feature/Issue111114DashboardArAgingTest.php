<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issues #111/#114 — the dashboard never returned ar_aging (frontend always
 * rendered hardcoded zero buckets) and never computed outstanding_delta_pct
 * (the AR Outstanding trend badge never appeared).
 */
class Issue111114DashboardArAgingTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '11111411-2233-4455-6677-889900112233',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['invoices', 'customers', 'payments']);

        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create(['is_active' => true]);
    }

    private function invoice(string $invoiceDate, string $dueDate, float $total, float $balance, string $status = 'sent'): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'status' => $status,
            'lifecycle_status' => 'posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => $total - $balance,
            'balance' => $balance,
            'created_by' => $this->user->id,
        ]);
    }

    private function dashboard(): array
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/dashboard?range=30d');
        $response->assertStatus(200, $response->getContent());

        return $response->json();
    }

    public function test_ar_aging_buckets_are_derived_from_real_invoices(): void
    {
        $today = now();

        // One invoice per bucket (due_date determines the bucket).
        $this->invoice($today->copy()->toDateString(), $today->copy()->addDays(10)->toDateString(), 10, 10);   // current
        $this->invoice($today->copy()->subDays(40)->toDateString(), $today->copy()->subDays(20)->toDateString(), 20, 20); // 1-30
        $this->invoice($today->copy()->subDays(60)->toDateString(), $today->copy()->subDays(45)->toDateString(), 30, 30); // 31-60
        $this->invoice($today->copy()->subDays(90)->toDateString(), $today->copy()->subDays(75)->toDateString(), 40, 40); // 61-90
        $this->invoice($today->copy()->subDays(150)->toDateString(), $today->copy()->subDays(120)->toDateString(), 50, 50); // 90+

        $stats = $this->dashboard();

        $this->assertIsArray($stats['ar_aging'] ?? null, 'The dashboard must return ar_aging.');
        $buckets = collect($stats['ar_aging'])->pluck('amount', 'bucket');

        $this->assertSame(10.0, round((float) $buckets['Current'], 2));
        $this->assertSame(20.0, round((float) $buckets['1 - 30 days'], 2));
        $this->assertSame(30.0, round((float) $buckets['31 - 60 days'], 2));
        $this->assertSame(40.0, round((float) $buckets['61 - 90 days'], 2));
        $this->assertSame(50.0, round((float) $buckets['90+ days'], 2));

        $this->assertSame(150.0, round((float) $stats['outstanding_invoices']['amount'], 2));
    }

    public function test_outstanding_delta_pct_compares_against_the_range_start(): void
    {
        $today = now();

        // Prior outstanding: an invoice dated before the 30-day range start.
        $this->invoice($today->copy()->subDays(90)->toDateString(), $today->copy()->subDays(60)->toDateString(), 100, 100);

        // New business inside the range.
        $this->invoice($today->copy()->toDateString(), $today->copy()->addDays(30)->toDateString(), 50, 50);

        $stats = $this->dashboard();

        $this->assertSame(150.0, round((float) $stats['outstanding_invoices']['amount'], 2));
        $this->assertSame(50.0, (float) $stats['outstanding_delta_pct'], 'Current 150 vs prior 100 -> +50%.');
    }

    public function test_outstanding_delta_pct_is_null_without_prior_outstanding(): void
    {
        $this->invoice(now()->toDateString(), now()->addDays(30)->toDateString(), 50, 50);

        $stats = $this->dashboard();

        $this->assertNull($stats['outstanding_delta_pct']);
    }

    public function test_voided_invoices_are_excluded_from_outstanding_and_aging(): void
    {
        $invoice = $this->invoice(now()->subDays(10)->toDateString(), now()->subDays(5)->toDateString(), 80, 80);
        $invoice->update(['lifecycle_status' => 'voided']);

        $stats = $this->dashboard();

        $this->assertSame(0.0, round((float) $stats['outstanding_invoices']['amount'], 2));
        $this->assertSame(
            0.0,
            round(collect($stats['ar_aging'])->sum('amount'), 2),
            'Voided documents must not appear in any aging bucket.'
        );
    }
}
