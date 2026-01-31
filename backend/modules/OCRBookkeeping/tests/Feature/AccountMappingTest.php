<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\OCRBookkeeping\Models\OcrAccountMapping;
use Modules\OCRBookkeeping\Services\AccountMappingService;
use App\Models\ChartOfAccount;
use App\Models\Vendor;
use App\Models\User;

class AccountMappingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected AccountMappingService $service;
    protected Vendor $vendor;
    protected ChartOfAccount $expenseAccount;
    protected ChartOfAccount $cogsAccount;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->vendor = Vendor::factory()->create(['name' => 'Test Vendor']);
        
        $this->expenseAccount = ChartOfAccount::factory()->create([
            'code' => '5200',
            'name' => 'Operating Expenses',
        ]);
        
        $this->cogsAccount = ChartOfAccount::factory()->create([
            'code' => '5000',
            'name' => 'Cost of Goods Sold',
        ]);
        
        $this->service = app(AccountMappingService::class);
    }

    /**
     * Property 14: Account suggestions use frequency
     */
    public function test_account_suggestions_use_frequency()
    {
        // Create mappings with different frequencies
        OcrAccountMapping::create([
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies'],
            'usage_count' => 10,
            'created_by' => $this->user->id,
        ]);

        OcrAccountMapping::create([
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->cogsAccount->id,
            'keywords' => ['inventory', 'stock'],
            'usage_count' => 3,
            'created_by' => $this->user->id,
        ]);

        $suggestions = $this->service->suggestAccount(
            $this->vendor->id,
            'Office supplies purchase'
        );

        $this->assertNotEmpty($suggestions);
        
        // Most frequently used should be first
        $this->assertEquals($this->expenseAccount->id, $suggestions[0]['account_id']);
        $this->assertGreaterThan($suggestions[1]['confidence'], $suggestions[0]['confidence']);
    }

    /**
     * Property 15: Historical data pre-populates accounts
     */
    public function test_historical_data_prepopulates_accounts()
    {
        // Create historical mapping
        OcrAccountMapping::create([
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office'],
            'usage_count' => 5,
            'created_by' => $this->user->id,
        ]);

        $suggestions = $this->service->suggestAccount(
            $this->vendor->id,
            'Any description'
        );

        $this->assertNotEmpty($suggestions);
        $this->assertEquals($this->expenseAccount->id, $suggestions[0]['account_id']);
        $this->assertEquals('vendor_history', $suggestions[0]['reason']);
    }

    /**
     * Property 16: Keyword matching for new vendors
     */
    public function test_keyword_matching_for_new_vendors()
    {
        $newVendor = Vendor::factory()->create(['name' => 'New Vendor']);

        // Create keyword-based mappings (no vendor_id)
        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies', 'stationery'],
            'usage_count' => 20,
            'created_by' => $this->user->id,
        ]);

        $suggestions = $this->service->suggestAccount(
            $newVendor->id,
            'Office supplies and stationery'
        );

        $this->assertNotEmpty($suggestions);
        $this->assertEquals($this->expenseAccount->id, $suggestions[0]['account_id']);
        $this->assertEquals('keyword_match', $suggestions[0]['reason']);
    }

    /**
     * Property 17: Account overrides are learned
     */
    public function test_account_overrides_are_learned()
    {
        $description = 'Office supplies';

        // Store mapping
        $this->service->storeMapping(
            $this->vendor->id,
            $this->expenseAccount->id,
            $description,
            $this->user->id
        );

        $this->assertDatabaseHas('ocr_account_mappings', [
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->expenseAccount->id,
        ]);

        // Store again with same account
        $this->service->storeMapping(
            $this->vendor->id,
            $this->expenseAccount->id,
            $description,
            $this->user->id
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
            'created_by' => $this->user->id,
        ]);

        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->cogsAccount->id,
            'keywords' => ['inventory', 'product'],
            'usage_count' => 10,
            'created_by' => $this->user->id,
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
        $this->assertEquals($this->expenseAccount->id, $suggestions[0][0]['account_id']);
        $this->assertEquals($this->cogsAccount->id, $suggestions[1][0]['account_id']);
    }

    /**
     * Test: Keywords are extracted from description
     */
    public function test_keywords_are_extracted_from_description()
    {
        $description = 'Office supplies and stationery items';

        $keywords = $this->service->extractKeywords($description);

        $this->assertIsArray($keywords);
        $this->assertContains('office', $keywords);
        $this->assertContains('supplies', $keywords);
        $this->assertContains('stationery', $keywords);
        
        // Should not contain common words
        $this->assertNotContains('and', $keywords);
        $this->assertNotContains('items', $keywords);
    }

    /**
     * Test: Empty description returns default suggestions
     */
    public function test_empty_description_returns_default_suggestions()
    {
        $suggestions = $this->service->suggestAccount($this->vendor->id, '');

        // Should still return suggestions based on vendor history
        $this->assertIsArray($suggestions);
    }

    /**
     * Test: Multiple keyword matches are ranked
     */
    public function test_multiple_keyword_matches_are_ranked()
    {
        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['office', 'supplies', 'stationery'],
            'usage_count' => 10,
            'created_by' => $this->user->id,
        ]);

        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->cogsAccount->id,
            'keywords' => ['office'],
            'usage_count' => 5,
            'created_by' => $this->user->id,
        ]);

        // Description matches more keywords from first mapping
        $suggestions = $this->service->suggestAccount(
            $this->vendor->id,
            'Office supplies and stationery'
        );

        $this->assertNotEmpty($suggestions);
        
        // First suggestion should have higher confidence (more keyword matches)
        $this->assertEquals($this->expenseAccount->id, $suggestions[0]['account_id']);
    }

    /**
     * Test: Vendor-specific mappings override generic ones
     */
    public function test_vendor_specific_mappings_override_generic()
    {
        // Generic mapping
        OcrAccountMapping::create([
            'vendor_id' => null,
            'account_id' => $this->cogsAccount->id,
            'keywords' => ['supplies'],
            'usage_count' => 20,
            'created_by' => $this->user->id,
        ]);

        // Vendor-specific mapping
        OcrAccountMapping::create([
            'vendor_id' => $this->vendor->id,
            'account_id' => $this->expenseAccount->id,
            'keywords' => ['supplies'],
            'usage_count' => 5,
            'created_by' => $this->user->id,
        ]);

        $suggestions = $this->service->suggestAccount(
            $this->vendor->id,
            'Office supplies'
        );

        // Vendor-specific should be preferred
        $this->assertEquals($this->expenseAccount->id, $suggestions[0]['account_id']);
        $this->assertEquals('vendor_history', $suggestions[0]['reason']);
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
            'created_by' => $this->user->id,
        ]);

        $testCases = [
            'OFFICE SUPPLIES',
            'Office Supplies',
            'office supplies',
            'OfFiCe SuPpLiEs',
        ];

        foreach ($testCases as $description) {
            $suggestions = $this->service->suggestAccount($this->vendor->id, $description);
            $this->assertNotEmpty($suggestions, "Failed for: $description");
            $this->assertEquals($this->expenseAccount->id, $suggestions[0]['account_id']);
        }
    }

    /**
     * Test: Suggestions are limited to top N
     */
    public function test_suggestions_are_limited_to_top_n()
    {
        // Create many mappings
        for ($i = 1; $i <= 10; $i++) {
            $account = ChartOfAccount::factory()->create([
                'code' => "520$i",
                'name' => "Expense Account $i",
            ]);

            OcrAccountMapping::create([
                'vendor_id' => null,
                'account_id' => $account->id,
                'keywords' => ['test'],
                'usage_count' => $i,
                'created_by' => $this->user->id,
            ]);
        }

        $suggestions = $this->service->suggestAccount($this->vendor->id, 'test');

        // Should return top 3-5 suggestions, not all 10
        $this->assertLessThanOrEqual(5, count($suggestions));
    }
}
