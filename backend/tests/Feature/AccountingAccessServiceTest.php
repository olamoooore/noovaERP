<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Services\AccountingAccessService;
use App\SuperAdmin\Application\EntitlementService;
use Tests\TenantTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AccountingAccessServiceTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function test_is_module_entitled_allows_core_accounting_without_entitlement_snapshot(): void
    {
        $this->bindTenantContext('accounting-core-tenant');

        Feature::query()->create([
            'key' => 'accounting',
            'name' => 'Accounting',
            'description' => 'Core accounting module',
            'is_core' => true,
            'price' => 0,
        ]);

        $entitlements = \Mockery::mock(EntitlementService::class);
        $entitlements->shouldNotReceive('isEntitled');

        $service = new AccountingAccessService($entitlements);

        $this->assertTrue($service->isModuleEntitled());
    }

    public function test_is_module_entitled_uses_entitlements_when_accounting_is_not_core(): void
    {
        $this->bindTenantContext('accounting-addon-tenant');

        Feature::query()->create([
            'key' => 'accounting',
            'name' => 'Accounting',
            'description' => 'Optional accounting module',
            'is_core' => false,
            'price' => 0,
        ]);

        $entitlements = \Mockery::mock(EntitlementService::class);
        $entitlements
            ->shouldReceive('isEntitled')
            ->once()
            ->with('accounting-addon-tenant', 'accounting')
            ->andReturn(false);

        $service = new AccountingAccessService($entitlements);

        $this->assertFalse($service->isModuleEntitled());
    }
}