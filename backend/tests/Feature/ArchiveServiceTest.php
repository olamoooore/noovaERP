<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Platform\Archive\ArchiveRecord;
use App\Platform\Archive\ArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class ArchiveServiceTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected ArchiveService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->service = new ArchiveService();
    }

    public function test_archive_creates_record(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password']);
        $result = $this->service->archive('invoice', 1, $user->id, 'Archived for compliance');

        $this->assertNotEmpty($result->id);
        $this->assertEquals('invoice', $result->entity_type);
        $this->assertEquals(1, $result->entity_id);
        $this->assertEquals($user->id, $result->archived_by);
        $this->assertEquals(2555, $result->retention_days);
    }

    public function test_restore_updates_record(): void
    {
        $archive = ArchiveRecord::create([
            'tenant_id' => $this->tenantUuid,
            'entity_type' => 'invoice',
            'entity_id' => 1,
            'archived_by' => 1,
            'archived_at' => now(),
            'retention_days' => 2555,
        ]);

        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password']);
        $result = $this->service->restore($archive->id, $user->id, 'Need to review');

        $this->assertNotNull($result->restored_at);
        $this->assertEquals($user->id, $result->restored_by);
        $this->assertEquals('Need to review', $result->restore_reason);
    }

    public function test_prune_deletes_old_archived_records(): void
    {
        $oldDate = now()->subDays(3000);
        ArchiveRecord::create([
            'tenant_id' => $this->tenantUuid,
            'entity_type' => 'invoice',
            'entity_id' => 1,
            'archived_by' => 1,
            'archived_at' => $oldDate,
            'restored_at' => now(),
            'retention_days' => 2555,
        ]);

        $pruned = $this->service->prune(2555);

        $this->assertEquals(1, $pruned);
    }

    public function test_get_archive_history_returns_records(): void
    {
        ArchiveRecord::create([
            'tenant_id' => $this->tenantUuid,
            'entity_type' => 'invoice',
            'entity_id' => 1,
            'archived_by' => 1,
            'archived_at' => now()->subDays(30),
            'retention_days' => 2555,
        ]);

        ArchiveRecord::create([
            'tenant_id' => $this->tenantUuid,
            'entity_type' => 'invoice',
            'entity_id' => 1,
            'archived_by' => 2,
            'archived_at' => now()->subDays(10),
            'restored_at' => now()->subDays(5),
            'restore_reason' => 'Review needed',
            'retention_days' => 2555,
        ]);

        $history = $this->service->getArchiveHistory('invoice', 1);

        $this->assertCount(2, $history);
    }

    public function test_is_archived_returns_true_when_archived(): void
    {
        ArchiveRecord::create([
            'tenant_id' => $this->tenantUuid,
            'entity_type' => 'invoice',
            'entity_id' => 1,
            'archived_by' => 1,
            'archived_at' => now(),
            'retention_days' => 2555,
        ]);

        $this->assertTrue($this->service->isArchived('invoice', 1));
    }

    public function test_is_archived_returns_false_when_restored(): void
    {
        ArchiveRecord::create([
            'tenant_id' => $this->tenantUuid,
            'entity_type' => 'invoice',
            'entity_id' => 1,
            'archived_by' => 1,
            'archived_at' => now()->subDays(10),
            'restored_at' => now(),
            'retention_days' => 2555,
        ]);

        $this->assertFalse($this->service->isArchived('invoice', 1));
    }
}
