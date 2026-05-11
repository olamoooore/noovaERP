<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * MOB-002: Offline sync envelope implemented.
 */
class MOB002OfflineSyncEnvelopeContractTest extends TestCase
{
    public function test_queue_endpoint_requires_strong_sync_envelope_fields(): void
    {
        $controller = file_get_contents(base_path('modules/POS/src/Http/Controllers/PosSyncController.php'));

        $this->assertStringContainsString("'metadata' => 'required|array'", $controller);
        $this->assertStringContainsString("'metadata.offline_device_id' => 'required|string|max:120'", $controller);
        $this->assertStringContainsString("'metadata.offline_event_id' => 'required|string|max:120'", $controller);
        $this->assertStringContainsString("'metadata.warehouse_id_at_capture' => 'required'", $controller);
        $this->assertStringContainsString("'metadata.payload_hash' => 'required|string|max:128'", $controller);
        $this->assertStringContainsString("'metadata.signature_or_auth_proof' => 'required|string|max:255'", $controller);
    }

    public function test_sync_service_defines_required_envelope_contract_keys(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('private const REQUIRED_ENVELOPE_KEYS', $service);
        $this->assertStringContainsString("'tenant_id'", $service);
        $this->assertStringContainsString("'offline_device_id'", $service);
        $this->assertStringContainsString("'offline_session_id'", $service);
        $this->assertStringContainsString("'offline_event_id'", $service);
        $this->assertStringContainsString("'warehouse_id_at_capture'", $service);
        $this->assertStringContainsString("'payload_hash'", $service);
        $this->assertStringContainsString("'signature_or_auth_proof'", $service);
    }

    public function test_queue_operation_builds_and_validates_envelope_before_persisting(): void
    {
        $service = file_get_contents(base_path('modules/POS/src/Services/PosSyncService.php'));

        $this->assertStringContainsString('$metadata = $this->buildSyncEnvelope(', $service);
        $this->assertStringContainsString('Offline sync envelope incomplete:', $service);
        $this->assertStringContainsString("'metadata' => \$metadata", $service);
    }
}
