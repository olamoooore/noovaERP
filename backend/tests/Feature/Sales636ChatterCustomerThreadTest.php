<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Services\CommunicationEngine;
use Tests\TenantTestCase;

/**
 * Issue #636 (CHAT-UX3) — separate the "Message customer" thread from
 * internal notes in the platform chatter.
 *
 * Acceptance criteria exercised through the live router → middleware →
 * controller chain:
 *  - two composer modes on POST /chatter/{type}/{id}/messages:
 *      mode=customer → email/WhatsApp to the document contact, logged as
 *                      outbound;
 *      mode=note     → internal only (never delivered to the customer or to
 *                      partner-contact followers);
 *  - an inbound reply threads under the outbound message it answers;
 *  - every message carries a category (customer / internal / system) so the
 *    panel can filter All / Customer / Internal / System.
 */
class Sales636ChatterCustomerThreadTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array']);

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = (string) $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Ada Admin']);
        DB::table('public.users')->updateOrInsert(
            ['email' => $this->admin->email],
            ['name' => $this->admin->name, 'password' => $this->admin->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $this->admin->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->admin->assignRole(UserRole::ADMIN->spatieName());

        $this->customer = Customer::factory()->create([
            'name' => 'Issue 636 Customer',
            'email' => 'customer636@example.com',
            'phone' => '+2348000000636',
        ]);
    }

    public function test_log_note_is_internal_and_never_reaches_the_customer_or_partner_followers(): void
    {
        $quotation = $this->makeQuotation();
        $this->followAsPartner($quotation, 'partner636@example.com');

        $response = $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
            'mode' => 'note',
            'body' => 'Margin is thin, do not discount further.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category', 'internal');

        $message = CommunicationMessage::findOrFail($response->json('data.id'));
        $this->assertSame(CommunicationMessage::TYPE_INTERNAL_NOTE, $message->message_type);

        $this->assertSame(
            0,
            DB::table('communication_outbox')->where('message_id', $message->id)->whereNull('recipient_user_id')->count(),
            'an internal note must never be queued for an external recipient'
        );

        $shown = collect($this->actingAs($this->admin)->getJson($this->url($quotation->id))->json('data.messages'))
            ->firstWhere('id', $message->id);
        $this->assertSame('internal', $shown['category'] ?? null);
    }

    public function test_message_customer_emails_the_document_contact_and_is_logged_as_outbound(): void
    {
        $quotation = $this->makeQuotation(['contact_email' => 'buyer636@example.com']);

        $response = $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
            'mode' => 'customer',
            'channel' => 'email',
            'body' => 'Please find the revised quotation attached.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category', 'customer');
        $response->assertJsonPath('data.direction', 'outbound');
        $response->assertJsonPath('data.channel', 'email');

        $message = CommunicationMessage::findOrFail($response->json('data.id'));
        $this->assertSame(CommunicationMessage::TYPE_EMAIL, $message->message_type);
        $this->assertSame(Quotation::class, $message->model);
        $this->assertSame('outbound', $message->metadata['direction'] ?? null);
        $this->assertNotEmpty($message->metadata['message_id'] ?? null);
        $this->assertStringContainsString("[Quotation-{$quotation->id}]", (string) $message->subject);

        $outbox = DB::table('communication_outbox')->where('message_id', $message->id)->get();
        $this->assertSame(['buyer636@example.com'], $outbox->pluck('recipient_email')->all());
        $this->assertSame(['email'], $outbox->pluck('channel')->all());

        // The email carries the stamped Message-ID so a reply's In-Reply-To
        // can be threaded back under this message.
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString(
            trim($message->metadata['message_id'], '<>'),
            $sent->first()->getOriginalMessage()->getHeaders()->get('Message-ID')->getBodyAsString()
        );
    }

    public function test_message_customer_falls_back_to_the_customer_email(): void
    {
        $quotation = $this->makeQuotation();

        $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
            'mode' => 'customer',
            'body' => 'Hello from sales.',
        ])->assertCreated();

        $this->assertTrue(
            DB::table('communication_outbox')->where('recipient_email', 'customer636@example.com')->exists()
        );
    }

    public function test_message_customer_is_refused_when_the_document_has_no_reachable_contact(): void
    {
        $this->customer->update(['email' => null, 'phone' => null]);
        $quotation = $this->makeQuotation();

        $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
            'mode' => 'customer',
            'channel' => 'email',
            'body' => 'Nobody to send this to.',
        ])->assertStatus(422);

        config(['communication.channels.whatsapp.enabled' => true]);
        $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
            'mode' => 'customer',
            'channel' => 'whatsapp',
            'body' => 'Nobody to send this to.',
        ])->assertStatus(422);

        $this->assertSame(0, CommunicationMessage::where('body_plain', 'Nobody to send this to.')->count());
    }

    public function test_message_customer_by_whatsapp_queues_to_the_contact_phone_when_enabled(): void
    {
        $quotation = $this->makeQuotation(['contact_phone' => '+2348011112222']);

        // Disabled channel: refused instead of logging a message that can
        // never be delivered.
        config(['communication.channels.whatsapp.enabled' => false]);
        $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
            'mode' => 'customer',
            'channel' => 'whatsapp',
            'body' => 'WhatsApp hello',
        ])->assertStatus(422);

        config(['communication.channels.whatsapp.enabled' => true]);
        $response = $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
            'mode' => 'customer',
            'channel' => 'whatsapp',
            'body' => 'WhatsApp hello',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.channel', 'whatsapp');
        $message = CommunicationMessage::findOrFail($response->json('data.id'));
        $this->assertSame(CommunicationMessage::TYPE_WHATSAPP, $message->message_type);
        $this->assertSame(
            ['+2348011112222'],
            DB::table('communication_outbox')->where('message_id', $message->id)->where('channel', 'whatsapp')->pluck('recipient_phone')->all()
        );
    }

    public function test_inbound_reply_threads_under_the_outbound_message_it_answers(): void
    {
        $token = 'test-inbound-token-'.Str::random(16);
        putenv("INBOUND_EMAIL_TOKEN={$token}");

        try {
            $quotation = $this->makeQuotation();

            $outbound = $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
                'mode' => 'customer',
                'body' => 'Can you confirm the delivery date?',
            ]);
            $outbound->assertCreated();
            $outboundId = (int) $outbound->json('data.id');
            $messageId = CommunicationMessage::findOrFail($outboundId)->metadata['message_id'];

            // A later outbound message, so "latest outbound" is not the answer.
            $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
                'mode' => 'customer',
                'body' => 'Second question.',
            ])->assertCreated();

            $this->withHeaders(['X-Inbound-Email-Token' => $token])
                ->postJson('/api/v1/webhooks/inbound-email', [
                    'from' => ['address' => 'customer636@example.com', 'name' => 'Cus Tomer'],
                    'subject' => 'Re: your message',
                    'body_plain' => 'Delivery on Friday works.',
                    'in_reply_to' => $messageId,
                    'tenant_id' => $this->tenantId,
                ])
                ->assertCreated();

            $messages = collect($this->actingAs($this->admin)->getJson($this->url($quotation->id))->json('data.messages'));
            $reply = $messages->firstWhere('body', 'Delivery on Friday works.');

            $this->assertNotNull($reply);
            $this->assertSame($outboundId, $reply['parent_id'] ?? null);
            $this->assertSame('customer', $reply['category'] ?? null);
            $this->assertSame('inbound', $reply['direction'] ?? null);
        } finally {
            putenv('INBOUND_EMAIL_TOKEN');
        }
    }

    public function test_every_message_is_categorised_for_the_all_customer_internal_system_filter(): void
    {
        $quotation = $this->makeQuotation();

        $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
            'mode' => 'note', 'body' => 'internal one',
        ])->assertCreated();
        $this->actingAs($this->admin)->postJson($this->url($quotation->id, '/messages'), [
            'mode' => 'customer', 'body' => 'customer one',
        ])->assertCreated();
        app(CommunicationEngine::class)->logSystem(
            Quotation::class, (int) $quotation->id, 'system one', 'system.log', $this->tenantId
        );

        $byBody = collect($this->actingAs($this->admin)->getJson($this->url($quotation->id))->json('data.messages'))
            ->mapWithKeys(fn (array $m) => [$m['body'] => $m['category'] ?? null]);

        $this->assertSame('internal', $byBody['internal one']);
        $this->assertSame('customer', $byBody['customer one']);
        $this->assertSame('system', $byBody['system one']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function makeQuotation(array $overrides = []): Quotation
    {
        return Quotation::create(array_merge([
            'customer_id' => (int) $this->customer->id,
            'created_by' => $this->admin->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 10,
            'tax_amount' => 0,
            'total' => 10,
        ], $overrides));
    }

    private function followAsPartner(Quotation $quotation, string $email): void
    {
        $contact = CustomerContact::create([
            'customer_id' => (int) $this->customer->id,
            'name' => 'Pat Partner',
            'email' => $email,
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->url($quotation->id, '/followers'), ['contact_id' => (int) $contact->id])
            ->assertCreated();
    }

    private function url(int|string $quotationId, string $suffix = ''): string
    {
        return '/api/v1/chatter/'.rawurlencode(Quotation::class)."/{$quotationId}{$suffix}";
    }
}
