<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MedicalCase;
use App\Models\User;
use App\Platform\DataSecurity\SensitiveFieldGuard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #223 (regression from the #159 fix) — the framework `encrypted` cast
 * throws an uncaught DecryptException on read for rows still holding legacy
 * plaintext (e.g. a tenant restored from an older backup). One bad row 500s
 * the whole Medical Case feature; the model must degrade like the backfill
 * migration already does.
 */
class HR223MedicalCaseLegacyDecryptTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());
        $this->assertTenantSchemaReady(['medical_cases', 'customers']);

        $this->user = User::create([
            'name' => 'HR223 User',
            'email' => 'hr223-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    private function makeCase(string $patientName): MedicalCase
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

    public function test_legacy_plaintext_rows_read_as_plaintext_without_throwing(): void
    {
        $case = $this->makeCase('Properly Encrypted');

        // Simulate a row that never went through the backfill migration.
        DB::table('medical_cases')
            ->where('id', $case->id)
            ->update(['patient_name_encrypted' => 'Legacy Jane Doe']);

        Log::spy();

        $fresh = MedicalCase::find($case->id);

        $this->assertSame('Legacy Jane Doe', $fresh->patient_name_encrypted);

        // #567 / Decision on #919 (approved 7 Oct 2026): serialization masks
        // the patient name unless the viewer may read it; no viewer = masked.
        $this->assertNotSame('Legacy Jane Doe', $fresh->toArray()['patient_name_encrypted']);

        // A viewer holding medical_cases.phi.view still gets the plaintext.
        $this->app->instance(SensitiveFieldGuard::class, new class extends SensitiveFieldGuard
        {
            public function canRead(string $permission): bool
            {
                return true;
            }
        });
        $this->assertSame('Legacy Jane Doe', $fresh->toArray()['patient_name_encrypted']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'Unreadable encrypted value'))
            ->atLeast()->once();
    }

    public function test_unreadable_ciphertext_degrades_to_the_raw_value(): void
    {
        $case = $this->makeCase('Round Trip');

        // Valid-format-looking but undecryptable payload.
        $tampered = strrev(Crypt::encryptString('whatever'));

        DB::table('medical_cases')
            ->where('id', $case->id)
            ->update(['patient_name_encrypted' => $tampered]);

        $fresh = MedicalCase::find($case->id);

        $this->assertSame($tampered, $fresh->patient_name_encrypted);
    }

    public function test_properly_encrypted_rows_still_round_trip_and_re_encrypt_on_update(): void
    {
        $case = $this->makeCase('Jane Doe');

        $raw = $this->rawColumn($case->id);
        $this->assertNotSame('Jane Doe', $raw, 'Value must be ciphertext at rest');
        $this->assertSame('Jane Doe', Crypt::decryptString($raw));
        $this->assertSame('Jane Doe', MedicalCase::find($case->id)->patient_name_encrypted);

        $case->update(['patient_name_encrypted' => 'Jane Updated']);

        $updatedRaw = $this->rawColumn($case->id);
        $this->assertNotSame('Jane Updated', $updatedRaw);
        $this->assertSame('Jane Updated', Crypt::decryptString($updatedRaw));
        $this->assertSame('Jane Updated', MedicalCase::find($case->id)->patient_name_encrypted);
    }
}
