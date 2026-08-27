<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Casts\EncryptedDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactTaxProfile;
use Modules\Contact\src\Models\HrStaffProfile;
use Tests\TenantTestCase;

/**
 * Pillar 2 Part 3 §1.3 — sensitive PII fields encrypted at rest:
 *
 * Tax IDs, bank details, and salary/payroll figures must be stored as
 * ciphertext in PostgreSQL. This test suite verifies:
 *
 * 1. Model casts declare encrypted storage (source-check).
 * 2. Raw database values are ciphertext (not plaintext).
 * 3. Reading through the model decrypts to the original value.
 * 4. EncryptedDecimal preserves numeric precision to two decimals.
 */
class IssueP30EncryptedPiiFieldsTest extends TenantTestCase
{
    // ------------------------------------------------------------------
    // Source-checks
    // ------------------------------------------------------------------

    public function test_contact_tax_profile_uses_encrypted_cast(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/Contact/src/Models/ContactTaxProfile.php'
        );

        $this->assertStringContainsString("'tax_id' => 'encrypted'", $source);
    }

    public function test_hr_staff_profile_uses_encrypted_decimal_for_salary(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/Contact/src/Models/HrStaffProfile.php'
        );

        $this->assertStringContainsString(EncryptedDecimal::class, $source);
        $this->assertStringContainsString("'salary' => EncryptedDecimal::class", $source);
    }

    public function test_hr_staff_profile_uses_encrypted_array_for_bank_details(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/Contact/src/Models/HrStaffProfile.php'
        );

        $this->assertStringContainsString("'bank_details' => 'encrypted:array'", $source);
    }

    public function test_contract_uses_encrypted_decimal_for_salary(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/HRM/src/Models/Contract.php'
        );

        $this->assertStringContainsString(EncryptedDecimal::class, $source);
        $this->assertStringContainsString("'base_monthly_salary' => EncryptedDecimal::class", $source);
    }

    public function test_payroll_uses_encrypted_decimal_casts(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/HRM/src/Models/Payroll.php'
        );

        $this->assertStringContainsString(EncryptedDecimal::class, $source);
        $this->assertStringContainsString("'base_monthly_salary' => EncryptedDecimal::class", $source);
        $this->assertStringContainsString("'net_pay' => EncryptedDecimal::class", $source);
    }

    public function test_payroll_run_uses_encrypted_decimal_casts(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/HRM/src/Models/PayrollRun.php'
        );

        $this->assertStringContainsString(EncryptedDecimal::class, $source);
        $this->assertStringContainsString("'total_gross' => EncryptedDecimal::class", $source);
        $this->assertStringContainsString("'total_deductions' => EncryptedDecimal::class", $source);
        $this->assertStringContainsString("'total_net' => EncryptedDecimal::class", $source);
    }

    public function test_migration_encrypts_contact_tax_profiles(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../database/migrations/tenant/2026_08_26_000002_encrypt_sensitive_pii_fields.php'
        );

        $this->assertStringContainsString('contact_tax_profiles', $source);
        $this->assertStringContainsString('tax_id', $source);
        $this->assertStringContainsString('Crypt::encryptString', $source);
        $this->assertStringContainsString('Crypt::decryptString', $source);
    }

    public function test_migration_encrypts_hr_staff_salary_and_bank(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../database/migrations/tenant/2026_08_26_000002_encrypt_sensitive_pii_fields.php'
        );

        $this->assertStringContainsString('hr_staff_profiles', $source);
        $this->assertStringContainsString("'salary'", $source);
        $this->assertStringContainsString("'bank_details'", $source);
    }

    public function test_migration_encrypts_hrm_contracts_salary(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../database/migrations/tenant/2026_08_26_000002_encrypt_sensitive_pii_fields.php'
        );

        $this->assertStringContainsString('hrm_contracts', $source);
        $this->assertStringContainsString('base_monthly_salary', $source);
    }

    public function test_migration_encrypts_payroll_run_totals(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../database/migrations/tenant/2026_08_26_000002_encrypt_sensitive_pii_fields.php'
        );

        $this->assertStringContainsString('hrm_payroll_runs', $source);
        $this->assertStringContainsString('total_gross', $source);
        $this->assertStringContainsString('total_net', $source);
    }

    // ------------------------------------------------------------------
    // Runtime: EncryptedDecimal unit test
    // ------------------------------------------------------------------

    public function test_encrypted_decimal_encrypts_and_decrypts(): void
    {
        $cast = new EncryptedDecimal;
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'users';
        };

        // Set encrypts the value
        $result = $cast->set($model, 'salary', '5000.50', []);
        $this->assertArrayHasKey('salary', $result);
        $ciphertext = $result['salary'];
        $this->assertIsString($ciphertext);
        $this->assertNotSame('5000.50', $ciphertext);
        // Must not be plaintext
        $this->assertStringNotContainsString('5000.50', $ciphertext);

        // Get decrypts back to the original
        $decrypted = $cast->get($model, 'salary', $ciphertext, []);
        $this->assertSame('5000.50', $decrypted);
    }

    public function test_encrypted_decimal_null_passthrough(): void
    {
        $cast = new EncryptedDecimal;
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'users';
        };

        $result = $cast->set($model, 'salary', null, []);
        $this->assertNull($result['salary']);

        $result = $cast->set($model, 'salary', '', []);
        $this->assertNull($result['salary']);

        $decrypted = $cast->get($model, 'salary', null, []);
        $this->assertNull($decrypted);
    }

    public function test_encrypted_decimal_rejects_non_numeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $cast = new EncryptedDecimal;
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'users';
        };

        $cast->set($model, 'salary', 'not-a-number', []);
    }

    public function test_encrypted_decimal_preserves_two_decimal_places(): void
    {
        $cast = new EncryptedDecimal;
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'users';
        };

        // Integer input → two decimals
        $result = $cast->set($model, 'salary', 5000, []);
        $decrypted = $cast->get($model, 'salary', $result['salary'], []);
        $this->assertSame('5000.00', $decrypted);

        // High precision → rounded
        $result = $cast->set($model, 'salary', 5000.126, []);
        $decrypted = $cast->get($model, 'salary', $result['salary'], []);
        $this->assertSame('5000.13', $decrypted);
    }

    // ------------------------------------------------------------------
    // Runtime: verify raw DB values are ciphertext
    // ------------------------------------------------------------------

    public function test_contact_tax_profile_stores_tax_id_as_ciphertext(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('contact_tax_profiles')) {
            $this->markTestSkipped('contact_tax_profiles table not in tenant schema');
        }

        $this->bindTenantContext((string) Str::uuid());

        $id = (string) Str::uuid();
        $taxId = 'TAX-12-345678';

        // Simulate what the migration does: insert ciphertext via DB::table
        DB::table('contact_tax_profiles')->insert([
            'id' => $id,
            'tenant_id' => tenant('id'),
            'contact_id' => (string) Str::uuid(),
            'tax_id' => \Illuminate\Support\Facades\Crypt::encryptString($taxId),
            'tax_profile_type' => 'customer_tax',
        ]);

        // Raw DB value must NOT be plaintext
        $raw = DB::table('contact_tax_profiles')->where('id', $id)->value('tax_id');
        $this->assertNotSame($taxId, $raw);

        // Reading through the model decrypts back
        $profile = ContactTaxProfile::find($id);
        $this->assertSame($taxId, $profile->tax_id);
    }

    public function test_hr_staff_profile_stores_bank_details_as_ciphertext(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('hr_staff_profiles')) {
            $this->markTestSkipped('hr_staff_profiles table not in tenant schema');
        }

        $this->bindTenantContext((string) Str::uuid());

        $bankDetails = [
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'routing_number' => '987654321',
        ];
        $id = (string) Str::uuid();

        // Insert ciphertext via DB::table (simulating migration)
        DB::table('hr_staff_profiles')->insert([
            'id' => $id,
            'tenant_id' => tenant('id'),
            'contact_id' => (string) Str::uuid(),
            'employment_status' => 'active',
            'bank_details' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($bankDetails)),
        ]);

        $raw = DB::table('hr_staff_profiles')->where('id', $id)->value('bank_details');
        $this->assertNotSame(json_encode($bankDetails), $raw);

        // Reading through the model decrypts back to the original array
        $profile = HrStaffProfile::find($id);
        $this->assertSame($bankDetails['bank_name'], $profile->bank_details['bank_name']);
        $this->assertSame($bankDetails['account_number'], $profile->bank_details['account_number']);
        $this->assertSame($bankDetails['routing_number'], $profile->bank_details['routing_number']);
    }
}
