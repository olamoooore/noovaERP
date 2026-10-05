<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #885 (SALES-ACCT UX3) — Settings > Default Accounts was made to write
 * the account mapping posting resolves (#877), but it stayed tenant-wide:
 * a multi-entity tenant had one set of defaults for every legal entity, even
 * though the posting path can resolve entity-scoped assignments through
 * entity_account_roles.
 *
 * These tests drive the real HTTP route (router → middleware → controller):
 * the screen writes the AR account for ONE entity, and an invoice posted for
 * that entity must debit that account while a sibling entity keeps the
 * tenant-wide default.
 */
class DefaultAccountsEntitySettingsTest extends TenantTestCase
{
    private User $admin;

    private int $entityA;

    private int $entityB;

    private int $defaultArId;

    private int $entityArId;

    private int $revenueId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Default Accounts Entity 885 Tenant');

        $suffix = Str::upper(Str::random(6));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue885 Group',
            'code' => 'I885-'.Str::upper(Str::random(5)),
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = (int) DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => '885-A-'.Str::upper(Str::random(5)),
            'country_code' => 'SG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityB = (int) DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => '885-B-'.Str::upper(Str::random(5)),
            'country_code' => 'SG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$this->entityA, $this->entityB] as $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $this->admin->id,
                'legal_entity_id' => $entityId,
                'role' => 'admin',
                'is_default' => $entityId === $this->entityA,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->defaultArId = (int) ChartOfAccount::factory()->create([
            'code' => '118000-'.$suffix,
            'name' => 'Tenant Default AR',
            'type' => 'asset',
            'is_active' => true,
        ])->id;

        $this->entityArId = (int) ChartOfAccount::factory()->create([
            'code' => '118100-'.$suffix,
            'name' => 'Entity A AR',
            'type' => 'asset',
            'is_active' => true,
        ])->id;

        $this->revenueId = (int) ChartOfAccount::factory()->create([
            'code' => '410000-'.$suffix,
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'is_active' => true,
        ])->id;

        AccountMapping::whereIn('mapping_key', ['accounts_receivable', 'sales_revenue'])->delete();

        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $this->defaultArId, 'is_active' => true]);
        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $this->revenueId, 'is_active' => true]);
    }

    private function tenantDefaultArId(): int
    {
        return (int) AccountMapping::query()
            ->where('mapping_key', 'accounts_receivable')
            ->whereNull('document_type')
            ->value('account_id');
    }

    private function activeEntityArId(int $entityId, int $accountId): ?object
    {
        $roleId = DB::table('account_roles')->where('code', 'AR_CONTROL')->value('id');
        $this->assertNotNull($roleId, 'AR_CONTROL must exist in account_roles');

        return DB::table('entity_account_roles')
            ->where('legal_entity_id', $entityId)
            ->where('account_role_id', $roleId)
            ->where('chart_of_account_id', $accountId)
            ->where('is_active', true)
            ->first();
    }

    private function postInvoiceForEntity(int $entityId): Invoice
    {
        $customer = Customer::factory()->create([
            'name' => 'Issue885 Customer '.$entityId,
            'legal_entity_id' => $entityId,
        ]);

        $product = Product::factory()->create([
            'name' => 'Issue885 Widget '.$entityId,
            'price' => 100,
            'cost' => 10,
            'track_inventory' => false,
        ]);

        $create = $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson('/api/v1/invoices', [
                'customer_id' => $customer->id,
                'invoice_date' => now()->toDateString(),
                'lines' => [[
                    'product_id' => $product->id,
                    'description' => 'Issue885 revenue line',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ]],
            ]);
        $create->assertCreated($create->getContent());

        $invoiceId = (int) $create->json('id');

        $post = $this->actingAs($this->admin)
            ->withHeaders(['X-Entity-Id' => (string) $entityId])
            ->postJson('/api/v1/invoices/'.$invoiceId.'/post');
        $post->assertOk($post->getContent());

        return Invoice::with(['journalEntry.lines'])->findOrFail($invoiceId);
    }

    public function test_screen_writes_the_ar_account_posting_uses_for_the_selected_entity(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/settings/accounts', [
                'entity_id' => $this->entityA,
                'accounts_receivable' => $this->entityArId,
            ])
            ->assertOk()
            ->assertJsonPath('data.accounts_receivable', $this->entityArId)
            ->assertJsonPath('scope.accounts_receivable', 'entity');

        $this->assertNotNull(
            $this->activeEntityArId($this->entityA, $this->entityArId),
            'the entity-scoped AR account must be stored in entity_account_roles for the selected entity'
        );

        $this->assertSame(
            $this->defaultArId,
            $this->tenantDefaultArId(),
            'the tenant-wide default mapping must not change when a single entity is edited'
        );

        $this->assertNull(
            $this->activeEntityArId($this->entityB, $this->entityArId),
            'entity B must not inherit entity A\'s override'
        );

        // Reading the screen for each entity must reflect what posting resolves.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/settings/accounts?entity_id='.$this->entityA)
            ->assertOk()
            ->assertJsonPath('data.accounts_receivable', $this->entityArId)
            ->assertJsonPath('scope.accounts_receivable', 'entity');

        $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/settings/accounts?entity_id='.$this->entityB)
            ->assertOk()
            ->assertJsonPath('data.accounts_receivable', $this->defaultArId)
            ->assertJsonPath('scope.accounts_receivable', 'default');
    }

    public function test_only_the_selected_entity_posts_to_its_own_default_account(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/settings/accounts', [
                'entity_id' => $this->entityA,
                'accounts_receivable' => $this->entityArId,
            ])
            ->assertOk();

        $invoiceA = $this->postInvoiceForEntity($this->entityA);

        $entityLine = $invoiceA->journalEntry->lines->firstWhere('account_id', $this->entityArId);
        $this->assertNotNull(
            $entityLine,
            'entity A\'s invoice must debit the AR account selected for entity A on the screen'
        );
        $this->assertGreaterThan(0, (float) $entityLine->debit);

        $invoiceB = $this->postInvoiceForEntity($this->entityB);

        $defaultLine = $invoiceB->journalEntry->lines->firstWhere('account_id', $this->defaultArId);
        $this->assertNotNull(
            $defaultLine,
            'entity B must keep posting to the tenant-wide default AR account'
        );
        $this->assertGreaterThan(0, (float) $defaultLine->debit);
        $this->assertNull(
            $invoiceB->journalEntry->lines->firstWhere('account_id', $this->entityArId),
            'entity B must not use entity A\'s AR override'
        );
    }

    public function test_clearing_an_entity_override_falls_back_to_the_tenant_default(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/settings/accounts', [
                'entity_id' => $this->entityA,
                'accounts_receivable' => $this->entityArId,
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/settings/accounts', [
                'entity_id' => $this->entityA,
                'accounts_receivable' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.accounts_receivable', $this->defaultArId)
            ->assertJsonPath('scope.accounts_receivable', 'default');

        $this->assertNull(
            $this->activeEntityArId($this->entityA, $this->entityArId),
            'clearing the entity override must deactivate the entity_account_roles row'
        );
    }
}
