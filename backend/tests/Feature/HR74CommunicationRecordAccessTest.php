<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Services\CommunicationEngine;
use Tests\TenantTestCase;

/**
 * Issue #74 (hostile re-review B1) — the Communication module had no
 * record-level authorization. Any entitled user could read/write threads and
 * subscribers for any registry record, and the id-based message endpoints
 * added by #86/#88 had the same gap.
 *
 * Contract exercised here (real router + real middleware):
 *   - the subject's mapped domain permission (customers.view for customer)
 *     gates access for reads AND writes;
 *   - a role without the permission is denied with the required permission
 *     disclosed;
 *   - id-addressed endpoints enforce the same contract via the message's
 *     subject.
 */
class HR74CommunicationRecordAccessTest extends TenantTestCase
{
    private string $tenantId;
    private Customer $customer;
    private CommunicationMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);

        // Entitlement gating is not under test here; the record-level
        // middleware IS.
        $this->withoutMiddleware([\App\Http\Middleware\EnsureEntitlementAccess::class]);

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->customer = Customer::factory()->create(['legal_entity_id' => null]);

        $this->message = app(CommunicationEngine::class)->logSystem(
            'customer',
            $this->customer->id,
            'Record-access fixture message',
            'system.log',
            $this->tenantId,
        );
    }

    private function userWithRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $centralRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_viewer_with_the_mapped_permission_can_read_the_thread(): void
    {
        $viewer = $this->userWithRole('viewer', 'Viewer');

        $this->actingAs($viewer)
            ->getJson("/api/v1/api/communication/messages?model=customer&record_id={$this->customer->id}")
            ->assertOk();
    }

    public function test_user_without_the_mapped_permission_is_denied_on_read_and_write(): void
    {
        $cashier = $this->userWithRole('cashier', 'Cashier');

        // Read.
        $this->actingAs($cashier)
            ->getJson("/api/v1/api/communication/messages?model=customer&record_id={$this->customer->id}")
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'customers.view');

        // Write.
        $this->actingAs($cashier)
            ->postJson("/api/v1/api/communication/messages?model=customer&record_id={$this->customer->id}", [
                'body' => 'Should not be allowed',
            ])
            ->assertStatus(403);

        // The denied write must not have produced a message.
        $this->assertSame(0, CommunicationMessage::where('body', 'Should not be allowed')->count());
    }

    public function test_id_addressed_message_endpoints_enforce_the_subject_permission(): void
    {
        $cashier = $this->userWithRole('cashier', 'Cashier');
        $viewer = $this->userWithRole('viewer', 'Viewer');

        // mark-read (id-addressed) — denied for the cashier.
        $this->actingAs($cashier)
            ->postJson("/api/v1/api/communication/messages/{$this->message->id}/read")
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'customers.view');

        // attachment upload (id-addressed) — denied for the cashier.
        $this->actingAs($cashier)
            ->postJson("/api/v1/api/communication/messages/{$this->message->id}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('note.txt', 'HR74'),
            ])
            ->assertStatus(403);

        // The same endpoints work for the permitted viewer.
        $this->actingAs($viewer)
            ->postJson("/api/v1/api/communication/messages/{$this->message->id}/read")
            ->assertOk();

        $this->actingAs($viewer)
            ->postJson("/api/v1/api/communication/messages/{$this->message->id}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('note.txt', 'HR74'),
            ])
            ->assertCreated();
    }
}
