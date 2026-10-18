<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Modules\OCRBookkeeping\Models\OcrVendorMapping;
use Modules\OCRBookkeeping\Services\VendorMappingService;
use App\Models\Vendor;
use App\Models\User;

class VendorMappingTest extends TenantTestCase
{
    protected User $user;
    protected VendorMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->service = app(VendorMappingService::class);
    }

    /**
     * Property 10: Vendor search is always performed
     */
    public function test_vendor_search_is_always_performed()
    {
        $vendor = Vendor::factory()->create([
            'name' => 'ABC Supplies Ltd',
        ]);

        $extractedName = 'ABC Supplies';

        $matches = $this->service->findVendorMatch($extractedName);

        $this->assertNotEmpty($matches);
        $this->assertIsArray($matches);
    }

    /**
     * Property 11: High confidence vendor matches are automatic
     */
    public function test_high_confidence_vendor_matches_are_automatic()
    {
        $vendor = Vendor::factory()->create([
            'name' => 'ABC Supplies Ltd',
        ]);

        // Create existing mapping for high confidence
        OcrVendorMapping::create([
            'extracted_name' => 'ABC Supplies Ltd',
            'normalized_name' => 'abc supplies',
            'vendor_id' => $vendor->id,
            'confidence_score' => 1.0,
            'usage_count' => 10,
        ]);

        $matches = $this->service->findVendorMatch('ABC Supplies Ltd');

        $this->assertNotEmpty($matches);
        $this->assertEquals($vendor->id, $matches[0]['vendor_id']);
        $this->assertGreaterThanOrEqual(0.9, $matches[0]['confidence']);
    }

    /**
     * Property 12: Multiple matches return top 3
     */
    public function test_multiple_matches_return_top_3()
    {
        // Create similar vendors for fuzzy search
        Vendor::factory()->create(['name' => 'ABC Supplies Ltd']);
        Vendor::factory()->create(['name' => 'ABC Supply Company']);
        Vendor::factory()->create(['name' => 'ABC Supplies Inc']);
        Vendor::factory()->create(['name' => 'ABC Trading Ltd']);
        Vendor::factory()->create(['name' => 'XYZ Supplies Ltd']);

        $matches = $this->service->findVendorMatch('ABC Supplies');

        // Should return top 3 matches (MAX_SUGGESTIONS = 3)
        $this->assertLessThanOrEqual(3, count($matches));

        // Verify sorted by confidence descending
        if (count($matches) > 1) {
            $this->assertGreaterThanOrEqual(
                $matches[1]['confidence'],
                $matches[0]['confidence']
            );
        }
    }

    /**
     * Property 13: Vendor confirmations are stored
     */
    public function test_vendor_confirmations_are_stored()
    {
        $vendor = Vendor::factory()->create([
            'name' => 'ABC Supplies Ltd',
        ]);

        $extractedName = 'ABC Supplies';

        $this->service->storeMapping($extractedName, $vendor->id, 0.85);

        $this->assertDatabaseHas('ocr_vendor_mappings', [
            'extracted_name' => $extractedName,
            'vendor_id' => $vendor->id,
        ]);

        // Verify normalized name stored
        $mapping = OcrVendorMapping::where('extracted_name', $extractedName)->first();
        $this->assertEquals('abc supplies', $mapping->normalized_name);
    }

    /**
     * Test: Fuzzy matching works with typos
     */
    public function test_fuzzy_matching_works_with_typos()
    {
        $vendor = Vendor::factory()->create([
            'name' => 'ABC Supplies Ltd',
        ]);

        // Create mapping
        OcrVendorMapping::create([
            'extracted_name' => 'ABC Supplies Ltd',
            'normalized_name' => 'abc supplies',
            'vendor_id' => $vendor->id,
            'confidence_score' => 1.0,
            'usage_count' => 5,
        ]);

        // Test with typos — normalizeName will produce "abc suplies" which triggers fuzzy search
        $matches = $this->service->findVendorMatch('ABC Suplies Ltd');

        $this->assertNotEmpty($matches);
        $this->assertEquals($vendor->id, $matches[0]['vendor_id']);
    }

    /**
     * Test: Case insensitive matching
     */
    public function test_case_insensitive_matching()
    {
        $vendor = Vendor::factory()->create([
            'name' => 'ABC Supplies Ltd',
        ]);

        OcrVendorMapping::create([
            'extracted_name' => 'ABC Supplies Ltd',
            'normalized_name' => 'abc supplies',
            'vendor_id' => $vendor->id,
            'confidence_score' => 1.0,
            'usage_count' => 5,
        ]);

        // Test different cases — normalizeName lowercases everything
        $testCases = [
            'abc supplies ltd',
            'ABC SUPPLIES LTD',
            'Abc Supplies Ltd',
            'aBc SuPpLiEs LtD',
        ];

        foreach ($testCases as $testCase) {
            $matches = $this->service->findVendorMatch($testCase);
            $this->assertNotEmpty($matches, "Failed for: $testCase");
            $this->assertEquals($vendor->id, $matches[0]['vendor_id']);
        }
    }

    /**
     * Test: Mapping frequency increases with confirmations
     */
    public function test_mapping_frequency_increases_with_confirmations()
    {
        $vendor = Vendor::factory()->create([
            'name' => 'ABC Supplies Ltd',
        ]);

        $extractedName = 'ABC Supplies';

        // storeMapping creates a mapping on first call, then recordUsage() on subsequent calls
        $this->service->storeMapping($extractedName, $vendor->id, 0.85);
        $this->service->storeMapping($extractedName, $vendor->id, 0.85);
        $this->service->storeMapping($extractedName, $vendor->id, 0.85);

        $mapping = OcrVendorMapping::where('extracted_name', $extractedName)->first();

        // First call creates with usage_count=1, subsequent 2 calls increment to 3
        $this->assertEquals(3, $mapping->usage_count);
    }

    /**
     * Test: Empty vendor name returns empty matches
     */
    public function test_empty_vendor_name_returns_empty_matches()
    {
        $matches = $this->service->findVendorMatch('');
        $this->assertEmpty($matches);
    }

    /**
     * Test: Special characters are handled
     */
    public function test_special_characters_are_handled()
    {
        $vendor = Vendor::factory()->create([
            'name' => "O'Reilly's Supplies & Co.",
        ]);

        OcrVendorMapping::create([
            'extracted_name' => "O'Reilly's Supplies & Co.",
            'normalized_name' => OcrVendorMapping::normalizeName("O'Reilly's Supplies & Co."),
            'vendor_id' => $vendor->id,
            'confidence_score' => 1.0,
            'usage_count' => 1,
        ]);

        $matches = $this->service->findVendorMatch("OReillys Supplies and Co");

        $this->assertNotEmpty($matches);
    }

    /**
     * Test: Vendor with no mappings can still be found via fuzzy search
     */
    public function test_vendor_with_no_mappings_can_still_be_found()
    {
        Vendor::factory()->create([
            'name' => 'New Vendor Ltd',
        ]);

        // No mappings exist yet — should find via fuzzy search on vendors table
        $matches = $this->service->findVendorMatch('New Vendor');

        $this->assertNotEmpty($matches);
    }

    /**
     * Test: Normalization removes common suffixes
     *
     * normalizeName() is a static method on OcrVendorMapping (not on the service).
     */
    public function test_normalization_removes_common_suffixes()
    {
        $testCases = [
            'ABC Supplies Ltd' => 'abc supplies',
            'XYZ Corp Inc' => 'xyz corp',
            'Test Company LLC' => 'test company',
            'Sample Business Co.' => 'sample business',
        ];

        foreach ($testCases as $input => $expected) {
            $normalized = OcrVendorMapping::normalizeName($input);
            $this->assertEquals($expected, $normalized, "Failed for: $input");
        }
    }
}
