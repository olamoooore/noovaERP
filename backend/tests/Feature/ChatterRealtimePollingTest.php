<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Tests\TenantTestCase;

/**
 * Issue #87 — Real-Time Chatter Updates via WebSocket.
 *
 * The platform does not deploy a WebSocket server, so the chatter panel
 * polls a lightweight \`since\` endpoint while open. Verifies, via real
 * HTTP, that:
 *
 *   1. GET /chatter/{type}/{id}/since returns messages with
 *      \`created_at > since\` in ascending order.
 *   2. The endpoint echoes a fresh \`since\` cursor for the next poll.
 *   3. Without \`since\`, returns all messages in ascending order (initial
 *      hydration).
 */
class ChatterRealtimePollingTest extends TenantTestCase
{
    protected User $user;
    protected Customer $customer;
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });
        $this->bindTenantContext($tenant->id);
        $this->tenantId = $tenant->id;

        $this->assertTenantSchemaReady(['customers', 'communication_messages']);

        $this->user = User::create([
            'name' => 'Realtime Tester',
            'email' => 'realtime-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Realtime Customer',
            'payment_terms' => 30,
            'billing_address' => 'Realtime Billing',
            'shipping_address' => 'Realtime Shipping',
        ]);
    }

    public function test_since_returns_messages_newer_than_cursor(): void
    {
        $class = rawurlencode(\App\Models\Customer::class);

        // Seed two messages with explicit created_at so the cursor logic is
        // unambiguous regardless of wall-clock timing.
        $old = CommunicationMessage::create([
            'tenant_id' => $this->tenantId,
            'model' => \App\Models\Customer::class,
            'record_id' => $this->customer->id,
            'message_type' => CommunicationMessage::TYPE_COMMENT,
            'subtype' => 'record.comment',
            'body' => 'Old message',
            'body_plain' => 'Old message',
            'author_id' => $this->user->id,
            'author_type' => 'user',
            'status' => CommunicationMessage::STATUS_SENT,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
        DB::table('communication_messages')->where('id', $old->id)->update([
            'created_at' => now()->subMinutes(10),
        ]);

        $new = CommunicationMessage::create([
            'tenant_id' => $this->tenantId,
            'model' => \App\Models\Customer::class,
            'record_id' => $this->customer->id,
            'message_type' => CommunicationMessage::TYPE_COMMENT,
            'subtype' => 'record.comment',
            'body' => 'New message',
            'body_plain' => 'New message',
            'author_id' => $this->user->id,
            'author_type' => 'user',
            'status' => CommunicationMessage::STATUS_SENT,
        ]);

        // Cursor at "5 minutes ago" should return only the new message.
        $cursor = rawurlencode(now()->subMinutes(5)->toIso8601String());
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chatter/{$class}/{$this->customer->id}/since?since={$cursor}");

        $response->assertOk();
        $messages = $response->json('data.messages');

        $this->assertCount(1, $messages);
        $this->assertSame($new->id, $messages[0]['id']);
        $this->assertSame('New message', $messages[0]['body']);
        $this->assertSame('communication', $messages[0]['source']);
    }

    public function test_since_returns_all_messages_when_cursor_is_omitted(): void
    {
        $class = rawurlencode(\App\Models\Customer::class);

        CommunicationMessage::create([
            'tenant_id' => $this->tenantId,
            'model' => \App\Models\Customer::class,
            'record_id' => $this->customer->id,
            'message_type' => CommunicationMessage::TYPE_COMMENT,
            'subtype' => 'record.comment',
            'body' => 'First',
            'body_plain' => 'First',
            'author_id' => $this->user->id,
            'author_type' => 'user',
            'status' => CommunicationMessage::STATUS_SENT,
        ]);
        CommunicationMessage::create([
            'tenant_id' => $this->tenantId,
            'model' => \App\Models\Customer::class,
            'record_id' => $this->customer->id,
            'message_type' => CommunicationMessage::TYPE_COMMENT,
            'subtype' => 'record.comment',
            'body' => 'Second',
            'body_plain' => 'Second',
            'author_id' => $this->user->id,
            'author_type' => 'user',
            'status' => CommunicationMessage::STATUS_SENT,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chatter/{$class}/{$this->customer->id}/since");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.messages'));
        $this->assertNotNull($response->json('data.since'));
    }

    public function test_since_echoes_next_cursor(): void
    {
        $class = rawurlencode(\App\Models\Customer::class);

        CommunicationMessage::create([
            'tenant_id' => $this->tenantId,
            'model' => \App\Models\Customer::class,
            'record_id' => $this->customer->id,
            'message_type' => CommunicationMessage::TYPE_COMMENT,
            'subtype' => 'record.comment',
            'body' => 'Hello',
            'body_plain' => 'Hello',
            'author_id' => $this->user->id,
            'author_type' => 'user',
            'status' => CommunicationMessage::STATUS_SENT,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chatter/{$class}/{$this->customer->id}/since");

        $response->assertOk();
        $cursor = $response->json('data.since');
        $this->assertNotNull($cursor);

        // Re-polling with the echoed cursor returns no new messages.
        $next = $this->actingAs($this->user)
            ->getJson("/api/v1/chatter/{$class}/{$this->customer->id}/since?since=".rawurlencode($cursor));

        $next->assertOk();
        $this->assertCount(0, $next->json('data.messages'));
    }
}
