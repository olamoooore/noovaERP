<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Analytics\SalesAnalyticsService;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #594 (SALES-RPT, P2, RP5): the trend series returned only periods with
 * sales, so the chart joined non-adjacent days, `growth_rate` compared against
 * the previous NON-EMPTY period (Monday vs the previous Friday read as
 * day-on-day growth), and `lowest_revenue_period` could never be a zero day.
 * Weekly periods use `IYYY-IW` (`2026-39`) which the frontend rendered raw.
 *
 * Every test drives the real HTTP route (router → middleware → controller →
 * SalesAnalyticsService); the summary-only assertions call the service directly
 * because the trends endpoint does not expose the summary envelope.
 */
class Issue594SalesTrendsZeroFillTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Sales Trends 594 Tenant');
        CompanySettings::query()->create(['base_currency' => 'MYR']);
        $this->customer = Customer::factory()->create(['name' => '594 Customer']);
        $this->product = Product::factory()->create([
            'name' => '594 Widget',
            'sku' => '594-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
    }

    private function saleOn(string $date, float $total, string $channel = 'admin'): SalesOrder
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-594-'.uniqid(),
            'customer_id' => $this->customer->id,
            'channel' => $channel,
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'order_date' => $date,
            'created_by' => $this->admin->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'description' => '594 line',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return $order;
    }

    private function posSaleAt(string $timestampUtc, float $total): PosOrder
    {
        $order = PosOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'paid',
            'is_return' => false,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'currency' => 'MYR',
            'paid_at' => Carbon::parse($timestampUtc, 'UTC'),
            'payment_method' => 'cash',
        ]);

        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return $order;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function trends(string $query): \Illuminate\Support\Collection
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/trends?'.$query);
        $response->assertOk();

        return collect($response->json('data.trends') ?? []);
    }

    /**
     * @return array<int, float>
     */
    private function revenues(\Illuminate\Support\Collection $rows): array
    {
        return $rows->map(fn ($row) => round((float) ($row['revenue'] ?? 0), 2))
            ->values()
            ->all();
    }

    /**
     * Acceptance: every day in the range gets a row. The gap day is a zero row,
     * growth for it compares against the immediately preceding day, and the day
     * after a zero day reports no growth rate instead of comparing across the
     * gap to the last non-empty day.
     */
    public function test_daily_trend_zero_fills_gap_days_and_growth_is_adjacent(): void
    {
        $this->saleOn('2026-10-01', 100);
        $this->saleOn('2026-10-03', 200);

        $rows = $this->trends('start_date=2026-10-01&end_date=2026-10-03&interval=day');

        $this->assertSame(
            ['2026-10-01', '2026-10-02', '2026-10-03'],
            $rows->pluck('period')->all(),
            'The series must contain one row per day, including days with no sales.'
        );
        $this->assertSame([100.0, 0.0, 200.0], $this->revenues($rows));

        $this->assertNull($rows[0]['growth_rate'], 'The first period has no preceding period.');
        $this->assertSame(
            -100.0,
            (float) $rows[1]['growth_rate'],
            'The zero day compares with the immediately preceding day, not with the last non-empty day.'
        );
        $this->assertNull(
            $rows[2]['growth_rate'],
            'Growth from a zero period is undefined and must never be computed across the gap (+100% before the fix).'
        );
    }

    /**
     * Acceptance: summary counts the zero-filled periods and the lowest
     * revenue period can be a zero day.
     */
    public function test_summary_counts_zero_filled_periods_and_lowest_can_be_zero(): void
    {
        $this->saleOn('2026-10-01', 100);
        $this->saleOn('2026-10-03', 200);

        $report = app(SalesAnalyticsService::class)->getSalesTrends(new ReportFilter(
            startDate: Carbon::parse('2026-10-01'),
            endDate: Carbon::parse('2026-10-03'),
        ), 'day');

        $this->assertCount(3, $report['data'], 'Zero days are part of the series.');
        $this->assertSame(3, (int) $report['summary']['total_periods']);
        $this->assertSame(
            '2026-10-02',
            $report['summary']['lowest_revenue_period'],
            'The lowest-revenue period is the zero day, not the quietest non-empty day.'
        );
        $this->assertSame('2026-10-03', $report['summary']['highest_revenue_period']);
        $this->assertSame(
            100.0,
            (float) $report['summary']['average_revenue_per_period'],
            'The average is over the zero-filled periods (300 / 3), not over the non-empty rows (300 / 2).'
        );
    }

    /**
     * Acceptance: weekly periods are zero-filled too and stay in `IYYY-IW`
     * form for the frontend to label.
     */
    public function test_weekly_trend_zero_fills_missing_iso_weeks(): void
    {
        // ISO weeks 39 (Mon 21 Sep), 40 (Mon 28 Sep) and 41 (Mon 5 Oct) 2026.
        $this->saleOn('2026-09-22', 100);
        $this->saleOn('2026-10-06', 200);

        $rows = $this->trends('start_date=2026-09-21&end_date=2026-10-11&interval=week');

        $this->assertSame(
            ['2026-39', '2026-40', '2026-41'],
            $rows->pluck('period')->all(),
            'Every ISO week in the range is present, including the empty week 40.'
        );
        $this->assertSame([100.0, 0.0, 200.0], $this->revenues($rows));
        $this->assertNull($rows[2]['growth_rate'], 'Week 41 follows an empty week, so growth is undefined.');
    }

    /**
     * Acceptance: monthly periods are zero-filled as well.
     */
    public function test_monthly_trend_zero_fills_missing_months(): void
    {
        $this->saleOn('2026-01-15', 100);
        $this->saleOn('2026-03-10', 200);

        $rows = $this->trends('start_date=2026-01-01&end_date=2026-03-31&interval=month');

        $this->assertSame(['2026-01', '2026-02', '2026-03'], $rows->pluck('period')->all());
        $this->assertSame([100.0, 0.0, 200.0], $this->revenues($rows));
    }

    /**
     * Acceptance: periods are built in the tenant timezone. A POS sale at
     * 18:00 UTC on 30 Sep is 02:00 on 1 Oct in Kuala Lumpur and belongs to the
     * 1 Oct period; a sale at 18:00 UTC on 1 Oct (02:00 on 2 Oct locally)
     * falls outside a 1 Oct report.
     */
    public function test_trend_periods_are_built_in_the_tenant_timezone(): void
    {
        CompanySettings::query()->update(['timezone' => 'Asia/Kuala_Lumpur']);

        $this->posSaleAt('2026-09-30 18:00:00', 100);
        $this->posSaleAt('2026-10-01 18:00:00', 200);

        $rows = $this->trends('start_date=2026-10-01&end_date=2026-10-01&interval=day');

        $this->assertSame(['2026-10-01'], $rows->pluck('period')->all());
        $this->assertSame(
            100.0,
            round((float) $rows[0]['revenue'], 2),
            'Only the sale whose tenant-local date is 1 Oct may count: 18:00 UTC on 30 Sep (+08:00 → 1 Oct) counts, 18:00 UTC on 1 Oct (+08:00 → 2 Oct) does not.'
        );
    }
}
