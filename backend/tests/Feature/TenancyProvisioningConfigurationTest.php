<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events;
use Tests\TestCase;

class TenancyProvisioningConfigurationTest extends TestCase
{
    public function test_tenant_created_event_has_provisioning_listeners_registered(): void
    {
        $this->assertTrue(
            Event::hasListeners(Events\TenantCreated::class),
            'TenantCreated must have listeners so new tenants are provisioned automatically.'
        );

        $this->assertNotEmpty(Event::getListeners(Events\TenantCreated::class));
    }

    public function test_tenant_database_name_uses_schema_name_when_present(): void
    {
        $tenant = new Tenant([
            'id' => 'tenant-id-123',
        ]);
        $tenant->schema_name = 'friendly_schema';

        $this->assertSame('friendly_schema', $tenant->database()->getName());
    }

    public function test_tenant_database_name_falls_back_to_tenant_id(): void
    {
        $tenant = new Tenant([
            'id' => 'tenant-id-123',
        ]);

        $this->assertSame('tenant-id-123', $tenant->database()->getName());
    }
}