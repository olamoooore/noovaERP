<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #441: merge duplicate customers — the decision-free gaps left open
 * after #435 delivered the base merge endpoint.
 *
 *  1. Per-field survivor selection: `field_choices` {field: survivor|merged}
 *     over a whitelist of customer master fields; chosen values land on the
 *     survivor in the same request and are recorded in the merge history.
 *  2. Tags (`taggables`) and chatter (`communication_messages`,
 *     `chatter_messages`, `chatter_activities`, `document_followers`), which
 *     link by record type + id, follow the merged customer to the survivor.
 *  3. The duplicate guard's 409 carries a "merge into existing" action hint.
 *
 * All assertions go through the real HTTP routes.
 */
class Issue441CustomerMergeCompletionTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Issue 441 Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── 1. Per-field survivor selection ────────────────────────────

    public function test_field_choices_copy_the_chosen_values_onto_the_survivor_and_are_recorded(): void
    {
        $survivor = Customer::factory()->create([
            'name' => 'Survivor Trading',
            'email' => 'survivor@example.test',
            'phone' => '61110000',
            'website' => null,
            'notes' => 'Survivor notes',
        ]);
        $merged = Customer::factory()->create([
            'name' => 'Survivor Trading Pte Ltd',
            'email' => 'merged@example.test',
            'phone' => '62220000',
            'website' => 'https://merged.example.test',
            'notes' => 'Merged notes',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$survivor->id}/merge", [
                'merged_customer_id' => $merged->id,
                'reason' => 'Duplicate from import',
                'field_choices' => [
                    'name' => 'merged',
                    'phone' => 'merged',
                    'website' => 'merged',
                    'email' => 'survivor',
                ],
            ]);

        $this->assertSame(200, $response->getStatusCode(), 'Merge with field choices must succeed: '.$response->getContent());

        $fresh = Customer::findOrFail($survivor->id);
        $this->assertSame('Survivor Trading Pte Ltd', $fresh->name, 'A field chosen from the merged record must be copied onto the survivor.');
        $this->assertSame('62220000', $fresh->phone);
        $this->assertSame('https://merged.example.test', $fresh->website);
        $this->assertSame('survivor@example.test', $fresh->email, 'A field chosen from the survivor must keep the survivor value.');
        $this->assertSame('Survivor notes', $fresh->notes, 'A field without a choice keeps the survivor value.');

        // The canonical Contact Master contact mirrors the chosen identity.
        $contact = DB::table('core_contacts')->where('id', $fresh->core_contact_uuid)->first();
        $this->assertNotNull($contact);
        $this->assertSame('Survivor Trading Pte Ltd', $contact->display_name);

        $history = DB::table('contact_merge_history')
            ->where('primary_contact_id', $fresh->core_contact_uuid)
            ->where('merged_contact_id', Customer::findOrFail($merged->id)->core_contact_uuid)
            ->first();
        $this->assertNotNull($history, 'The merge history row must exist.');

        $details = json_decode((string) $history->merge_details, true);
        $choices = collect($details)->first(fn ($entry) => is_array($entry) && isset($entry['field_choices']));
        $this->assertNotNull($choices, 'The merge history must record the field choices: '.$history->merge_details);
        $this->assertSame('merged', $choices['field_choices']['phone']['source']);
        $this->assertSame('61110000', $choices['field_choices']['phone']['previous']);
        $this->assertSame('62220000', $choices['field_choices']['phone']['value']);
        $this->assertSame('survivor', $choices['field_choices']['email']['source']);
        $this->assertContains('roles', $details, 'The existing moved-group entries stay in the history as strings.');

        $response->assertJsonPath('field_choices.phone', 'merged');
    }

    public function test_field_choices_reject_fields_outside_the_whitelist_and_bad_sources(): void
    {
        $survivor = Customer::factory()->create();
        $merged = Customer::factory()->create();

        $badField = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$survivor->id}/merge", [
                'merged_customer_id' => $merged->id,
                'field_choices' => ['credit_balance' => 'merged'],
            ]);
        $this->assertSame(422, $badField->getStatusCode(), 'Balances/stats are not selectable master fields: '.$badField->getContent());

        $badSource = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$survivor->id}/merge", [
                'merged_customer_id' => $merged->id,
                'field_choices' => ['email' => 'both'],
            ]);
        $this->assertSame(422, $badSource->getStatusCode(), 'Only survivor|merged are valid sources: '.$badSource->getContent());

        $this->assertTrue((bool) Customer::findOrFail($merged->id)->is_active, 'A rejected merge must not archive anything.');
    }

    // ─── 2. Tags + chatter follow the merge ─────────────────────────

    public function test_tags_and_chatter_are_repointed_to_the_survivor(): void
    {
        $survivor = Customer::factory()->create(['name' => 'Tag Survivor']);
        $merged = Customer::factory()->create(['name' => 'Tag Duplicate']);

        $tags = app(TagService::class);
        $tags->attach($survivor, ['VIP'], $this->admin->id);
        $tags->attach($merged, ['VIP', 'Wholesale'], $this->admin->id);

        $type = rawurlencode(Customer::class);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/chatter/{$type}/{$merged->id}/messages", ['body' => 'Note on the duplicate record.'])
            ->assertCreated();

        DB::table('chatter_messages')->insert([
            'tenant_id' => $this->tenantUuid,
            'subject_type' => Customer::class,
            'subject_id' => (string) $merged->id,
            'author_id' => $this->admin->id,
            'body' => 'Legacy chatter note',
        ]);
        DB::table('chatter_activities')->insert([
            'tenant_id' => $this->tenantUuid,
            'subject_type' => Customer::class,
            'subject_id' => (string) $merged->id,
            'created_by_id' => $this->admin->id,
            'activity_type' => 'call',
            'summary' => 'Call the duplicate',
        ]);
        DB::table('document_followers')->insert([
            ['tenant_id' => $this->tenantUuid, 'document_type' => Customer::class, 'document_id' => $survivor->id, 'user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenantUuid, 'document_type' => Customer::class, 'document_id' => $merged->id, 'user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$survivor->id}/merge", ['merged_customer_id' => $merged->id]);
        $this->assertSame(200, $response->getStatusCode(), 'Merge must succeed: '.$response->getContent());

        $survivorTags = collect($tags->forSubject(Customer::findOrFail($survivor->id)))->pluck('name')->sort()->values()->all();
        $this->assertSame(['VIP', 'Wholesale'], $survivorTags, 'The merged customer\'s tags must move to the survivor without duplicating shared tags.');
        $this->assertSame(0, DB::table('taggables')->where('taggable_id', $merged->id)->where('taggable_type', (new Customer)->getMorphClass())->count());

        $this->assertSame(0, DB::table('communication_messages')->where('model', Customer::class)->where('record_id', $merged->id)->count(), 'Chatter messages must leave the merged customer.');
        $this->assertSame(1, DB::table('chatter_messages')->where('subject_type', Customer::class)->where('subject_id', (string) $survivor->id)->count());
        $this->assertSame(1, DB::table('chatter_activities')->where('subject_type', Customer::class)->where('subject_id', (string) $survivor->id)->count());
        $this->assertSame(0, DB::table('document_followers')->where('document_type', Customer::class)->where('document_id', $merged->id)->count());
        $this->assertSame(1, DB::table('document_followers')->where('document_type', Customer::class)->where('document_id', $survivor->id)->where('user_id', $this->admin->id)->count());

        // The survivor's chatter thread (real route) shows the moved note.
        $thread = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/chatter/{$type}/{$survivor->id}");
        $thread->assertOk();
        $this->assertStringContainsString('Note on the duplicate record.', $thread->getContent());
        $this->assertStringContainsString('Legacy chatter note', $thread->getContent());

        $moved = $response->json('moved');
        $this->assertContains('taggables', $moved);
        $this->assertContains('communication_messages', $moved);
    }

    // ─── 3. Duplicate guard carries a merge action ──────────────────

    public function test_duplicate_guard_on_update_offers_merge_into_existing(): void
    {
        $existing = Customer::factory()->create(['name' => 'Existing Co', 'tax_number' => 'TIN-441']);
        $editing = Customer::factory()->create(['name' => 'Editing Co', 'email' => 'editing@example.test']);

        $blocked = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/customers/{$editing->id}", [
                'name' => 'Editing Co',
                'type' => 'company',
                'tax_number' => 'TIN-441',
            ]);

        $this->assertSame(409, $blocked->getStatusCode(), $blocked->getContent());
        $this->assertSame('merge_into_existing', $blocked->json('merge_action.action'));
        $this->assertSame($editing->id, $blocked->json('merge_action.merged_customer_id'), 'On update the record being edited is the one merged away.');

        $match = collect($blocked->json('matches'))->firstWhere('customer_id', $existing->id);
        $this->assertNotNull($match);
        $this->assertSame("/api/v1/customers/{$existing->id}/merge", $match['merge_endpoint']);

        // Following the hint through the merge endpoint completes the merge.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($match['merge_endpoint'], ['merged_customer_id' => $blocked->json('merge_action.merged_customer_id')])
            ->assertOk();
        $this->assertFalse((bool) Customer::findOrFail($editing->id)->is_active);
    }

    public function test_duplicate_guard_on_create_offers_merge_after_create(): void
    {
        $existing = Customer::factory()->create(['name' => 'Create Existing', 'tax_number' => 'TIN-442']);

        $blocked = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/customers', [
                'name' => 'Create New',
                'type' => 'company',
                'email' => 'create-new@example.test',
                'tax_number' => 'TIN-442',
            ]);

        $this->assertSame(409, $blocked->getStatusCode(), $blocked->getContent());
        $this->assertSame('merge_into_existing', $blocked->json('merge_action.action'));
        $this->assertNull($blocked->json('merge_action.merged_customer_id'), 'On create there is no record yet to merge away.');
        $this->assertSame(
            "/api/v1/customers/{$existing->id}/merge",
            collect($blocked->json('matches'))->firstWhere('customer_id', $existing->id)['merge_endpoint']
        );
    }
}
