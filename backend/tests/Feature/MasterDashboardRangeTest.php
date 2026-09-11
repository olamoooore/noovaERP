<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\DashboardController;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class MasterDashboardRangeTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;

    public function test_revenue_total_and_daily_trend_follow_selected_range(): void
    {
        $this->travelTo('2026-08-18 12:00:00');
        $this->userId = User::factory()->create()->id;

        $this->invoice(100, now()->subDays(2)->toDateString());
        $this->invoice(200, now()->subDays(10)->toDateString());
        $this->invoice(300, now()->subDays(45)->toDateString());
        $this->invoice(400, now()->subDays(100)->toDateString());

        $sevenDays = $this->dashboard('7d');
        $thirtyDays = $this->dashboard('30d');
        $ninetyDays = $this->dashboard('90d');

        $this->assertSame(100.0, (float) $sevenDays['total_revenue']);
        $this->assertSame(300.0, (float) $thirtyDays['total_revenue']);
        $this->assertSame(600.0, (float) $ninetyDays['total_revenue']);
        $this->assertCount(7, $sevenDays['revenue_trend']);
        $this->assertCount(30, $thirtyDays['revenue_trend']);
        $this->assertCount(90, $ninetyDays['revenue_trend']);
        $this->assertSame('2026-08-12', $sevenDays['range_start']);
        $this->assertSame('2026-08-18', $sevenDays['range_end']);
    }

    private function dashboard(string $range): array
    {
        return app(DashboardController::class)
            ->index(Request::create('/dashboard', 'GET', ['range' => $range]))
            ->getData(true);
    }

    private function invoice(float $total, string $date): void
    {
        Invoice::factory()->create([
            'invoice_date' => $date,
            'status' => 'paid',
            'total' => $total,
            'paid_amount' => $total,
            'balance' => 0,
            'created_by' => $this->userId,
        ]);
    }
}
