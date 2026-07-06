<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Platform\DataSecurity\DataClassification;
use App\Platform\DataSecurity\DataSecurityService;
use App\Platform\DataSecurity\FieldMaskingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class DataSecurityServiceTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected DataSecurityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->service = new DataSecurityService();
    }

    public function test_classify_creates_classification(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password']);
        $result = $this->service->classify('invoice', 1, 'confidential', $user->id, 'Sensitive financial data');

        $this->assertNotEmpty($result->id);
        $this->assertEquals('confidential', $result->classification);
        $this->assertEquals($user->id, $result->classified_by);
        $this->assertEquals('Sensitive financial data', $result->notes);
    }

    public function test_classify_updates_existing_classification(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password']);

        DataClassification::create([
            'tenant_id' => $this->tenantUuid,
            'entity_type' => 'invoice',
            'entity_id' => 1,
            'classification' => 'public',
            'classified_by' => $user->id,
            'classified_at' => now(),
        ]);

        $result = $this->service->classify('invoice', 1, 'secret', $user->id, 'Reclassified');

        $this->assertEquals('secret', $result->classification);
        $this->assertEquals('Reclassified', $result->notes);
    }

    public function test_get_classification_returns_existing(): void
    {
        DataClassification::create([
            'tenant_id' => $this->tenantUuid,
            'entity_type' => 'invoice',
            'entity_id' => 1,
            'classification' => 'internal',
            'classified_by' => 1,
            'classified_at' => now(),
        ]);

        $result = $this->service->getClassification('invoice', 1);

        $this->assertNotNull($result);
        $this->assertEquals('internal', $result->classification);
    }

    public function test_get_classification_returns_null_when_not_exists(): void
    {
        $result = $this->service->getClassification('invoice', 999);

        $this->assertNull($result);
    }

    public function test_mask_email_correctly(): void
    {
        $masked = FieldMaskingService::maskEmail('john@example.com');

        $this->assertStringStartsWith('j', $masked);
        $this->assertStringEndsWith('@example.com', $masked);
        $this->assertStringContainsString('*', $masked);
    }

    public function test_mask_phone_correctly(): void
    {
        $masked = FieldMaskingService::maskPhone('1234567890');

        $this->assertStringEndsWith('7890', $masked);
        $this->assertStringContainsString('*', $masked);
    }

    public function test_mask_ssn_correctly(): void
    {
        $masked = FieldMaskingService::maskSSN('123-45-6789');

        $this->assertEquals('***-**-6789', $masked);
    }

    public function test_mask_card_correctly(): void
    {
        $masked = FieldMaskingService::maskCard('4111-1111-1111-1234');

        $this->assertEquals('****-****-****-1234', $masked);
    }
}
