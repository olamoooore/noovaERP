<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\SuperAdmin\Application\TaxProvenanceReport;
use PHPUnit\Framework\TestCase;

class TaxProvenanceClassificationTest extends TestCase
{
    private function classify(array $overrides): array
    {
        return TaxProvenanceReport::classify(array_merge([
            'rows' => 0,
            'max_burst_per_minute' => 0,
            'creators' => 1,
            'span_days' => 0,
            'days_since_last_activity' => 0,
            'distinct_periods' => 0,
        ], $overrides));
    }

    public function test_empty_trail_is_no_activity(): void
    {
        $this->assertSame('no_tax_activity', $this::classify([])['verdict']);
    }

    public function test_compressed_multi_period_history_is_synthetic_suspected(): void
    {
        // The Acme Production shape: 788 rows, 12 periods, 7-day span, one author.
        $result = $this->classify([
            'rows' => 788, 'max_burst_per_minute' => 116, 'creators' => 1,
            'span_days' => 7, 'distinct_periods' => 12,
        ]);

        $this->assertSame('synthetic_suspected', $result['verdict']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_same_minute_bulk_seed_is_synthetic_suspected(): void
    {
        $result = $this->classify([
            'rows' => 100, 'max_burst_per_minute' => 90, 'creators' => 1,
            'span_days' => 1, 'distinct_periods' => 4,
        ]);

        $this->assertSame('synthetic_suspected', $result['verdict']);
    }

    public function test_organic_trailing_activity_is_active(): void
    {
        $result = $this->classify([
            'rows' => 40, 'max_burst_per_minute' => 2, 'creators' => 6,
            'span_days' => 400, 'distinct_periods' => 13, 'days_since_last_activity' => 3,
        ]);

        $this->assertSame('active', $result['verdict']);
    }

    public function test_stale_but_organic_trail_is_dormant(): void
    {
        $result = $this->classify([
            'rows' => 30, 'max_burst_per_minute' => 3, 'creators' => 4,
            'span_days' => 300, 'distinct_periods' => 10, 'days_since_last_activity' => 120,
        ]);

        $this->assertSame('dormant', $result['verdict']);
    }

    public function test_small_trail_reports_weak_signals_and_never_synthetic(): void
    {
        $result = $this->classify(['rows' => 5, 'max_burst_per_minute' => 3, 'span_days' => 125, 'distinct_periods' => 3]);

        $this->assertNotSame('synthetic_suspected', $result['verdict']);
        $this->assertStringContainsString('weak', $result['reasons'][0]);
    }
}
