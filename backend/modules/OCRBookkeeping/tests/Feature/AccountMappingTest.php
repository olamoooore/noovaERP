<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\User;
use Modules\OCRBookkeeping\Models\OcrAccountMapping;
use Modules\OCRBookkeeping\Services\AccountMappingService;
use Tests\TenantTestCase;

class AccountMappingTest extends TenantTestCase
{
    protected User $user;

    protected AccountMappingService $service;

    protected Customer $vendor;

    protected ChartOfAccount $expenseAccount;

    protected ChartOfAccount $cogsAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->vendor = Customer::factory()->create(['name' => 'Test Vendor']);

        $this->expenseAccount = ChartOfAccount::where('code', '5200')->firstOrFail();

        $this->cogsAccount = ChartOfAccount::where('code', '5100')->firstOrFail();

        $this->service = app(AccountMappingService::class);
    }

    /**
     * Property 14: Account suggestions use frequency — higher-usage mapping wins
     */
    public function test_account_suggestions_use_frequency()
    {
        // Create mappings with different frequencies
        OcrAccountMapping::create([
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies'],
            'description_pattern' => 'Office supplies purchase',
            'usage_count' => 10,
        ]);

        OcrAccountMapping::create([
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->cogsAccount->id,
            'keywords' => ['inventory', 'stock'],
            'usage_count' => 3,
        ]);

        // suggestAccount returns ?int — the best match account ID
        $suggestion = $this->service->suggestAccount(
            $this->vendor->id,
            'Office supplies purchase'
        );

        $this->assertNotNull($suggestion);
        // Most frequently used with matching description should win
        $this->assertEquals($this->expenseAccount->id, $suggestion);
    }

    /**
     * Property 15: Historical data pre-populates accounts
     */
    public function test_historical_data_prepopulates_accounts()
    {
        // Create historical mapping — keywords must overlap the description
        // so that calculateMappingScore() exceeds the 0.6 confidence threshold.
        OcrAccountMapping::create([
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies'],
            'description_pattern' => 'Office supplies',
            'usage_count' => 5,
        ]);

        $suggestion = $this->service->suggestAccount(
            $this->vendor->id,
            'Office supplies'
        );

        $this->assertNotNull($suggestion);
        $this->assertEquals($this->expenseAccount->id, $suggestion);
    }

    /**
     * Property 16: Keyword matching for new vendors
     */
    public function test_keyword_matching_for_new_vendors()
    {
        $newVendor = Customer::factory()->create(['name' => 'New Vendor']);

        // Create keyword-based mappings (no vendor_id)
        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies', 'stationery'],
            'usage_count' => 20,
        ]);

        $suggestion = $this->service->suggestAccount(
            $newVendor->id,
            'Office supplies and stationery'
        );

        $this->assertNotNull($suggestion);
        $this->assertEquals($this->expenseAccount->id, $suggestion);
    }

    /**
     * Property 17: Account overrides are learned
     *
     * storeMapping signature: storeMapping(?int $vendorId, string $description, int $accountId)
     */
    public function test_account_overrides_are_learned()
    {
        $description = 'Office supplies';

        // Store mapping — correct arg order: vendorId, description, accountId
        $this->service->storeMapping(
            $this->vendor->id,
            $description,
            $this->expenseAccount->id
        );

        $this->assertDatabaseHas('ocr_account_mappings', [
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->expenseAccount->id,
        ]);

        // Store again with same account — usage_count should increment
        $this->service->storeMapping(
            $this->vendor->id,
            $description,
            $this->expenseAccount->id
        );

        $mapping = OcrAccountMapping::where('vendor_id', $this->vendor->id)
            ->where('account_id', $this->expenseAccount->id)
            ->first();

        $this->assertEquals(2, $mapping->usage_count);
    }

    /**
     * Property 18: Per-line-item account suggestions
     */
    public function test_per_line_item_account_suggestions()
    {
        // Create mappings for different keywords
        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies'],
            'usage_count' => 10,
        ]);

        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->cogsAccount->id,
            'keywords' => ['inventory', 'product'],
            'usage_count' => 10,
        ]);

        $lineItems = [
            ['description' => 'Office supplies'],
            ['description' => 'Inventory products'],
        ];

        $suggestions = [];
        foreach ($lineItems as $item) {
            $suggestions[] = $this->service->suggestAccount(
                $this->vendor->id,
                $item['description']
            );
        }

        // Different line items should get different suggestions
        $this->assertEquals($this->expenseAccount->id, $suggestions[0]);
        $this->assertEquals($this->cogsAccount->id, $suggestions[1]);
    }

    /**
     * Test: Keywords are extracted from description
     *
     * extractKeywords is a static method on OcrAccountMapping model, not on the service.
     */
    public function test_keywords_are_extracted_from_description()
    {
        $description = 'Office supplies and stationery items';

        $keywords = OcrAccountMapping::extractKeywords($description);

        $this->assertIsArray($keywords);
        $this->assertContains('office', $keywords);
        $this->assertContains('supplies', $keywords);
        $this->assertContains('stationery', $keywords);

        // Should not contain common stop words
        $this->assertNotContains('and', $keywords);
    }

    /**
     * Test: Empty description returns null
     */
    public function test_empty_description_returns_null()
    {
        $suggestion = $this->service->suggestAccount($this->vendor->id, '');

        // suggestAccount returns null for empty description
        $this->assertNull($suggestion);
    }

    /**
     * Test: Multiple keyword matches are ranked — best match wins
     */
    public function test_multiple_keyword_matches_are_ranked()
    {
        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies', 'stationery'],
            'usage_count' => 10,
        ]);

        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->cogsAccount->id,
            'keywords' => ['office'],
            'usage_count' => 5,
        ]);

        // Description matches more keywords from first mapping
        $suggestion = $this->service->suggestAccount(
            $this->vendor->id,
            'Office supplies and stationery'
        );

        $this->assertNotNull($suggestion);

        // First mapping has more keyword matches and higher usage — should win
        $this->assertEquals($this->expenseAccount->id, $suggestion);
    }

    /**
     * Test: Vendor-specific mappings override generic ones
     */
    public function test_vendor_specific_mappings_override_generic()
    {
        // Generic mapping (higher usage)
        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->cogsAccount->id,
            'keywords' => ['supplies'],
            'usage_count' => 20,
        ]);

        // Vendor-specific mapping (lower usage but vendor-specific takes priority)
        OcrAccountMapping::create([
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['supplies'],
            'description_pattern' => 'Office supplies',
            'usage_count' => 5,
        ]);

        $suggestion = $this->service->suggestAccount(
            $this->vendor->id,
            'Office supplies'
        );

        // Vendor-specific should be preferred
        $this->assertEquals($this->expenseAccount->id, $suggestion);
    }

    /**
     * Test: Case insensitive keyword matching
     */
    public function test_case_insensitive_keyword_matching()
    {
        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies'],
            'usage_count' => 10,
        ]);

        $testCases = [
            'OFFICE SUPPLIES',
            'Office Supplies',
            'office supplies',
            'OfFiCe SuPpLiEs',
        ];

        foreach ($testCases as $description) {
            $suggestion = $this->service->suggestAccount($this->vendor->id, $description);
            $this->assertNotNull($suggestion, "Failed for: $description");
            $this->assertEquals($this->expenseAccount->id, $suggestion);
        }
    }

    /**
     * Test: suggestAccount returns a single best-match account ID (not array)
     */
    public function test_suggest_account_returns_nullable_int()
    {
        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies'],
            'usage_count' => 10,
        ]);

        // Description keywords fully overlap mapping keywords → score above threshold
        $suggestion = $this->service->suggestAccount($this->vendor->id, 'office supplies');

        // suggestAccount returns ?int
        $this->assertIsInt($suggestion);
        $this->assertEquals($this->expenseAccount->id, $suggestion);
    }
}
