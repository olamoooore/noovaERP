<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactIdentifier;
use Modules\Contact\src\Services\ContactMasterService;
use Tests\TenantTestCase;

/**
 * Issue #118 (HIGH) — ContactIdentifier.identifier_value (tax IDs, registration
 * numbers, external references) must be encrypted at rest, matching the
 * ContactTaxProfile.tax_id pattern already used in this module.
 */
class HR118ContactIdentifierEncryptionTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['contact_identifiers', 'core_contacts']);
    }

    private function makeIdentifier(string $value, string $type = 'tax_id'): ContactIdentifier
    {
        return ContactIdentifier::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'contact_id' => (string) Str::uuid(),
            'identifier_type' => $type,
            'identifier_value' => $value,
            'is_primary' => false,
        ]);
    }

    private function raw(string $id): ?string
    {
        return DB::table('contact_identifiers')->where('id', $id)->value('identifier_value');
    }

    public function test_identifier_value_is_ciphertext_at_rest(): void
    {
        $identifier = $this->makeIdentifier('SG-123456789A');

        $raw = $this->raw($identifier->id);

        $this->assertNotSame('SG-123456789A', $raw, 'identifier_value must not be stored plaintext');
        $this->assertNotNull($raw);
        $this->assertSame('SG-123456789A', Crypt::decryptString($raw));
    }

    public function test_model_reads_return_plaintext_transparently(): void
    {
        $identifier = $this->makeIdentifier('SG-123456789A');

        $fresh = ContactIdentifier::find($identifier->id);

        $this->assertSame('SG-123456789A', $fresh->identifier_value);
    }

    public function test_column_is_text_typed(): void
    {
        $type = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'contact_identifiers')
            ->where('column_name', 'identifier_value')
            ->value('data_type');

        $this->assertSame('text', $type, 'identifier_value must be text (ciphertext grows beyond varchar 255)');
    }

    public function test_service_add_identifier_encrypts_at_rest(): void
    {
        $contact = \Modules\Contact\src\Models\CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'display_name' => 'HR118 Contact',
        ]);

        $identifier = app(ContactMasterService::class)
            ->addIdentifier($contact->id, 'registration_number', '202612345K', $contact->tenant_id);

        $this->assertNotNull($identifier);
        $this->assertNotSame('202612345K', $this->raw((string) $identifier->id));
        $this->assertSame('202612345K', Crypt::decryptString($this->raw((string) $identifier->id)));
        $this->assertSame('202612345K', ContactIdentifier::find($identifier->id)->identifier_value);
    }

    public function test_backfill_encrypts_legacy_plaintext_and_is_idempotent(): void
    {
        $id = (string) Str::uuid();
        DB::table('contact_identifiers')->insert([
            'id' => $id,
            'tenant_id' => (string) Str::uuid(),
            'contact_id' => (string) Str::uuid(),
            'identifier_type' => 'legacy_id',
            'identifier_value' => 'LEGACY-0001',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('LEGACY-0001', $this->raw($id), 'Precondition: legacy row is plaintext');

        $migration = require base_path('database/migrations/tenant/2026_09_21_000006_encrypt_contact_sensitive_fields.php');
        $migration->up();

        $encrypted = $this->raw($id);
        $this->assertNotSame('LEGACY-0001', $encrypted);
        $this->assertSame('LEGACY-0001', Crypt::decryptString($encrypted));

        $migration->up();
        $this->assertSame($encrypted, $this->raw($id), 'Re-running must not double-encrypt');
        $this->assertSame('LEGACY-0001', ContactIdentifier::find($id)->identifier_value);
    }
}
