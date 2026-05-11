<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * MOB-003..MOB-014 closure contracts for offline/mobile hardening.
 */
class MOB003toMOB014OfflineHardeningContractTest extends TestCase
{
    public function test_mob003_offline_idempotency_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString("PosOfflineQueue::where('idempotency_key'", $service);
        $this->assertStringContainsString('Offline operation already queued', $service);
        $this->assertStringContainsString('Check if payment already exists (idempotency)', $service);
    }

    public function test_mob004_device_registration_and_revoke_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('assertDeviceRegistrationState', $service);
        $this->assertStringContainsString('Offline device is not registered or active.', $service);
        $this->assertStringContainsString('Offline device has been revoked.', $service);
    }

    public function test_mob005_permission_and_capability_snapshot_revalidation_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('assertCapabilitySnapshotState', $service);
        $this->assertStringContainsString("offline_capability_snapshot_version", $service);
        $this->assertStringContainsString("offline_permission_snapshot_version", $service);
        $this->assertStringContainsString('Offline capability snapshot is stale.', $service);
        $this->assertStringContainsString('Offline permission snapshot is stale.', $service);
    }

    public function test_mob006_offline_warehouse_scope_validation_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('assertWarehouseScopeState', $service);
        $this->assertStringContainsString('Offline warehouse scope mismatch for captured event.', $service);
    }

    public function test_mob007_offline_conflict_resolution_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));
        $controller = file_get_contents(base_path('modules/POS/src/Http/Controllers/PosSyncController.php'));
        $queueModel = file_get_contents(base_path('modules/POS/src/Models/PosOfflineQueue.php'));

        $this->assertStringContainsString("'status' => \$isConflict ? 'conflict' : 'failed'", $service);
        $this->assertStringContainsString('resolveConflict', $service);
        $this->assertStringContainsString("'resolution' => 'required|string|in:skip,retry,manual'", $controller);
        $this->assertStringContainsString("'conflict' => ['pending', 'syncing']", $queueModel);
    }

    public function test_mob008_offline_stock_allocation_contract_is_enforced_when_enabled(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('offline_enable_stock_allocation', $service);
        $this->assertStringContainsString('Offline stock allocation plan is required when allocation is enabled.', $service);
    }

    public function test_mob009_offline_stock_count_draft_reconciliation_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('draft_reconciliation_reference', $service);
        $this->assertStringContainsString('Offline stock count requires draft reconciliation reference.', $service);
    }

    public function test_mob010_offline_refund_void_restrictions_or_approval_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('Void operation requires approval. Cannot sync without approval_request_id.', $service);
        $this->assertStringContainsString('Refund operation requires approval. Cannot sync without approval_request_id.', $service);
    }

    public function test_mob011_offline_event_monitoring_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('logOfflineEvent', $service);
        $this->assertStringContainsString("Log::info('offline_sync_event'", $service);
        $this->assertStringContainsString('sync.conflict', $service);
    }

    public function test_mob012_offline_policy_tier_restrictions_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));
        $controller = file_get_contents(base_path('modules/POS/src/Http/Controllers/PosSyncController.php'));

        $this->assertStringContainsString('assertOfflinePolicyTierState', $service);
        $this->assertStringContainsString("'strict' => ['create_order']", $service);
        $this->assertStringContainsString("'standard' => ['create_order', 'payment']", $service);
        $this->assertStringContainsString("'enterprise' => ['create_order', 'payment', 'void', 'refund']", $service);
        $this->assertStringContainsString("'metadata.offline_policy_tier' => 'required|string|in:strict,standard,enterprise'", $controller);
    }

    public function test_mob013_tenant_offline_configuration_boundary_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('assertTenantBoundary', $service);
        $this->assertStringContainsString('Offline tenant boundary mismatch.', $service);
        $this->assertStringContainsString("'tenant_id' => \$tenantId", $service);
    }

    public function test_mob014_local_cache_visibility_and_encryption_contract_is_enforced(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));
        $controller = file_get_contents(base_path('modules/POS/src/Http/Controllers/PosSyncController.php'));

        $this->assertStringContainsString('assertLocalCacheSecurityState', $service);
        $this->assertStringContainsString('Offline local cache must be encrypted.', $service);
        $this->assertStringContainsString('Offline local cache visibility scope is invalid.', $service);
        $this->assertStringContainsString("'metadata.local_cache_encrypted' => 'required|boolean'", $controller);
        $this->assertStringContainsString("'metadata.local_cache_visibility_scope' => 'required|string|in:user,session,device'", $controller);
    }
}
