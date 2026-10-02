<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #538 (ST1): DocumentNumberingService always used a per-month counter,
 * so the default year-only format INV-{YYYY}-{SEQ:4} restarted at 0001 every
 * month, collided with January's numbers and fell back to an unchecked
 * timestamp suffix. The counter scope now follows the format, collisions
 * advance the counter past the issued numbers, and there is no timestamp
 * fallback.
 */
class DocumentNumberingSequenceTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) \Illuminate\Support\Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createInvoice(): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_year_only_format_continues_the_sequence_into_february(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        // More than the model's 10 retry attempts, so the old per-month counter
        // exhausted every 0001-0010 attempt in February and fell back to the
        // unchecked INV-2026-<minute><second> number.
        $januaryNumbers = [];
        for ($i = 1; $i <= 11; $i++) {
            $januaryNumbers[] = $this->createInvoice()->invoice_number;
        }

        $this->assertSame('INV-2026-0001', $januaryNumbers[0], 'January starts the year at 0001');
        $this->assertSame('INV-2026-0011', $januaryNumbers[10], 'January keeps counting up');

        Carbon::setTestNow('2026-02-10 10:00:00');
        $february = $this->createInvoice();

        $this->assertSame('INV-2026-0012', $february->invoice_number, 'a year-only format must not reset in February');
    }

    public function test_month_format_resets_per_month_by_design(): void
    {
        app(\App\Services\DocumentNumberingService::class)->setFormat('invoice', 'INV/{YYYY}/{MM}/{SEQ:3}');

        Carbon::setTestNow('2026-01-15 10:00:00');
        $january = $this->createInvoice();

        Carbon::setTestNow('2026-02-10 10:00:00');
        $february = $this->createInvoice();

        $this->assertSame('INV/2026/01/001', $january->invoice_number);
        $this->assertSame('INV/2026/02/001', $february->invoice_number, 'a {MM} format resets each month');
    }

    public function test_counter_advances_past_numbers_already_issued(): void
    {
        // Legacy state: numbers were issued but the year counter does not
        // exist (the old implementation only wrote monthly keys).
        Carbon::setTestNow('2026-03-05 10:00:00');

        foreach (['INV-2026-0007', 'INV-2026-0008'] as $existing) {
            DB::table('invoices')->insert([
                'invoice_number' => $existing,
                'customer_id' => $this->customer->id,
                'invoice_date' => '2026-01-10',
                'due_date' => '2026-02-09',
                'status' => 'draft',
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'balance' => 0,
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $next = $this->createInvoice();

        $this->assertSame('INV-2026-0009', $next->invoice_number, 'the counter must jump past the issued numbers');
    }

    public function test_check_command_reports_gaps_and_fallback_shaped_numbers(): void
    {
        Carbon::setTestNow('2026-05-01 12:00:00');

        // 0001, 0003 (gap at 0002) and 1045 — the shape the old timestamp
        // fallback (date('is')) produced, which leaves a huge gap behind it.
        foreach (['INV-2026-0001', 'INV-2026-0003', 'INV-2026-1045'] as $number) {
            DB::table('invoices')->insert([
                'invoice_number' => $number,
                'customer_id' => $this->customer->id,
                'invoice_date' => '2026-05-01',
                'due_date' => '2026-05-31',
                'status' => 'draft',
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'balance' => 0,
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $exitCode = \Illuminate\Support\Facades\Artisan::call('documents:check-numbering', ['--type' => 'invoice']);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exitCode, 'the data check is informational and exits successfully');
        $this->assertStringContainsString('INV-2026-0002', $output, 'the gap after 0001 is reported');
        $this->assertStringContainsString('gap after INV-2026-', $output);
        $this->assertStringContainsString('1 problems', $output, 'the fallback-shaped jump is counted as a numbering problem');
    }

    public function test_no_timestamp_fallback_when_counters_are_cleared(): void
    {
        Carbon::setTestNow('2026-04-01 12:34:56');

        Setting::query()->where('key', 'like', 'document_format.invoice.seq%')->delete();

        $first = $this->createInvoice();
        $second = $this->createInvoice();

        $this->assertSame('INV-2026-0001', $first->invoice_number);
        $this->assertSame('INV-2026-0002', $second->invoice_number);
        $this->assertStringNotContainsString('1234', (string) $second->invoice_number);
    }
}
