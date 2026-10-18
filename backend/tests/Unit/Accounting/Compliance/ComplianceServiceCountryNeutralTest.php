<?php

namespace Tests\Unit\Accounting\Compliance;

use PHPUnit\Framework\TestCase;

/**
 * Issue #699 (EC2) AC3 — ComplianceService is the country-neutral core: it
 * must not branch on a country code. This is an explicitly static acceptance
 * criterion; behaviour is covered by the per-pack suites.
 */
class ComplianceServiceCountryNeutralTest extends TestCase
{
    private function source(): string
    {
        $path = dirname(__DIR__, 4).'/app/Accounting/Services/ComplianceService.php';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_compliance_service_contains_no_country_code_literals(): void
    {
        $source = $this->source();

        foreach (["'MY'", '"MY"', "'SG'", '"SG"'] as $literal) {
            $this->assertStringNotContainsString(
                $literal,
                $source,
                "ComplianceService must not branch on the country code {$literal} — the pack declares it (#699 EC2)"
            );
        }
    }

    public function test_capability_dispatch_replaces_country_branches_and_method_probes(): void
    {
        $source = $this->source();

        foreach ([
            'instanceof SupportsCredentialValidation',
            'instanceof SupportsPayloadDefaults',
            'instanceof SupportsSigning',
            'instanceof ProvidesProviderName',
        ] as $capability) {
            $this->assertStringContainsString($capability, $source);
        }

        $this->assertStringNotContainsString("method_exists(\$provider, 'signDocument')", $source);
        $this->assertStringNotContainsString("method_exists(\$provider, 'getStatus')", $source);
    }
}
