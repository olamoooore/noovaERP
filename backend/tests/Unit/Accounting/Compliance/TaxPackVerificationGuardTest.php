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
    public function test_corrected_my_pack_carries_no_verify_tagged_rows(): void
    {
        // #734 T1: the fictional 2026 GST-model rows were removed, so the
        // corrected MY pack has nothing left carrying the VERIFY marker.
        $this->assertSame([], TaxPackVerificationGuard::verifyTaggedRows('MY'));
        $this->assertNull(TaxPackVerificationGuard::warningFor('MY', '2026-03'));
        $this->assertNull(TaxPackVerificationGuard::warningFor('MY', '2026-Q3'));
    }

    public function test_verify_mechanism_warns_for_effective_periods(): void
    {
        config(['tax_packs.MY.rules' => [[
            'code' => 'TEST-SVC-VERIFY',
            'name' => 'Test dashboard row — VERIFY before filing',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]]]);

        $monthly = TaxPackVerificationGuard::warningFor('MY', '2026-03');
        $this->assertNotNull($monthly);
        $this->assertStringContainsString('TEST-SVC-VERIFY', $monthly);
        $this->assertStringContainsString('VERIFY-tagged', $monthly);

        $quarterly = TaxPackVerificationGuard::warningFor('MY', '2026-Q2');
        $this->assertNotNull($quarterly, 'Q2 months intersect the row effective window.');

        $this->assertNull(TaxPackVerificationGuard::warningFor('MY', '2025-03'), 'Months before the window stay clean.');
        $this->assertNull(TaxPackVerificationGuard::warningFor('MY', '2026-Q4'), 'Months after the window stay clean.');
    }

    public function test_verified_periods_and_countries_are_clean(): void
    {
        $this->assertNull(TaxPackVerificationGuard::warningFor('MY', '2025-03'), '2025 rows carry no VERIFY marker.');
        $this->assertNull(TaxPackVerificationGuard::warningFor('SG', '2026-03'), 'SG pack has no VERIFY-tagged rows.');
    }
}
