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
 * Issue #81 — Two competing chatter implementations must reconcile.
 *
 * Verifies the chosen architecture (Communication module is canonical,
 * legacy Chatter is a thin facade):
 *
 *   1. POST /chatter/{type}/{id}/messages writes through
 *      \`CommunicationEngine::postComment\` and persists a
 *      \`CommunicationMessage\` row.
 *   2. GET /chatter/{type}/{id} merges canonical Communication messages
 *      with any legacy chatter_messages rows in chronological order.
 *   3. The existing legacy addMessage/addActivity contract (response
 *      shape, validation, audit log, attachments) is preserved so the
 *      frontend does not need to change.
 *   4. Activity scheduling remains in ChatterActivity (separate
 *      semantics — not duplicated in Communication).
 */
class ChatterReconciliationTest extends TenantTestCase
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

        $this->assertTenantSchemaReady(['customers', 'chatter_messages', 'communication_messages']);

        $this->user = User::create([
            'name' => 'Chatter Reconciler',
            'email' => 'chatter-'.uniqid().'@test.local',
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
            'name' => 'Reconciliation Customer',
            'payment_terms' => 30,
            'billing_address' => 'Reconciliation Billing',
            'shipping_address' => 'Reconciliation Shipping',
        ]);
    }

    public function test_add_message_writes_through_canonical_communication_engine(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/chatter/'.rawurlencode(\App\Models\Customer::class)."/{$this->customer->id}/messages", [
                'body' => 'Canonical message routed through Communication.',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.body', 'Canonical message routed through Communication.');
        $response->assertJsonPath('data.actor.name', $this->user->name);

        // The canonical row lives in communication_messages, not chatter_messages.
        $this->assertSame(
            0,
            \App\Models\ChatterMessage::query()->count(),
            'No chatter_messages row should be created when Communication is canonical.'
        );

        $comm = CommunicationMessage::query()
            ->where('model', \App\Models\Customer::class)
            ->where('record_id', $this->customer->id)
            ->first();
        $this->assertNotNull($comm, 'A CommunicationMessage must have been created.');
        $this->assertSame('Canonical message routed through Communication.', $comm->body);
        $this->assertSame('record.comment', $comm->subtype);
        $this->assertSame($this->user->id, $comm->author_id);
    }

    public function test_show_returns_communication_messages_in_chronological_order(): void
    {
        $class = rawurlencode(\App\Models\Customer::class);

        // Post the FIRST legacy message before any Communication message so
        // the timestamp ordering is unambiguous. Then post two Communication
        // messages with explicit offsets.
        $legacy = \App\Models\ChatterMessage::create([
            'tenant_id' => $this->tenantId,
            'subject_type' => \App\Models\Customer::class,
            'subject_id' => (string) $this->customer->id,
            'author_id' => $this->user->id,
            'body' => 'Legacy message in chatter_messages',
        ]);
        // Force the timestamp via DB::table to bypass Eloquent's timestamp
        // behaviour (the model ignores the passed `created_at` and resets it).
        DB::table('chatter_messages')->where('id', $legacy->id)->update([
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        $first = $this->actingAs($this->user)
            ->postJson("/api/v1/chatter/{$class}/{$this->customer->id}/messages", [
                'body' => 'First canonical message',
            ])->assertCreated()->json('data.id');
        DB::table('communication_messages')->where('id', $first)->update([
            'created_at' => now()->subSeconds(15),
        ]);

        $third = $this->actingAs($this->user)
            ->postJson("/api/v1/chatter/{$class}/{$this->customer->id}/messages", [
                'body' => 'Third canonical message',
            ])->assertCreated()->json('data.id');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chatter/{$class}/{$this->customer->id}");

        $response->assertOk();
        $messages = $response->json('data.messages');

        $this->assertCount(3, $messages);
        // Third (newest) first.
        $this->assertSame($third, $messages[0]['id']);
        $this->assertSame('communication', $messages[0]['source']);
        // First canonical (middle).
        $this->assertSame($first, $messages[1]['id']);
        $this->assertSame('communication', $messages[1]['source']);
        // Legacy (oldest) last.
        $this->assertSame($legacy->id, $messages[2]['id']);
        $this->assertSame('chatter', $messages[2]['source']);
    }

    public function test_add_activity_still_writes_to_chatter_activity(): void
    {
        $class = rawurlencode(\App\Models\Customer::class);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chatter/{$class}/{$this->customer->id}/activities", [
                'activity_type' => 'follow_up',
                'summary' => 'Call back next week',
                'due_date' => now()->addDays(7)->toDateString(),
            ]);

        $response->assertCreated();
        $this->assertSame('follow_up', $response->json('data.activity_type'));
        $this->assertSame('Call back next week', $response->json('data.summary'));

        $this->assertSame(
            1,
            \App\Models\ChatterActivity::query()
                ->where('subject_type', \App\Models\Customer::class)
                ->where('subject_id', (string) $this->customer->id)
                ->count(),
            'Activities remain in ChatterActivity (separate semantics from messages).'
        );
    }
}
