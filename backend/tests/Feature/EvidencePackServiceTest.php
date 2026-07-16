<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Platform\Evidence\EvidenceItem;
use App\Platform\Evidence\EvidencePack;
use App\Platform\Evidence\EvidencePackService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class EvidencePackServiceTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected EvidencePackService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->service = new EvidencePackService;
    }

    public function test_create_pack_creates_record(): void
    {
        $result = $this->service->createPack('Deployment Evidence', 'deployment', 'Evidence for v2.0 deployment');

        $this->assertNotEmpty($result->id);
        $this->assertEquals('Deployment Evidence', $result->name);
        $this->assertEquals('deployment', $result->type);
        $this->assertEquals('draft', $result->status);
    }

    public function test_add_item_adds_to_pack(): void
    {
        $pack = EvidencePack::create([
            'tenant_id' => $this->tenantUuid,
            'name' => 'Test Pack',
            'type' => 'test',
            'status' => 'draft',
        ]);

        $result = $this->service->addItem($pack->id, 'test_result', 'All tests passed');

        $this->assertNotEmpty($result->id);
        $this->assertEquals('test_result', $result->item_type);
        $this->assertEquals('All tests passed', $result->content);
    }

    public function test_finalize_sets_hash_and_status(): void
    {
        $pack = EvidencePack::create([
            'tenant_id' => $this->tenantUuid,
            'name' => 'Test Pack',
            'type' => 'test',
            'status' => 'draft',
        ]);

        EvidenceItem::create([
            'tenant_id' => $this->tenantUuid,
            'pack_id' => $pack->id,
            'item_type' => 'test_result',
            'content' => 'Test output',
        ]);

        $result = $this->service->finalize($pack->id);

        $this->assertEquals('finalized', $result->status);
        $this->assertNotNull($result->integrity_hash);
        $this->assertNotNull($result->finalized_at);
    }

    public function test_verify_returns_true_when_hash_matches(): void
    {
        $pack = EvidencePack::create([
            'tenant_id' => $this->tenantUuid,
            'name' => 'Test Pack',
            'type' => 'test',
            'status' => 'draft',
        ]);

        EvidenceItem::create([
            'tenant_id' => $this->tenantUuid,
            'pack_id' => $pack->id,
            'item_type' => 'test_result',
            'content' => 'Test output',
        ]);

        $this->service->finalize($pack->id);

        $verified = $this->service->verify($pack->id);

        $this->assertTrue($verified);
    }

    public function test_verify_returns_false_when_not_finalized(): void
    {
        $pack = EvidencePack::create([
            'tenant_id' => $this->tenantUuid,
            'name' => 'Test Pack',
            'type' => 'test',
            'status' => 'draft',
        ]);

        $verified = $this->service->verify($pack->id);

        $this->assertFalse($verified);
    }

    public function test_get_pack_returns_pack_with_items(): void
    {
        $pack = EvidencePack::create([
            'tenant_id' => $this->tenantUuid,
            'name' => 'Test Pack',
            'type' => 'test',
            'status' => 'draft',
        ]);

        EvidenceItem::create([
            'tenant_id' => $this->tenantUuid,
            'pack_id' => $pack->id,
            'item_type' => 'test_result',
            'content' => 'Test output',
        ]);

        $result = $this->service->getPack($pack->id);

        $this->assertNotNull($result);
        $this->assertEquals('Test Pack', $result->pack['name']);
        $this->assertCount(1, $result->items);
    }
}
