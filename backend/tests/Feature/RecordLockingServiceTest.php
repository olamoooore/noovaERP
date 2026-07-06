<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Platform\Locking\LockRecord;
use App\Platform\Locking\RecordLockingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class RecordLockingServiceTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected RecordLockingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->service = new RecordLockingService();
    }

    public function test_acquire_creates_lock(): void
    {
        $user = User::create(['name' => 'User1', 'email' => 'user1@test.com', 'password' => 'password']);
        $result = $this->service->acquire('invoice', 1, $user->id);

        $this->assertTrue($result->acquired);
        $this->assertNotEmpty($result->lock_id);
        $this->assertNotNull($result->expires_at);
    }

    public function test_acquire_blocks_different_user(): void
    {
        $user1 = User::create(['name' => 'User1', 'email' => 'user1@test.com', 'password' => 'password']);
        $user2 = User::create(['name' => 'User2', 'email' => 'user2@test.com', 'password' => 'password']);

        $this->service->acquire('invoice', 1, $user1->id);
        $result = $this->service->acquire('invoice', 1, $user2->id);

        $this->assertFalse($result->acquired);
        $this->assertEquals($user1->id, $result->locked_by);
    }

    public function test_release_frees_lock(): void
    {
        $user = User::create(['name' => 'User1', 'email' => 'user1@test.com', 'password' => 'password']);
        $this->service->acquire('invoice', 1, $user->id);

        $released = $this->service->release('invoice', 1, $user->id);

        $this->assertTrue($released);
        $this->assertFalse($this->service->isLocked('invoice', 1));
    }

    public function test_is_locked_returns_true_when_locked(): void
    {
        $user = User::create(['name' => 'User1', 'email' => 'user1@test.com', 'password' => 'password']);
        $this->service->acquire('invoice', 1, $user->id, 60);

        $this->assertTrue($this->service->isLocked('invoice', 1));
    }

    public function test_is_locked_returns_false_when_released(): void
    {
        $user = User::create(['name' => 'User1', 'email' => 'user1@test.com', 'password' => 'password']);
        $this->service->acquire('invoice', 1, $user->id);
        $this->service->release('invoice', 1, $user->id);

        $this->assertFalse($this->service->isLocked('invoice', 1));
    }

    public function test_force_release_frees_any_lock(): void
    {
        $user = User::create(['name' => 'User1', 'email' => 'user1@test.com', 'password' => 'password']);
        $this->service->acquire('invoice', 1, $user->id);

        $released = $this->service->forceRelease('invoice', 1);

        $this->assertTrue($released);
        $this->assertFalse($this->service->isLocked('invoice', 1));
    }
}
