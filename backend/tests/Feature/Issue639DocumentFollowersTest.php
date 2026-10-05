<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Platform\Notifications\NotificationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #639 (CHATTER-EPIC #628) — document followers.
 *
 * Odoo keeps a follower list per record with per-subtype subscription flags
 * and lets customer contacts follow a document so "Send message" emails reach
 * them. NoovaERP had no followers at all: mentions were a stub (#635) and a
 * chatter post notified nobody.
 *
 * Every assertion below runs through the live router → middleware →
 * controller chain (postJson/getJson/deleteJson), never a direct service
 * call:
 *
 *  - follow / list / unfollow round trip on /api/v1/chatter/{type}/{id}/followers
 *  - a user without the document's mapped view permission gets 403
 *  - creating a quotation auto-follows the creator and the resolved salesperson
 *  - posting a message notifies only followers whose message flag is on
 *  - scheduling an activity notifies only followers whose activity flag is on
 *  - a stage change notifies only followers whose stage flag is on, and the
 *    actor who moved the stage auto-follows
 *  - a partner (customer contact) follower receives the customer-message
 *    email through the Communication engine outbox
 *  - approving a document auto-follows the approver
 */
class Issue639DocumentFollowersTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private User $sales;

    private User $salesperson;

    private Customer $customer;

    private Product $product;

    private string $quotationFqcn;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = $this->makeUser('admin', UserRole::ADMIN, 'Ada Admin');
        $this->sales = $this->makeUser('sales', UserRole::SALES, 'Sam Sales');
        $this->salesperson = $this->makeUser('sales', UserRole::SALES, 'Rita Rep');

        $this->customer = Customer::factory()->create([
            'name' => 'Issue 639 Customer',
            'email' => 'customer639@example.com',
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Followers Widget',
            'sku' => 'FOL-'.Str::upper(Str::random(6)),
            'price' => 10,
            'cost' => 4,
            'track_inventory' => false,
        ]);

        // No approval threshold so lifecycle routes proceed ungated; a
        // default warehouse for anything that touches allocation.
        Setting::setValue(\App\Services\SalesApprovalGate::SETTING_KEY, null);
        Warehouse::factory()->create(['is_default' => true]);

        $this->quotationFqcn = Quotation::class;
    }

    // ─── follow / list / unfollow through the real routes ───────────────────

    public function test_follow_list_and_unfollow_round_trip_through_real_routes(): void
    {
        $quotation = $this->makeQuotation();

        $url = $this->followersUrl($quotation->id);

        $follow = $this->actingAs($this->admin)->postJson($url, [
            'subscribe_messages' => true,
            'subscribe_activities' => false,
            'subscribe_stage_changes' => true,
        ]);

        $follow->assertCreated();
        $this->assertSame('internal', $follow->json('data.follower_type'));
        $this->assertSame($this->admin->id, $follow->json('data.user.id'));
        $this->assertTrue($follow->json('data.subscribe_activities') === false);

        // Idempotent: following again updates flags, never duplicates a row.
        $this->actingAs($this->admin)->postJson($url, ['subscribe_activities' => true])->assertCreated();
        $this->assertCount(1, $this->actingAs($this->admin)->getJson($url)->json('data'));

        $list = $this->actingAs($this->admin)->getJson($url);
        $list->assertOk();
        $this->assertSame([$this->admin->id], collect($list->json('data'))->pluck('user.id')->all());

        $followerId = $list->json('data.0.id');
        $this->actingAs($this->admin)
            ->deleteJson($url.'/'.$followerId)
            ->assertOk();

        $after = $this->actingAs($this->admin)->getJson($url);
        $after->assertOk();
        $this->assertSame([], $after->json('data'));
    }

    public function test_user_without_the_documents_view_permission_is_denied_403(): void
    {
        // Vendor chatter is gated by accounting.view — the Sales role does not
        // hold it (RolePermissionSeeder PERMISSION_MATRIX), so every follower
        // endpoint must 403 before the record even resolves.
        $url = '/api/v1/chatter/'.rawurlencode(Vendor::class).'/999999/followers';

        $this->actingAs($this->sales)->getJson($url)->assertStatus(403);
        $this->actingAs($this->sales)->postJson($url, [])->assertStatus(403);
        $this->actingAs($this->sales)->deleteJson($url.'/1')->assertStatus(403);
    }

    // ─── auto-follow rules ──────────────────────────────────────────────────

    public function test_creating_a_quotation_auto_follows_creator_and_salesperson(): void
    {
        $quotationId = $this->createQuotationThroughRoute(['salesperson' => 'Rita Rep']);

        $followers = $this->actingAs($this->admin)
            ->getJson($this->followersUrl($quotationId))
            ->json('data');

        $byUser = collect($followers)->keyBy('user.id');

        $this->assertTrue($byUser->has($this->admin->id), 'creator must auto-follow');
        $this->assertSame('auto:creator', $byUser[$this->admin->id]['follow_source']);

        $this->assertTrue($byUser->has($this->salesperson->id), 'free-text salesperson must resolve to a user and follow');
        $this->assertSame('auto:salesperson', $byUser[$this->salesperson->id]['follow_source']);
    }

    public function test_approving_a_document_auto_follows_the_approver(): void
    {
        // Created by Sam so Ada appears on the follower list only through the
        // approval decision.
        $quotation = $this->makeQuotation((int) $this->sales->id);

        $request = \App\Models\ApprovalRequest::create([
            'request_type' => \App\Services\SalesApprovalGate::REQUEST_TYPE_QUOTATION,
            'subject_type' => Quotation::class,
            'subject_id' => (int) $quotation->id,
            'request_number' => \App\Models\ApprovalRequest::generateRequestNumber(),
            'amount' => 10,
            'reason' => 'Issue 639 approver follow',
            'requested_by' => (int) $this->sales->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/approvals/{$request->id}/approve", ['notes' => 'Approved in test'])
            ->assertOk();

        $follower = \App\Models\DocumentFollower::query()
            ->where('document_type', Quotation::class)
            ->where('document_id', (int) $quotation->id)
            ->where('user_id', (int) $this->admin->id)
            ->first();

        $this->assertNotNull($follower, 'the approver must follow the document they approved');
        $this->assertSame('auto:approver', $follower->follow_source);
    }

    // ─── delivery honours the subscription flags ────────────────────────────

    public function test_message_notifies_only_followers_with_the_message_subscription(): void
    {
        $quotation = $this->makeQuotation();

        // Rita keeps every flag; Sam switches messages off.
        $this->actingAs($this->salesperson)->postJson($this->followersUrl($quotation->id), [])->assertCreated();
        $this->actingAs($this->sales)->postJson($this->followersUrl($quotation->id), [
            'subscribe_messages' => false,
        ])->assertCreated();

        $this->actingAs($this->admin)
            ->postJson($this->messagesUrl($quotation->id), ['body' => 'Issue 639 message'])
            ->assertCreated();

        $this->assertTrue(
            $this->hasNotification($this->salesperson, 'chatter.message'),
            'follower with subscribe_messages on must be notified'
        );
        $this->assertFalse(
            $this->hasNotification($this->sales, 'chatter.message'),
            'follower with subscribe_messages off must not be notified'
        );
        $this->assertFalse(
            $this->hasNotification($this->admin, 'chatter.message'),
            'the message author must not notify themselves'
        );
    }

    public function test_activity_notifies_only_followers_with_the_activity_subscription(): void
    {
        $quotation = $this->makeQuotation();

        $this->actingAs($this->salesperson)->postJson($this->followersUrl($quotation->id), [])->assertCreated();
        $this->actingAs($this->sales)->postJson($this->followersUrl($quotation->id), [
            'subscribe_activities' => false,
        ])->assertCreated();

        $this->actingAs($this->admin)
            ->postJson($this->activitiesUrl($quotation->id), [
                'activity_type' => 'call',
                'summary' => 'Issue 639 follow-up call',
            ])
            ->assertCreated();

        $this->assertTrue($this->hasNotification($this->salesperson, 'chatter.activity'));
        $this->assertFalse($this->hasNotification($this->sales, 'chatter.activity'));
    }

    public function test_stage_change_notifies_only_flagged_followers_and_the_actor_follows(): void
    {
        $quotation = $this->makeQuotation();

        $this->actingAs($this->salesperson)->postJson($this->followersUrl($quotation->id), [])->assertCreated();
        $this->actingAs($this->sales)->postJson($this->followersUrl($quotation->id), [
            'subscribe_stage_changes' => false,
        ])->assertCreated();

        // Stage transition through the real route (draft -> cancelled).
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/cancel")
            ->assertOk();

        $this->assertTrue(
            $this->hasNotification($this->salesperson, 'chatter.stage_change'),
            'follower with subscribe_stage_changes on must be notified'
        );
        $this->assertFalse(
            $this->hasNotification($this->sales, 'chatter.stage_change'),
            'follower with subscribe_stage_changes off must not be notified'
        );
        $this->assertFalse(
            $this->hasNotification($this->admin, 'chatter.stage_change'),
            'the actor who moved the stage must not notify themselves'
        );

        $actorFollows = \App\Models\DocumentFollower::query()
            ->where('document_type', Quotation::class)
            ->where('document_id', (int) $quotation->id)
            ->where('user_id', (int) $this->admin->id)
            ->where('follow_source', 'auto:stage_change')
            ->exists();

        $this->assertTrue($actorFollows, 'the user who moved the stage must follow the document');
    }

    // ─── partner (customer contact) followers ───────────────────────────────

    public function test_partner_contact_follower_receives_the_customer_message_email(): void
    {
        $quotation = $this->makeQuotation();

        $contact = CustomerContact::create([
            'customer_id' => (int) $this->customer->id,
            'name' => 'Cora Contact',
            'email' => 'cora.contact@example.com',
        ]);

        $follow = $this->actingAs($this->admin)->postJson($this->followersUrl($quotation->id), [
            'contact_id' => (int) $contact->id,
        ]);

        $follow->assertCreated();
        $this->assertSame('partner', $follow->json('data.follower_type'));
        $this->assertSame($contact->id, $follow->json('data.contact.id'));

        $this->actingAs($this->admin)
            ->postJson($this->messagesUrl($quotation->id), ['body' => 'Issue 639 customer message'])
            ->assertCreated();

        $outbox = DB::table('communication_outbox')
            ->where('channel', 'email')
            ->where('recipient_email', 'cora.contact@example.com')
            ->first();

        $this->assertNotNull(
            $outbox,
            'a partner follower must receive the customer-message email through the Communication outbox'
        );
        $this->assertNotSame('failed', $outbox->status);
    }

    public function test_contact_followers_must_belong_to_the_documents_customer(): void
    {
        $quotation = $this->makeQuotation();

        $otherCustomer = Customer::factory()->create(['name' => 'Someone Else']);
        $stranger = CustomerContact::create([
            'customer_id' => (int) $otherCustomer->id,
            'name' => 'Stranger',
            'email' => 'stranger@example.com',
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->followersUrl($quotation->id), ['contact_id' => (int) $stranger->id])
            ->assertStatus(422);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function makeUser(string $centralRole, UserRole $spatieRole, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
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

    /**
     * A bare draft quotation. `created_by` is NOT NULL on quotations, so the
     * creator auto-follows as soon as the rule exists; tests that need to
     * control the follower list pass an explicit creator.
     */
    private function makeQuotation(?int $createdBy = null): Quotation
    {
        return Quotation::create([
            'customer_id' => (int) $this->customer->id,
            'created_by' => $createdBy ?? $this->admin->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 10,
            'tax_amount' => 0,
            'total' => 10,
        ]);
    }

    private function createQuotationThroughRoute(array $overrides = []): int
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/quotations', array_merge([
            'customer_id' => (int) $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => (int) $this->product->id,
                'description' => $this->product->name,
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
            ]],
        ], $overrides));

        $response->assertCreated();

        return (int) ($response->json('data.id') ?? $response->json('id'));
    }

    private function followersUrl(int|string $quotationId): string
    {
        return '/api/v1/chatter/'.rawurlencode($this->quotationFqcn)."/{$quotationId}/followers";
    }

    private function messagesUrl(int|string $quotationId): string
    {
        return '/api/v1/chatter/'.rawurlencode($this->quotationFqcn)."/{$quotationId}/messages";
    }

    private function activitiesUrl(int|string $quotationId): string
    {
        return '/api/v1/chatter/'.rawurlencode($this->quotationFqcn)."/{$quotationId}/activities";
    }

    private function hasNotification(User $user, string $event): bool
    {
        return NotificationLog::query()
            ->where('user_id', (int) $user->id)
            ->where('event', $event)
            ->exists();
    }
}
