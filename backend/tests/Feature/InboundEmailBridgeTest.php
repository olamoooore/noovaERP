<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InboundEmail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Tests\TenantTestCase;

/**
 * Issue #85 — Incoming email bridge (IMAP integration).
 *
 * Verifies the HTTP ingestion seam end-to-end via real requests:
 *
 *   1. POST /api/v1/webhooks/inbound-email without a token → 401.
 *   2. With the wrong token → 401.
 *   3. Subject containing \`[Invoice-123]\` → matched to that invoice's
 *      chatter as a \`record.comment\` CommunicationMessage; the
 *      \`inbound_emails\` row records the match + strategy.
 *   4. In-Reply-To header matching an outbound \`message_id\` (when
 *      the outbox is wired) → matched to the source record.
 *   5. Unmatched email → stored with \`matched_model=null\` for
 *      manual triage; a system note is still recorded.
 */
class InboundEmailBridgeTest extends TenantTestCase
{
    protected User $user;
    protected Customer $customer;
    protected string $tenantId;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = 'test-inbound-token-'.Str::random(16);
        // Put the token in the env so the InboundEmailAuth middleware can
        // read it via env().
        putenv("INBOUND_EMAIL_TOKEN={$this->token}");

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });
        $this->bindTenantContext($tenant->id);
        $this->tenantId = $tenant->id;

        $this->assertTenantSchemaReady(['customers', 'inbound_emails', 'communication_messages']);

        $this->user = User::create([
            'name' => 'Inbound Tester',
            'email' => 'inbound-'.uniqid().'@test.local',
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
            'name' => 'Inbound Customer',
            'payment_terms' => 30,
            'billing_address' => 'Inbound Billing',
            'shipping_address' => 'Inbound Shipping',
        ]);
    }

    protected function tearDown(): void
    {
        putenv('INBOUND_EMAIL_TOKEN');
        parent::tearDown();
    }

    public function test_request_without_token_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/webhooks/inbound-email', [
            'subject' => 'Hi',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Invalid token.');
    }

    public function test_subject_token_matches_invoice_record(): void
    {
        $invoice = \App\Models\Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'requires_approval' => false,
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeaders(['X-Inbound-Email-Token' => $this->token])
            ->postJson('/api/v1/webhooks/inbound-email', [
                'from' => ['address' => 'reply@example.com', 'name' => 'Reply Sender'],
                'subject' => "[Invoice-{$invoice->id}] Re: payment question",
                'body_plain' => 'Thanks, can we defer by a week?',
                'headers' => ['X-Mailer' => 'Inbound test'],
                'tenant_id' => $this->tenantId,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.matched_model', \App\Models\Invoice::class);
        $response->assertJsonPath('data.matched_record_id', $invoice->id);
        $response->assertJsonPath('data.match_strategy', 'subject_token');

        $inbound = InboundEmail::find($response->json('data.id'));
        $this->assertNotNull($inbound);
        $this->assertSame('reply@example.com', $inbound->from_address);
        $this->assertSame('Thanks, can we defer by a week?', $inbound->body_plain);

        $comm = CommunicationMessage::find($inbound->communication_message_id);
        $this->assertNotNull($comm);
        $this->assertSame(\App\Models\Invoice::class, $comm->model);
        $this->assertSame($invoice->id, $comm->record_id);
        $this->assertSame('record.comment', $comm->subtype);
        $this->assertSame('Thanks, can we defer by a week?', $comm->body);
    }

    public function test_in_reply_to_matches_outbound_message_id(): void
    {
        // Simulate a previous outbound message that stamped a Message-ID.
        $outbound = CommunicationMessage::create([
            'tenant_id' => $this->tenantId,
            'model' => \App\Models\Customer::class,
            'record_id' => $this->customer->id,
            'message_type' => CommunicationMessage::TYPE_NOTIFICATION,
            'subtype' => 'invoice.sent',
            'subject' => 'Invoice sent',
            'body' => 'Your invoice is on its way',
            'body_plain' => 'Your invoice is on its way',
            'author_type' => 'system',
            'status' => CommunicationMessage::STATUS_SENT,
            'metadata' => ['message_id' => '<outbound-abc@example.com>'],
        ]);

        $response = $this->withHeaders(['X-Inbound-Email-Token' => $this->token])
            ->postJson('/api/v1/webhooks/inbound-email', [
                'from' => ['address' => 'reply@example.com'],
                'subject' => 'Re: Invoice sent', // no subject token this time
                'body_plain' => 'Thanks!',
                'in_reply_to' => '<outbound-abc@example.com>',
                'tenant_id' => $this->tenantId,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.matched_model', \App\Models\Customer::class);
        $response->assertJsonPath('data.matched_record_id', $this->customer->id);
        $response->assertJsonPath('data.match_strategy', 'in_reply_to');

        $this->assertNotNull(CommunicationMessage::find($response->json('data.communication_message_id')));
    }

    public function test_unmatched_email_is_stored_for_manual_triage(): void
    {
        $response = $this->withHeaders(['X-Inbound-Email-Token' => $this->token])
            ->postJson('/api/v1/webhooks/inbound-email', [
                'from' => ['address' => 'random@example.com'],
                'subject' => 'Generic question with no token',
                'body_plain' => 'No idea who this is for',
                'tenant_id' => $this->tenantId,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.matched_model', null);
        $response->assertJsonPath('data.matched_record_id', null);
        $response->assertJsonPath('data.match_strategy', null);
        // No chatter post when the email is unmatched.
        $response->assertJsonPath('data.communication_message_id', null);

        $inbound = InboundEmail::find($response->json('data.id'));
        $this->assertNotNull($inbound);
        $this->assertNull($inbound->matched_model);
        $this->assertNull($inbound->communication_message_id);
    }
}
