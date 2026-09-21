<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactBankAccount;
use Tests\TenantTestCase;

/**
 * Issue #117 (HIGH) — ContactBankAccount bank details must be encrypted at
 * rest, matching the ContactTaxProfile.tax_id pattern already used in this
 * module. Also verifies the columns were widened (an encrypted 34-char IBAN is
 * 256 chars — it would not fit the previous varchar(255)).
 */
class HR117ContactBankAccountEncryptionTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['contact_bank_accounts']);
    }

    private function makeAccount(array $overrides = []): ContactBankAccount
    {
        return ContactBankAccount::create(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'contact_id' => (string) Str::uuid(),
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'routing_number' => '021000021',
            'swift_code' => 'DBSSSGSG',
            'iban' => 'GB82WEST12345698765432',
            'is_primary' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function raw(string $id, string $column): ?string
    {
        return DB::table('contact_bank_accounts')->where('id', $id)->value($column);
    }

    public function test_bank_details_are_ciphertext_at_rest(): void
    {
        $account = $this->makeAccount();

        foreach (['account_number' => '1234567890', 'routing_number' => '021000021', 'swift_code' => 'DBSSSGSG', 'iban' => 'GB82WEST12345698765432'] as $column => $plaintext) {
            $raw = $this->raw($account->id, $column);

            $this->assertNotSame($plaintext, $raw, "{$column} must not be stored plaintext");
            $this->assertNotNull($raw);
            $this->assertSame($plaintext, Crypt::decryptString($raw), "{$column} must decrypt with APP_KEY");
        }
    }

    public function test_model_reads_return_plaintext_transparently(): void
    {
        $account = $this->makeAccount();
        $fresh = ContactBankAccount::find($account->id);

        $this->assertSame('1234567890', $fresh->account_number);
        $this->assertSame('021000021', $fresh->routing_number);
        $this->assertSame('DBSSSGSG', $fresh->swift_code);
        $this->assertSame('GB82WEST12345698765432', $fresh->iban);
    }

    public function test_long_iban_round_trips_through_the_widened_column(): void
    {
        // 34 chars (IBAN max) encrypts to > 255 chars.
        $iban = str_pad('GB82WEST', 34, 'X');
        $this->assertSame(34, strlen($iban));

        $account = $this->makeAccount(['iban' => $iban]);

        $this->assertSame($iban, ContactBankAccount::find($account->id)->iban);
        $this->assertNotSame($iban, $this->raw($account->id, 'iban'));
    }

    public function test_columns_are_text_typed(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'contact_bank_accounts')
            ->whereIn('column_name', ['account_number', 'routing_number', 'swift_code', 'iban'])
            ->pluck('data_type', 'column_name');

        foreach (['account_number', 'routing_number', 'swift_code', 'iban'] as $column) {
            $this->assertSame('text', $columns[$column] ?? null, "{$column} must be text (ciphertext grows beyond varchar 255)");
        }
    }

    public function test_backfill_encrypts_legacy_plaintext_and_is_idempotent(): void
    {
        $id = (string) Str::uuid();
        DB::table('contact_bank_accounts')->insert([
            'id' => $id,
            'tenant_id' => (string) Str::uuid(),
            'contact_id' => (string) Str::uuid(),
            'bank_name' => 'Legacy Bank',
            'account_number' => '999888777',
            'routing_number' => '011000015',
            'swift_code' => 'CHASUS33',
            'iban' => null,
            'is_primary' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('999888777', $this->raw($id, 'account_number'), 'Precondition: legacy row is plaintext');

        $migration = require base_path('database/migrations/tenant/2026_09_21_000006_encrypt_contact_sensitive_fields.php');
        $migration->up();

        $encrypted = $this->raw($id, 'account_number');
        $this->assertNotSame('999888777', $encrypted);
        $this->assertSame('999888777', Crypt::decryptString($encrypted));
        $this->assertSame('011000015', Crypt::decryptString($this->raw($id, 'routing_number')));
        $this->assertSame('CHASUS33', Crypt::decryptString($this->raw($id, 'swift_code')));
        $this->assertNull($this->raw($id, 'iban'), 'Null stays null');

        // Idempotent second run.
        $migration->up();
        $this->assertSame($encrypted, $this->raw($id, 'account_number'), 'Re-running must not double-encrypt');
        $this->assertSame('999888777', ContactBankAccount::find($id)->account_number);
    }
}
