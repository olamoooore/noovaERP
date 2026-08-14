<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use Tests\TenantTestCase;

class ChartOfAccountComprehensiveTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
    }

    private function createAccount(array $overrides = []): ChartOfAccount
    {
        return ChartOfAccount::create(array_merge([
            'code' => uniqid('ACC-'),
            'name' => 'Test Account',
            'type' => 'asset',
            'account_type' => 'asset',
            'is_active' => true,
        ], $overrides));
    }

    // ─── Hierarchy Tests ──────────────────────────────────────────

    public function test_account_hierarchy_levels(): void
    {
        $level1 = $this->createAccount(['code' => 'L1-' . uniqid(), 'name' => 'Assets']);
        $level2 = $this->createAccount(['code' => 'L2-' . uniqid(), 'name' => 'Current Assets', 'parent_id' => $level1->id]);
        $level3 = $this->createAccount(['code' => 'L3-' . uniqid(), 'name' => 'Cash', 'parent_id' => $level2->id]);

        // Levels are derived from code length/convention
        $this->assertNotNull($level1->level);
        $this->assertNotNull($level2->level);
        $this->assertNotNull($level3->level);
    }

    public function test_parent_child_relationship(): void
    {
        $parent = $this->createAccount(['code' => 'PC-' . uniqid()]);
        $child1 = $this->createAccount(['code' => 'PC1-' . uniqid(), 'parent_id' => $parent->id]);
        $child2 = $this->createAccount(['code' => 'PC2-' . uniqid(), 'parent_id' => $parent->id]);

        $this->assertEquals($parent->id, $child1->parent_id);
        $this->assertEquals($parent->id, $child2->parent_id);
        $this->assertCount(2, $parent->children);
    }

    public function test_can_be_parent_attribute(): void
    {
        $parent = $this->createAccount(['code' => 'CBP-' . uniqid()]);
        $child = $this->createAccount(['code' => 'CBPC-' . uniqid(), 'parent_id' => $parent->id]);

        $this->assertTrue($parent->can_be_parent);
        $this->assertFalse($child->can_be_parent);
    }

    // ─── Balance Calculation Tests ────────────────────────────────

    public function test_balance_asset_account(): void
    {
        $account = $this->createAccount(['account_type' => 'asset']);

        // Balance is calculated from posted journal entry lines
        // For asset accounts: balance = sum(debit) - sum(credit)
        $balance = $account->getBalance();

        $this->assertIsFloat($balance);
    }

    public function test_balance_liability_account(): void
    {
        $account = $this->createAccount([
            'account_type' => 'liability',
            'account_sub_type' => 'current_liability',
        ]);

        $balance = $account->getBalance();

        $this->assertIsFloat($balance);
    }

    // ─── Validation Tests ─────────────────────────────────────────

    public function test_unique_account_code_enforced(): void
    {
        $uniqueCode = 'UNIQ-' . uniqid();
        $this->createAccount(['code' => $uniqueCode]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->createAccount(['code' => $uniqueCode]);
    }

    public function test_account_type_required(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        ChartOfAccount::create([
            'code' => 'NO-TYPE',
            'name' => 'No Type',
        ]);
    }

    // ─── Soft Delete Tests ────────────────────────────────────────

    public function test_soft_deleted_account_not_visible(): void
    {
        $account = $this->createAccount(['code' => 'SOFT-' . uniqid()]);
        $accountId = $account->id;

        $account->delete();

        $this->assertNull(ChartOfAccount::find($accountId));
        $this->assertNotNull(ChartOfAccount::withTrashed()->find($accountId));
    }

    // ─── Scope Tests ──────────────────────────────────────────────

    public function test_scope_by_type(): void
    {
        $asset = $this->createAccount(['code' => 'ASSET-' . uniqid(), 'type' => 'asset', 'account_type' => 'asset']);
        $liability = $this->createAccount(['code' => 'LIAB-' . uniqid(), 'type' => 'liability', 'account_type' => 'liability']);

        $assets = ChartOfAccount::where('type', 'asset')->get();

        $this->assertTrue($assets->contains('id', $asset->id));
        $this->assertFalse($assets->contains('id', $liability->id));
    }
}
