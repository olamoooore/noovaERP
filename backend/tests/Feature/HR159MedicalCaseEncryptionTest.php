<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MedicalCase;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #159 (CRITICAL) — patient names are PHI; the column was named
 * patient_name_encrypted but stored plaintext.
 *
 * Verification from the issue: a created case's raw column value must be
 * ciphertext, not "Jane Doe". Also covers the in-place backfill of rows that
 * were stored before the cast existed.
 */
class HR159MedicalCaseEncryptionTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['medical_cases', 'customers']);

        $this->user = User::create([
            'name' => 'HR159 User',
            'email' => 'hr159-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    private function makeCase(?string $patientName): MedicalCase
    {
        return MedicalCase::create([
            'customer_id' => Customer::factory()->create()->id,
            'patient_name_encrypted' => $patientName,
            'case_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);
    }

    private function rawColumn(int $id): ?string
    {
        return DB::table('medical_cases')->where('id', $id)->value('patient_name_encrypted');
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_patient_name_is_ciphertext_at_rest(): void
    {
        $case = $this->makeCase('Jane Doe');

        $raw = $this->rawColumn($case->id);

        $this->assertNotSame('Jane Doe', $raw, 'The raw column must NOT contain the plaintext patient name');
        $this->assertNotNull($raw);
        $this->assertSame('Jane Doe', Crypt::decryptString($raw), 'The stored ciphertext must decrypt with APP_KEY');
    }

    public function test_model_reads_return_decrypted_name(): void
    {
        $case = $this->makeCase('Jane Doe');

        $fresh = MedicalCase::find($case->id);

        $this->assertSame('Jane Doe', $fresh->patient_name_encrypted, 'Casted attribute must decrypt transparently');
        $this->assertSame('Jane Doe', $fresh->patient_name, 'Virtual patient_name attribute must return the decrypted value');
    }

    public function test_null_patient_name_stays_null(): void
    {
        $case = $this->makeCase(null);

        $this->assertNull($this->rawColumn($case->id));
        $this->assertNull(MedicalCase::find($case->id)->patient_name);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Backfill of pre-existing plaintext rows
    // ─────────────────────────────────────────────────────────────────────

    public function test_backfill_migration_encrypts_legacy_plaintext_rows(): void
    {
        $legacyId = DB::table('medical_cases')->insertGetId([
            'case_no' => 'MC-LEGACY-'.Str::upper(Str::random(6)),
            'customer_id' => Customer::factory()->create()->id,
            'patient_name_encrypted' => 'Legacy Patient',
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('Legacy Patient', $this->rawColumn($legacyId), 'Precondition: legacy row is plaintext');

        $migration = require base_path('database/migrations/tenant/2026_09_21_000005_encrypt_medical_case_patient_names.php');
        $migration->up();

        $encrypted = $this->rawColumn($legacyId);

        $this->assertNotSame('Legacy Patient', $encrypted);
        $this->assertSame('Legacy Patient', Crypt::decryptString($encrypted));

        // The model can read the backfilled row once the cast is in place.
        $this->assertSame('Legacy Patient', MedicalCase::find($legacyId)->patient_name);

        // Idempotent: a second run must not double-encrypt.
        $migration->up();

        $this->assertSame($encrypted, $this->rawColumn($legacyId), 'Re-running the backfill must be a no-op');
        $this->assertSame('Legacy Patient', Crypt::decryptString($this->rawColumn($legacyId)));
    }

    public function test_backfill_leaves_null_and_empty_rows_alone(): void
    {
        $nullId = DB::table('medical_cases')->insertGetId([
            'case_no' => 'MC-NULL-'.Str::upper(Str::random(6)),
            'customer_id' => Customer::factory()->create()->id,
            'patient_name_encrypted' => null,
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $emptyId = DB::table('medical_cases')->insertGetId([
            'case_no' => 'MC-EMPTY-'.Str::upper(Str::random(6)),
            'customer_id' => Customer::factory()->create()->id,
            'patient_name_encrypted' => '',
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/tenant/2026_09_21_000005_encrypt_medical_case_patient_names.php');
        $migration->up();

        $this->assertNull($this->rawColumn($nullId));
        $this->assertSame('', $this->rawColumn($emptyId));
    }
}
