<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\DocumentFollower;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Platform\Notifications\NotificationLog;
use App\Platform\Notifications\NotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #635 (CHAT-UX2) — real @mentions.
 *
 * Before this fix every chatter message returned `mentions: []` and posting
 * `@someone` did nothing: no resolution, no notification, no follower. This
 * suite runs through the live router → middleware → controller chain:
 *
 *  - a mention resolves an exact tenant-user name or email handle, notifies
 *    the mentioned user through the platform NotificationService and follows
 *    them on the document (reason `auto:mention`);
 *  - the mention is persisted and returned by the read endpoints (show/since),
 *    not just on the 201;
 *  - unknown tokens and partial-name lookalikes are ignored;
 *  - a mentioned user who cannot view the record is NOT notified or followed
 *    (policy disclosed in ChatterMentionService — no chatter excerpt leaking
 *    to a user who would get a 403 from the chatter route itself);
 *  - scheduling an activity assigned to another user notifies the assignee.
 */
class Issue635ChatterMentionsTest extends TenantTestCase
{
    private const EVENT_MENTION = 'chatter.mention';

    private const EVENT_ASSIGNED = 'chatter.activity.assigned';

    private string $tenantId;

    private User $admin;

    private User $sam;

    private User $rita;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = $this->makeUser('admin', UserRole::ADMIN, 'Ada Admin', 'ada.admin@acme.test');
        $this->sam = $this->makeUser('sales', UserRole::SALES, 'Sam Sales', 'sam.sales@acme.test');
        $this->rita = $this->makeUser('sales', UserRole::SALES, 'Rita Rep', 'rita.rep@acme.test');

        $this->customer = Customer::factory()->create([
            'name' => 'Issue 635 Customer',
            'email' => 'customer635@example.com',
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Mentions Widget',
            'sku' => 'MEN-'.Str::upper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'track_inventory' => false,
        ]);

        Warehouse::factory()->create(['is_default' => true]);
    }

    // ─── mention → notification + auto-follow + API payload ────────────────

    public function test_mention_notifies_follows_and_is_returned_by_the_api(): void
    {
        $quotation = $this->makeQuotation(($this->admin->id));
        $body = 'Please review this @Sam Sales.';

        $response = $this->actingAs($this->admin)
            ->postJson($this->messagesUrl($quotation->id), ['body' => $body]);

        $response->assertCreated();
        $response->assertJsonCount(1, 'data.mentions');
        $response->assertJsonPath('data.mentions.0.id', $this->sam->id);
        $response->assertJsonPath('data.mentions.0.name', 'Sam Sales');

        $this->assertTrue(
            $this->hasNotification($this->sam, self::EVENT_MENTION),
            'the mentioned user must receive a notification'
        );

        $log = NotificationLog::query()
            ->where('user_id', (int) $this->sam->id)
            ->where('event', self::EVENT_MENTION)
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status, 'the mail leg must go through NotificationService (Mail is faked)');

        $follower = DocumentFollower::query()
            ->where('document_type', Quotation::class)
            ->where('document_id', (int) $quotation->id)
            ->where('user_id', (int) $this->sam->id)
            ->first();

        $this->assertNotNull($follower, 'the mentioned user must become a follower');
        $this->assertSame(DocumentFollower::SOURCE_MENTION, $follower->follow_source);
    }

    public function test_mention_is_returned_by_show_and_since_endpoints(): void
    {
        $quotation = $this->makeQuotation(($this->admin->id));
        $body = 'Looping in @Sam Sales and @rita.rep for visibility.';

        $this->actingAs($this->admin)
            ->postJson($this->messagesUrl($quotation->id), ['body' => $body])
            ->assertCreated()
            ->assertJsonCount(2, 'data.mentions');

        $show = $this->actingAs($this->admin)
            ->getJson($this->chatterUrl($quotation->id));
        $show->assertOk();

        $message = collect($show->json('data.messages'))->firstWhere('body', $body);
        $this->assertNotNull($message, 'the posted message must be part of the chatter bundle');
        $this->assertSame(
            [$this->sam->id, $this->rita->id],
            collect($message['mentions'])->pluck('id')->all()
        );

        $since = $this->actingAs($this->admin)
            ->getJson($this->chatterUrl($quotation->id).'/since?since='.urlencode(now()->subMinute()->toIso8601String()));
        $since->assertOk();

        $sinceMessage = collect($since->json('data.messages'))->firstWhere('body', $body);
        $this->assertNotNull($sinceMessage, 'the polling endpoint must return the message too');
        $this->assertSame(
            [$this->sam->id, $this->rita->id],
            collect($sinceMessage['mentions'])->pluck('id')->all()
        );
    }

    public function test_unknown_and_partial_tokens_are_ignored(): void
    {
        $quotation = $this->makeQuotation(($this->admin->id));

        $response = $this->actingAs($this->admin)->postJson($this->messagesUrl($quotation->id), [
            'body' => 'Hello @nobody and @Samuelson and @Rita Reputation — no fuzzy matches.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.mentions', []);

        $this->assertFalse($this->hasNotification($this->sam, self::EVENT_MENTION));
        $this->assertFalse($this->hasNotification($this->rita, self::EVENT_MENTION));
        $this->assertFalse(
            DocumentFollower::query()
                ->where('document_type', Quotation::class)
                ->where('document_id', (int) $quotation->id)
                ->where('follow_source', DocumentFollower::SOURCE_MENTION)
                ->exists(),
            'an unresolved token must not follow anyone'
        );
    }

    public function test_mention_of_a_user_without_record_access_is_not_notified_or_followed(): void
    {
        // Vendor chatter is gated by accounting.view; the SALES role does not
        // hold it, so notifying Sam with the message excerpt would leak a
        // record he would get a 403 on. Policy: the token still resolves and
        // is returned in the message, but notification and auto-follow are
        // skipped (disclosed in ChatterMentionService).
        $vendor = Vendor::factory()->create(['name' => 'Issue 635 Vendor']);

        $response = $this->actingAs($this->admin)->postJson(
            $this->messagesUrl($vendor->id, Vendor::class),
            ['body' => 'Vendor issue — @Sam Sales FYI.'],
        );

        $response->assertCreated();
        $response->assertJsonPath('data.mentions.0.id', $this->sam->id);

        $this->assertFalse(
            $this->hasNotification($this->sam, self::EVENT_MENTION),
            'a mentioned user without the record view permission must not be notified'
        );
        $this->assertFalse(
            DocumentFollower::query()
                ->where('document_type', Vendor::class)
                ->where('document_id', (int) $vendor->id)
                ->where('user_id', (int) $this->sam->id)
                ->exists(),
            'a mentioned user without record access must not auto-follow'
        );
    }

    public function test_mention_respects_the_mail_opt_out_preference(): void
    {
        NotificationPreference::create([
            'tenant_id' => $this->tenantId,
            'user_id' => (int) $this->sam->id,
            'event' => self::EVENT_MENTION,
            'channel' => 'mail',
            'mail_enabled' => false,
        ]);

        $quotation = $this->makeQuotation(($this->admin->id));

        $this->actingAs($this->admin)
            ->postJson($this->messagesUrl($quotation->id), ['body' => 'Opted out @Sam Sales'])
            ->assertCreated()
            ->assertJsonPath('data.mentions.0.id', $this->sam->id);

        $this->assertFalse(
            $this->hasNotification($this->sam, self::EVENT_MENTION),
            'NotificationService must honour the stored opt-out preference'
        );
    }

    // ─── activity assignment notification ───────────────────────────────────

    public function test_activity_assigned_to_another_user_notifies_the_assignee(): void
    {
        $quotation = $this->makeQuotation(($this->admin->id));

        $response = $this->actingAs($this->admin)->postJson($this->activitiesUrl($quotation->id), [
            'activity_type' => 'call',
            'summary' => 'Issue 635 assigned call',
            'assigned_to_id' => (int) $this->sam->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.assigned_to.id', $this->sam->id);

        $this->assertTrue(
            $this->hasNotification($this->sam, self::EVENT_ASSIGNED),
            'the assignee must be notified directly, not only through subscriptions'
        );
        $this->assertFalse(
            $this->hasNotification($this->admin, self::EVENT_ASSIGNED),
            'the actor assigning to themselves must not be notified'
        );

        // Self-assignment stays frictionless and silent.
        $this->actingAs($this->admin)->postJson($this->activitiesUrl($quotation->id), [
            'activity_type' => 'todo',
            'summary' => 'Issue 635 self todo',
            'assigned_to_id' => (int) $this->admin->id,
        ])->assertCreated();

        $this->assertSame(
            0,
            NotificationLog::query()
                ->where('user_id', (int) $this->admin->id)
                ->where('event', self::EVENT_ASSIGNED)
                ->count(),
            'self-assignment must not produce an assignee notification'
        );
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function makeUser(string $centralRole, UserRole $spatieRole, string $name, string $email): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole->spatieName());

        return $user;
    }

    private function makeQuotation(int $createdBy): Quotation
    {
        return Quotation::create([
            'customer_id' => (int) $this->customer->id,
            'created_by' => $createdBy,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 10,
            'tax_amount' => 0,
            'total' => 10,
        ]);
    }

    private function chatterUrl(int|string $id, string $subjectType = Quotation::class): string
    {
        return '/api/v1/chatter/'.rawurlencode($subjectType)."/{$id}";
    }

    private function messagesUrl(int|string $id, string $subjectType = Quotation::class): string
    {
        return $this->chatterUrl($id, $subjectType).'/messages';
    }

    private function activitiesUrl(int|string $id, string $subjectType = Quotation::class): string
    {
        return $this->chatterUrl($id, $subjectType).'/activities';
    }

    private function hasNotification(User $user, string $event): bool
    {
        return NotificationLog::query()
            ->where('user_id', (int) $user->id)
            ->where('event', $event)
            ->exists();
    }
}
