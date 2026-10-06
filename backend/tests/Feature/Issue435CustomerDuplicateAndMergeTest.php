<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TenantTestCase;

/**
 * Issue #435 (CU7): tiered duplicate detection + contact merge history.
 *
 * Real HTTP coverage of the acceptance criteria:
 *  1. Branches under the same parent may share email/phone (previously a hard
 *     422 from ensureUniqueCustomerIdentity()).
 *  2. Cross-parent shared email is a MEDIUM warning (409 without a
 *     duplicate_acknowledgement_reason, allowed with one).
 *  3. Same tax_number / company_registration_number is STRONG (409 requiring
 *     the allow_duplicate override).
 *  4. Same name + same city is MEDIUM.
 *  5. The customer merge endpoint re-points documents, records
 *     ContactMergeHistory (source -> target, who, when) and archives the
 *     merged customer.
 *  6. Merging a customer into itself or into an already-merged customer is
 *     rejected.
 */
class Issue435CustomerDuplicateAndMergeTest extends TenantTestCase
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
            'name' => 'Issue 435 Tenant',
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

    private function createCustomer(array $payload): TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/customers', $payload);
    }

    private function makeParent(string $name, string $email): int
    {
        return (int) $this->createCustomer([
            'name' => $name,
            'type' => 'company',
            'email' => $email,
        ])->assertCreated()->json('id');
    }

    // ─── 1. Same parent group: shared email/phone is allowed ────────

    public function test_branches_under_the_same_parent_may_share_email_and_phone(): void
    {
        $parentId = $this->makeParent('Holdings Pte Ltd', 'holdings@example.test');

        $this->createCustomer([
            'name' => 'Holdings Branch A',
            'type' => 'company',
            'parent_id' => $parentId,
            'email' => 'accounts@holdings.test',
            'phone' => '65001111',
        ])->assertCreated();

        $branchB = $this->createCustomer([
            'name' => 'Holdings Branch B',
            'type' => 'company',
            'parent_id' => $parentId,
            'email' => 'accounts@holdings.test',
            'phone' => '65001111',
        ]);

        $this->assertSame(
            201,
            $branchB->getStatusCode(),
            'Branches under the same parent must share email/phone without a duplicate block: '.$branchB->getContent()
        );
    }

    // ─── 2. Cross-parent shared email is a MEDIUM warning ───────────

    public function test_cross_parent_shared_email_is_medium_blocked_without_reason_and_allowed_with_reason(): void
    {
        $parentA = $this->makeParent('Group A Holdings', 'group-a@example.test');
        $parentB = $this->makeParent('Group B Holdings', 'group-b@example.test');

        $branchA = $this->createCustomer([
            'name' => 'Group A Branch',
            'type' => 'company',
            'parent_id' => $parentA,
            'email' => 'shared@group-a.test',
        ])->assertCreated();

        $this->createCustomer([
            'name' => 'Group B Branch One',
            'type' => 'company',
            'parent_id' => $parentB,
            'email' => 'group-b@branch.test',
        ])->assertCreated();

        $blocked = $this->createCustomer([
            'name' => 'Group B Branch Two',
            'type' => 'company',
            'parent_id' => $parentB,
            'email' => 'shared@group-a.test',
        ]);

        $this->assertSame(409, $blocked->getStatusCode(), 'Cross-parent shared email must be flagged: '.$blocked->getContent());
        $this->assertSame('medium', $blocked->json('tier'));
        $this->assertSame('duplicate_acknowledgement_reason', $blocked->json('requires'));
        $this->assertNotEmpty($blocked->json('matches'), 'The warning payload must list the matching customer(s).');

        $matchedIds = collect($blocked->json('matches'))->pluck('customer_id')->all();
        $this->assertContains($branchA->json('id'), $matchedIds, 'The cross-parent branch must be listed as the match.');

        $allowed = $this->createCustomer([
            'name' => 'Group B Branch Two',
            'type' => 'company',
            'parent_id' => $parentB,
            'email' => 'shared@group-a.test',
            'duplicate_acknowledgement_reason' => 'Shared group inbox, reviewed by finance',
        ]);

        $this->assertSame(201, $allowed->getStatusCode(), 'A medium warning with a reason must create: '.$allowed->getContent());

        // ContactDuplicateCandidate is wired: the acknowledged duplicate is
        // recorded so the review queue can see it.
        $candidate = DB::table('contact_duplicate_candidates')
            ->where('tenant_id', $this->tenantUuid)
            ->where('match_type', 'exact_email')
            ->first();
        $this->assertNotNull($candidate, 'An acknowledged duplicate must be recorded as a ContactDuplicateCandidate.');
        $this->assertSame('dismissed', $candidate->status);
    }

    // ─── 3. Same legal identifier is STRONG ─────────────────────────

    public function test_same_tax_number_is_strong_and_requires_an_explicit_override(): void
    {
        $first = $this->createCustomer([
            'name' => 'Taxpayer One',
            'type' => 'company',
            'email' => 'taxpayer-one@example.test',
            'tax_number' => 'TIN-9001',
        ])->assertCreated();

        $blocked = $this->createCustomer([
            'name' => 'Taxpayer Two',
            'type' => 'company',
            'email' => 'taxpayer-two@example.test',
            'tax_number' => 'TIN-9001',
        ]);

        $this->assertSame(409, $blocked->getStatusCode(), 'Same tax_number must be a strong duplicate: '.$blocked->getContent());
        $this->assertSame('strong', $blocked->json('tier'));
        $this->assertSame('duplicate_override', $blocked->json('requires'));
        $this->assertContains(
            $first->json('id'),
            collect($blocked->json('matches'))->pluck('customer_id')->all()
        );

        $override = $this->createCustomer([
            'name' => 'Taxpayer Two',
            'type' => 'company',
            'email' => 'taxpayer-two@example.test',
            'tax_number' => 'TIN-9001',
            'allow_duplicate' => true,
        ]);

        $this->assertSame(201, $override->getStatusCode(), 'The explicit override must create: '.$override->getContent());
    }

    public function test_same_company_registration_number_is_strong(): void
    {
        $this->createCustomer([
            'name' => 'Registered One',
            'type' => 'company',
            'email' => 'registered-one@example.test',
            'company_registration_number' => '202612345A',
        ])->assertCreated();

        $blocked = $this->createCustomer([
            'name' => 'Registered Two',
            'type' => 'company',
            'email' => 'registered-two@example.test',
            'company_registration_number' => '202612345A',
        ]);

        $this->assertSame(409, $blocked->getStatusCode(), 'Same company_registration_number must be strong: '.$blocked->getContent());
        $this->assertSame('strong', $blocked->json('tier'));
    }

    // ─── 4. Same name + city is MEDIUM ──────────────────────────────

    public function test_same_name_and_city_is_medium(): void
    {
        $this->createCustomer([
            'name' => 'Acme Trading Pte Ltd',
            'type' => 'company',
            'email' => 'acme-sg-one@example.test',
            'addresses' => [[
                'type' => 'billing',
                'street' => '1 Shenton Way',
                'city' => 'Singapore',
                'is_default' => true,
            ]],
        ])->assertCreated();

        $blocked = $this->createCustomer([
            'name' => 'Acme Trading Pte Ltd',
            'type' => 'company',
            'email' => 'acme-sg-two@example.test',
            'addresses' => [[
                'type' => 'billing',
                'street' => '2 Shenton Way',
                'city' => 'Singapore',
                'is_default' => true,
            ]],
        ]);

        $this->assertSame(409, $blocked->getStatusCode(), 'Same name + city must be a medium warning: '.$blocked->getContent());
        $this->assertSame('medium', $blocked->json('tier'));
        $this->assertSame('duplicate_acknowledgement_reason', $blocked->json('requires'));

        $allowed = $this->createCustomer([
            'name' => 'Acme Trading Pte Ltd',
            'type' => 'company',
            'email' => 'acme-sg-two@example.test',
            'addresses' => [[
                'type' => 'billing',
                'street' => '2 Shenton Way',
                'city' => 'Singapore',
                'is_default' => true,
            ]],
            'duplicate_acknowledgement_reason' => 'Second retail outlet of the same company',
        ]);

        $this->assertSame(201, $allowed->getStatusCode(), 'A medium warning with a reason must create: '.$allowed->getContent());
    }

    // ─── 5. Merge moves documents + records ContactMergeHistory ─────

    public function test_merge_moves_documents_records_history_and_archives_source(): void
    {
        $primary = Customer::factory()->create(['name' => 'Survivor Co', 'email' => 'survivor@example.test']);
        $merged = Customer::factory()->create(['name' => 'Merged Co', 'email' => 'merged@example.test']);

        $invoice = Invoice::factory()->create(['customer_id' => $merged->id]);
        $contactId = DB::table('customer_contacts')->insertGetId([
            'customer_id' => $merged->id,
            'name' => 'Merged Contact',
            'email' => 'contact@merged.test',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $addressId = DB::table('customer_addresses')->insertGetId([
            'customer_id' => $merged->id,
            'type' => 'billing',
            'city' => 'Singapore',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$primary->id}/merge", [
                'merged_customer_id' => $merged->id,
                'reason' => 'Duplicate created by the branch importer',
            ]);

        $this->assertSame(200, $response->getStatusCode(), 'Merge must succeed: '.$response->getContent());

        $this->assertSame(
            $primary->id,
            (int) DB::table('invoices')->where('id', $invoice->id)->value('customer_id'),
            'Invoices must be re-pointed to the surviving customer.'
        );
        $this->assertSame(
            $primary->id,
            (int) DB::table('customer_contacts')->where('id', $contactId)->value('customer_id'),
            'Customer contacts must be re-pointed to the surviving customer.'
        );
        $this->assertSame(
            $primary->id,
            (int) DB::table('customer_addresses')->where('id', $addressId)->value('customer_id'),
            'Customer addresses must be re-pointed to the surviving customer.'
        );

        $this->assertFalse(
            (bool) Customer::findOrFail($merged->id)->is_active,
            'The merged customer must be archived (inactive), not deleted.'
        );

        $history = DB::table('contact_merge_history')
            ->where('primary_contact_id', $primary->fresh()->core_contact_uuid)
            ->where('merged_contact_id', $merged->fresh()->core_contact_uuid)
            ->first();

        $this->assertNotNull($history, 'ContactMergeHistory must record source -> target for the merged customers.');
        $this->assertSame($this->admin->id, (int) $history->merged_by, 'ContactMergeHistory must record who merged.');
        $this->assertNotNull($history->merged_at, 'ContactMergeHistory must record when the merge happened.');
        $this->assertSame('Duplicate created by the branch importer', $history->reason);
        $this->assertStringContainsString('invoices', (string) $history->merge_details, 'The history must record the re-pointed document groups.');
    }

    // ─── 6. Same / already-merged customers are rejected ────────────

    public function test_merge_of_a_customer_into_itself_is_rejected(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$customer->id}/merge", [
                'merged_customer_id' => $customer->id,
            ]);

        $this->assertSame(422, $response->getStatusCode(), 'Merging a customer into itself must be rejected: '.$response->getContent());
    }

    public function test_merge_of_an_already_merged_customer_is_rejected(): void
    {
        $survivor = Customer::factory()->create(['name' => 'Survivor']);
        $duplicate = Customer::factory()->create(['name' => 'Duplicate']);
        $other = Customer::factory()->create(['name' => 'Other']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$survivor->id}/merge", ['merged_customer_id' => $duplicate->id])
            ->assertOk();

        $again = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$other->id}/merge", ['merged_customer_id' => $duplicate->id]);

        $this->assertSame(422, $again->getStatusCode(), 'An already-merged customer must not be merged again: '.$again->getContent());
    }

    // ─── ContactModuleContract owned-entity namespace guard ─────────

    /**
     * The CU7 evidence referenced a wrong class in ContactModuleContract's
     * owned entities (the pre-#115 phantom `ContactDuplicate`). Pin every
     * declared entity to a real class so a bad namespace cannot recur.
     */
    public function test_contact_module_contract_owned_entities_all_resolve(): void
    {
        $contract = new \Modules\Contact\Contracts\ContactModuleContract;

        foreach ($contract->getOwnedEntities() as $class) {
            $this->assertTrue(
                class_exists($class),
                "ContactModuleContract::getOwnedEntities() declares '{$class}', which does not autoload."
            );
        }
    }
}
