<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TenantTestCase;

/**
 * Profile page endpoints — own profile editing (name/email/phone),
 * password change (with current-password verification + Sanctum
 * token rotation), avatar upload/delete on the public disk.
 *
 * The /api/v1/auth/profile endpoints live in
 *   App\Http\Controllers\Api\Auth\ProfileController.
 *
 * Coverage matrix:
 *   - profile update persists to BOTH central + tenant schemas
 *   - email change is uniqueness-checked against the central users table
 *   - password change requires the current password, applies min
 *     complexity, rotates every other Sanctum token
 *   - avatar upload writes to the public disk, replaces the old file,
 *     resolves avatar_url to an absolute URL
 *   - avatar delete clears the field on both schemas
 *   - unauthenticated callers get 401
 *   - non-owner non-admin callers CAN edit their own profile
 *     (this is not an admin endpoint — every authenticated user has
 *     self-service access)
 */
class HR250ProfileEndpointsTest extends TenantTestCase
{
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'HR250 Profile Tenant',
                'email' => 'hr250-'.substr(md5($this->tenantId), 0, 8).'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Storage::fake('public');
    }

    private function makeSalesUser(): User
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'profile-sales@example.test',
            'password' => Hash::make('OriginalPass1!'),
        ]);

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('public.user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            [
                'role' => 'sales',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $sales = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Sales', 'guard_name' => 'web']);
        $user->assignRole($sales);

        return $user;
    }

    public function test_get_profile_returns_user_and_avatar_url(): void
    {
        $user = $this->makeSalesUser();

        $response = $this->actingAs($user)->getJson('/api/v1/auth/profile');

        $response->assertOk();
        $response->assertJsonPath('user.email', 'profile-sales@example.test');
        $response->assertJsonPath('user.name', 'Original Name');
        $this->assertNull($response->json('avatar_url'));
    }

    public function test_update_profile_persists_to_both_central_and_tenant(): void
    {
        $user = $this->makeSalesUser();

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'name' => 'Updated Name',
            'email' => 'profile-sales-updated@example.test',
            'phone' => '+1-555-0100',
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.name', 'Updated Name');
        $response->assertJsonPath('user.email', 'profile-sales-updated@example.test');
        $response->assertJsonPath('user.phone', '+1-555-0100');

        // Central row updated.
        $central = DB::table('public.users')->where('email', 'profile-sales-updated@example.test')->first();
        $this->assertNotNull($central, 'Central row must be updated.');
        $this->assertSame('Updated Name', $central->name);
        $this->assertSame('+1-555-0100', $central->phone);

        // Tenant row mirrored.
        $tenant = DB::table('users')->where('email', 'profile-sales-updated@example.test')->first();
        $this->assertNotNull($tenant, 'Tenant row must be mirrored.');
        $this->assertSame('Updated Name', $tenant->name);
        $this->assertSame('+1-555-0100', $tenant->phone);
    }

    public function test_update_profile_rejects_invalid_email(): void
    {
        $user = $this->makeSalesUser();

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_update_profile_rejects_duplicate_email(): void
    {
        $user = $this->makeSalesUser();
        $other = User::factory()->create(['email' => 'other@example.test']);
        DB::table('public.users')->updateOrInsert(
            ['email' => $other->email],
            ['name' => $other->name, 'password' => $other->password, 'created_at' => now(), 'updated_at' => now()]
        );

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'email' => 'other@example.test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_update_profile_rejects_empty_payload(): void
    {
        $user = $this->makeSalesUser();

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile', []);

        $response->assertStatus(422);
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $user = $this->makeSalesUser();

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile/password', [
            'current_password' => 'WrongPassword1!',
            'password' => 'NewSecurePass1!',
            'password_confirmation' => 'NewSecurePass1!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);

        // Original password still works (no rollback needed — the
        // controller never reached the save branch).
        $this->assertTrue(Hash::check('OriginalPass1!', $user->fresh()->password));
    }

    public function test_password_change_requires_minimum_complexity(): void
    {
        $user = $this->makeSalesUser();

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile/password', [
            'current_password' => 'OriginalPass1!',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_password_change_succeeds_and_revokes_other_tokens(): void
    {
        $user = $this->makeSalesUser();

        // Create two extra tokens that should be revoked after the
        // password change.
        $stale1 = $user->createToken('stale-1')->plainTextToken;
        $stale2 = $user->createToken('stale-2')->plainTextToken;
        $this->assertNotEmpty($stale1);
        $this->assertNotEmpty($stale2);

        $active = $user->createToken('active')->plainTextToken;
        $activeTokenId = PersonalAccessToken::findToken(explode('|', $active)[1])?->id;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$active])
            ->putJson('/api/v1/auth/profile/password', [
                'current_password' => 'OriginalPass1!',
                'password' => 'BrandNewPass1!',
                'password_confirmation' => 'BrandNewPass1!',
            ]);

        $response->assertOk();

        $this->assertTrue(Hash::check('BrandNewPass1!', $user->fresh()->password));
        $this->assertFalse(Hash::check('OriginalPass1!', $user->fresh()->password));

        // Only the active token survives.
        $remaining = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->pluck('id')->all();
        $this->assertContains($activeTokenId, $remaining, 'Active token must survive.');
        $this->assertCount(1, $remaining, 'All stale tokens must be revoked.');
    }

    public function test_avatar_upload_writes_to_public_disk_and_persists_path(): void
    {
        $user = $this->makeSalesUser();

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(500);

        $response = $this->actingAs($user)
            ->post('/api/v1/auth/profile/avatar', ['avatar' => $file]);

        $response->assertOk();
        $this->assertNotNull($response->json('avatar_url'));
        $this->assertStringContainsString('/storage/avatars/', $response->json('avatar_url'));

        $fresh = $user->fresh();
        $this->assertNotEmpty($fresh->avatar);
        $this->assertStringStartsWith('avatars/', $fresh->avatar);

        Storage::disk('public')->assertExists($fresh->avatar);

        // Tenant row mirrored.
        $tenant = DB::table('users')->where('email', $user->email)->first();
        $this->assertNotNull($tenant->avatar);
        $this->assertSame($fresh->avatar, $tenant->avatar);
    }

    public function test_avatar_upload_replaces_previous_file(): void
    {
        $user = $this->makeSalesUser();

        $first = UploadedFile::fake()->image('first.png', 100, 100);
        $this->actingAs($user)->post('/api/v1/auth/profile/avatar', ['avatar' => $first])->assertOk();
        $firstPath = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($firstPath);

        $second = UploadedFile::fake()->image('second.png', 100, 100);
        $response = $this->actingAs($user)->post('/api/v1/auth/profile/avatar', ['avatar' => $second]);

        $response->assertOk();
        $secondPath = $user->fresh()->avatar;

        $this->assertNotSame($firstPath, $secondPath, 'New upload must replace the old path.');
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_avatar_upload_rejects_oversized_or_wrong_mime(): void
    {
        $user = $this->makeSalesUser();

        $oversized = UploadedFile::fake()->image('big.jpg')->size(11 * 1024); // 11 MB
        $this->actingAs($user)->post('/api/v1/auth/profile/avatar', ['avatar' => $oversized])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);

        $pdf = UploadedFile::fake()->create('not-an-image.pdf', 100);
        $this->actingAs($user)->post('/api/v1/auth/profile/avatar', ['avatar' => $pdf])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_avatar_delete_clears_field_and_removes_file(): void
    {
        $user = $this->makeSalesUser();
        $file = UploadedFile::fake()->image('me.jpg');
        $this->actingAs($user)->post('/api/v1/auth/profile/avatar', ['avatar' => $file])->assertOk();
        $path = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($path);

        $response = $this->actingAs($user)->deleteJson('/api/v1/auth/profile/avatar');

        $response->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->assertNull($user->fresh()->avatar);

        $tenant = DB::table('users')->where('email', $user->email)->first();
        $this->assertNull($tenant->avatar);
    }

    public function test_unauthenticated_cannot_access_profile_endpoints(): void
    {
        $this->getJson('/api/v1/auth/profile')->assertStatus(401);
        $this->putJson('/api/v1/auth/profile', ['name' => 'X'])->assertStatus(401);
        $this->putJson('/api/v1/auth/profile/password', [
            'current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y',
        ])->assertStatus(401);
        $this->post('/api/v1/auth/profile/avatar', ['avatar' => UploadedFile::fake()->image('x.jpg')])->assertStatus(401);
        $this->deleteJson('/api/v1/auth/profile/avatar')->assertStatus(401);
    }
}
