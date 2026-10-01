<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Compliance;

use App\Support\TaxPackVerificationGuard;
use Tests\TestCase;

/**
 * #269 CI content check: every VERIFY-tagged pack row is covered by the
 * filing-artifact warning, and verified periods stay clean.
 */
class TaxPackVerificationGuardTest extends TestCase
{
    public function test_verify_tagged_my_rows_warn_for_their_effective_periods(): void
    {
        $rows = TaxPackVerificationGuard::verifyTaggedRows('MY');
        $this->assertNotEmpty($rows, 'The 2026 MY GST-model rows must remain VERIFY-tagged until sign-off.');

        foreach ($rows as $row) {
            $this->assertNotSame('', $row['code']);

            if ($row['from'] === null) {
                continue;
            }

            $month = substr($row['from'], 0, 7);
            $warning = TaxPackVerificationGuard::warningFor('MY', $month);

            $this->assertNotNull($warning, "VERIFY row {$row['code']} must warn for its effective month {$month}.");
            $this->assertStringContainsString($row['code'], $warning);
            $this->assertStringContainsString('VERIFY-tagged', $warning);
        }
    }

    public function test_quarterly_periods_intersecting_verify_rows_warn(): void
    {
        $warning = TaxPackVerificationGuard::warningFor('MY', '2026-Q3');

        $this->assertNotNull($warning, 'H2 2026 intersects the VERIFY-tagged planned 10% row.');
        $this->assertStringContainsString('MY-SSTG-10', $warning);
    }

    public function test_verified_periods_and_countries_are_clean(): void
    {
        $this->assertNull(TaxPackVerificationGuard::warningFor('MY', '2025-03'), '2025 rows carry no VERIFY marker.');
        $this->assertNull(TaxPackVerificationGuard::warningFor('SG', '2026-03'), 'SG pack has no VERIFY-tagged rows.');
    }
}
