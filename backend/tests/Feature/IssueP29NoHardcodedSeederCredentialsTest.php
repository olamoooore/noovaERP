<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\FinancialCloseRoleAccountsSeeder;
use Database\Seeders\SuperAdminSeeder;
use Tests\TestCase;

/**
 * Pillar 2 Part 3 §2.2 / Pillar 1 P1-19:
 *
 * Seeders must not ship usable passwords in the repository. Credentials are
 * supplied by the operator through environment variables when the explicit
 * seeder command is run; missing credentials fail closed.
 */
class IssueP29NoHardcodedSeederCredentialsTest extends TestCase
{
    public function test_financial_close_seeder_has_no_hardcoded_passwords(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../database/seeders/FinancialCloseRoleAccountsSeeder.php'
        );

        $this->assertStringNotContainsString('Accountant@123', $source);
        $this->assertStringNotContainsString('Manager@123', $source);
        $this->assertStringContainsString('FINANCIAL_CLOSE_ACCOUNTANT_PASSWORD', $source);
        $this->assertStringContainsString('FINANCIAL_CLOSE_MANAGER_PASSWORD', $source);
    }

    public function test_super_admin_seeder_has_no_hardcoded_password(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../database/seeders/SuperAdminSeeder.php'
        );

        $this->assertStringNotContainsString("Hash::make('password')", $source);
        $this->assertStringContainsString('SUPERADMIN_PASSWORD', $source);
        $this->assertStringContainsString('No default/demo password is permitted', $source);
    }

    public function test_financial_close_seeder_fails_closed_when_password_is_missing(): void
    {
        $reflection = new \ReflectionClass(FinancialCloseRoleAccountsSeeder::class);
        $method = $reflection->getMethod('configuredAccounts');
        $method->setAccessible(true);

        $oldAccountant = $_ENV['FINANCIAL_CLOSE_ACCOUNTANT_PASSWORD'] ?? null;
        $oldManager = $_ENV['FINANCIAL_CLOSE_MANAGER_PASSWORD'] ?? null;

        unset($_ENV['FINANCIAL_CLOSE_ACCOUNTANT_PASSWORD'], $_ENV['FINANCIAL_CLOSE_MANAGER_PASSWORD']);
        putenv('FINANCIAL_CLOSE_ACCOUNTANT_PASSWORD');
        putenv('FINANCIAL_CLOSE_MANAGER_PASSWORD');

        try {
            $this->expectException(\RuntimeException::class);
            $method->invoke(new FinancialCloseRoleAccountsSeeder);
        } finally {
            if ($oldAccountant !== null) {
                $_ENV['FINANCIAL_CLOSE_ACCOUNTANT_PASSWORD'] = $oldAccountant;
                putenv('FINANCIAL_CLOSE_ACCOUNTANT_PASSWORD='.$oldAccountant);
            }
            if ($oldManager !== null) {
                $_ENV['FINANCIAL_CLOSE_MANAGER_PASSWORD'] = $oldManager;
                putenv('FINANCIAL_CLOSE_MANAGER_PASSWORD='.$oldManager);
            }
        }
    }
}
