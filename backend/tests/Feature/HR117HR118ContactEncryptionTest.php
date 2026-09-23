<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactBankAccount;
use Modules\Contact\src\Models\ContactIdentifier;
use Tests\TenantTestCase;

/**
 * Issues #117/#118 — ContactBankAccount bank details and
 * ContactIdentifier.identifier_value are encrypted at rest, and the legacy
 * plaintext rows are backfilled so Eloquent reads cannot throw
 * DecryptException.
 */
class HR117HR118ContactEncryptionTest extends TenantTestCase
{
    private const MIGRATION = 'modules/Contact/database/migrations/tenant/2026_09_22_000015_encrypt_legacy_contact_sensitive_fields.php';

    private string $tenantId;

    private string $contactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->contactId = DB::table('core_contacts')->insertGetId([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'display_name' => 'HR117 Contact',
            'contact_type' => 'individual',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');
    }

    public function test_new_writes_are_encrypted_and_round_trip(): void
    {
        $bank = ContactBankAccount::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $this->contactId,
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'routing_number' => '011000138',
            'swift_code' => 'TESTMYXX',
            'iban' => 'MY12TEST1234567890',
            'is_primary' => true,
            'is_active' => true,
        ]);

        $identifier = ContactIdentifier::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $this->contactId,
            'identifier_type' => 'tax_id',
            'identifier_value' => 'TAX-998877',
        ]);

        $rawBank = DB::table('contact_bank_accounts')->where('id', $bank->id)->first();
        $rawIdentifier = DB::table('contact_identifiers')->where('id', $identifier->id)->first();

        $this->assertNotSame('1234567890', $rawBank->account_number, 'account_number must not be stored in plaintext');
        $this->assertNotSame('TESTMYXX', $rawBank->swift_code, 'swift_code must not be stored in plaintext');
        $this->assertNotSame('TAX-998877', $rawIdentifier->identifier_value, 'identifier_value must not be stored in plaintext');

        $this->assertSame('1234567890', $bank->fresh()->account_number, 'model must decrypt on read');
        $this->assertSame('TAX-998877', $identifier->fresh()->identifier_value, 'model must decrypt on read');
    }

    public function test_backfill_encrypts_legacy_plaintext_rows_idempotently(): void
    {
        // Legacy rows written raw (as the pre-fix code did).
        DB::table('contact_bank_accounts')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $this->contactId,
            'bank_name' => 'Legacy Bank',
            'account_number' => 'LEGACY-ACC-1',
            'iban' => 'LEGACY-IBAN-1',
            'is_primary' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('contact_identifiers')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'contact_id' => $this->contactId,
            'identifier_type' => 'registration_number',
            'identifier_value' => 'LEGACY-REG-1',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $bank = ContactBankAccount::where('bank_name', 'Legacy Bank')->first();
        $identifier = ContactIdentifier::where('identifier_type', 'registration_number')
            ->where('contact_id', $this->contactId)
            ->first();

        $this->assertSame('LEGACY-ACC-1', $bank->account_number, 'legacy bank value must decrypt after backfill');
        $this->assertSame('LEGACY-IBAN-1', $bank->iban);
        $this->assertSame('LEGACY-REG-1', $identifier->identifier_value, 'legacy identifier must decrypt after backfill');

        $rawBefore = DB::table('contact_bank_accounts')->where('id', $bank->id)->value('account_number');

        // Idempotent: a second run must not double-encrypt.
        $migration->up();
        $rawAfter = DB::table('contact_bank_accounts')->where('id', $bank->id)->value('account_number');
        $this->assertSame($rawBefore, $rawAfter, 'Re-running the backfill must not re-encrypt already-encrypted values');
        $this->assertSame('LEGACY-ACC-1', $bank->fresh()->account_number);
    }
}
