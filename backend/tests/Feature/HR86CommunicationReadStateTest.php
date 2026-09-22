<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Modules\Communication\Services\CommunicationEngine;
use Tests\TenantTestCase;

/**
 * Issue #86 — per-user read state: unread counter, mark-as-read,
 * mark-all-as-read, and is_read flags on the thread.
 */
class HR86CommunicationReadStateTest extends TenantTestCase
{
    private string $tenantId;

    private CommunicationEngine $engine;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->engine = app(CommunicationEngine::class);
        $this->userId = 42;
    }

    private function makeMessages(int $count, string $model = 'Invoice', int $recordId = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->engine->logSystem($model, $recordId, "message {$i}", 'system.log', $this->tenantId);
        }
    }

    public function test_unread_counter_mark_read_and_mark_all_read(): void
    {
        $this->makeMessages(3);

        $this->assertSame(3, $this->engine->unreadCount($this->userId, $this->tenantId));

        $first = \Modules\Communication\Models\CommunicationMessage::orderBy('id')->first();
        $this->engine->markAsRead((int) $first->id, $this->userId, $this->tenantId);

        $this->assertSame(2, $this->engine->unreadCount($this->userId, $this->tenantId));

        // The thread exposes per-user read flags.
        $thread = $this->engine->getThread('Invoice', 1, $this->tenantId, 50, null, $this->userId, 'admin');
        $readMessage = $thread->firstWhere('id', $first->id);
        $this->assertTrue((bool) $readMessage->is_read);
        $unreadMessage = $thread->firstWhere('id', '!=', $first->id);
        $this->assertFalse((bool) $unreadMessage->is_read);

        // Mark-all is idempotent and returns only newly-read messages.
        $this->assertSame(2, $this->engine->markAllAsRead('Invoice', 1, $this->userId, $this->tenantId));
        $this->assertSame(0, $this->engine->unreadCount($this->userId, $this->tenantId));
        $this->assertSame(0, $this->engine->markAllAsRead('Invoice', 1, $this->userId, $this->tenantId));
    }

    public function test_unread_counter_can_be_scoped_to_one_record(): void
    {
        $this->makeMessages(2, 'Invoice', 1);
        $this->makeMessages(1, 'Invoice', 2);

        $this->assertSame(3, $this->engine->unreadCount($this->userId, $this->tenantId));
        $this->assertSame(2, $this->engine->unreadCount($this->userId, $this->tenantId, 'Invoice', 1));
        $this->assertSame(1, $this->engine->unreadCount($this->userId, $this->tenantId, 'Invoice', 2));
    }
}
