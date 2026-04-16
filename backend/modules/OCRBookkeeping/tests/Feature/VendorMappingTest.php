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
            'normalized_name' => 'abc supplies ltd',
            'vendor_id' => $vendor->id,
            'confidence_score' => 1.0,
            'match_count' => 10,
            'created_by' => $this->user->id,
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
        // Create similar vendors
        $vendors = [
            Vendor::factory()->create(['name' => 'ABC Supplies Ltd']),
            Vendor::factory()->create(['name' => 'ABC Supply Company']),
            Vendor::factory()->create(['name' => 'ABC Supplies Inc']),
            Vendor::factory()->create(['name' => 'ABC Trading Ltd']),
            Vendor::factory()->create(['name' => 'XYZ Supplies Ltd']),
        ];

        $matches = $this->service->findVendorMatch('ABC Supplies');

        // Should return top 3 matches
        $this->assertLessThanOrEqual(3, count($matches));
        
        // Verify sorted by confidence
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

        $this->service->storeMapping($extractedName, $vendor->id, 0.85, $this->user->id);

        $this->assertDatabaseHas('ocr_vendor_mappings', [
            'extracted_name' => $extractedName,
            'vendor_id' => $vendor->id,
            'created_by' => $this->user->id,
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
            'normalized_name' => 'abc supplies ltd',
            'vendor_id' => $vendor->id,
            'confidence_score' => 1.0,
            'match_count' => 5,
            'created_by' => $this->user->id,
        ]);

        // Test with typos
        $matches = $this->service->findVendorMatch('ABC Suplies Ltd'); // Missing 'p'

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
            'normalized_name' => 'abc supplies ltd',
            'vendor_id' => $vendor->id,
            'confidence_score' => 1.0,
            'match_count' => 5,
            'created_by' => $this->user->id,
        ]);

        // Test different cases
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

        // Store mapping multiple times
        $this->service->storeMapping($extractedName, $vendor->id, 0.85, $this->user->id);
        $this->service->storeMapping($extractedName, $vendor->id, 0.85, $this->user->id);
        $this->service->storeMapping($extractedName, $vendor->id, 0.85, $this->user->id);

        $mapping = OcrVendorMapping::where('extracted_name', $extractedName)->first();
        
        $this->assertEquals(3, $mapping->match_count);
    }

    /**
     * Test: Empty or null vendor name returns empty matches
     */
    public function test_empty_vendor_name_returns_empty_matches()
    {
        $matches = $this->service->findVendorMatch('');
        $this->assertEmpty($matches);

        $matches = $this->service->findVendorMatch(null);
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
            'normalized_name' => "oreillys supplies  co",
            'vendor_id' => $vendor->id,
            'confidence_score' => 1.0,
            'match_count' => 1,
            'created_by' => $this->user->id,
        ]);

        $matches = $this->service->findVendorMatch("OReillys Supplies and Co");

        $this->assertNotEmpty($matches);
    }

    /**
     * Test: Vendor with no mappings can still be found
     */
    public function test_vendor_with_no_mappings_can_still_be_found()
    {
        $vendor = Vendor::factory()->create([
            'name' => 'New Vendor Ltd',
        ]);

        // No mappings exist yet
        $matches = $this->service->findVendorMatch('New Vendor');

        // Should still find vendor by direct name match
        $this->assertNotEmpty($matches);
    }

    /**
     * Test: Normalization removes common suffixes
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
            $normalized = $this->service->normalizeName($input);
            $this->assertEquals($expected, $normalized, "Failed for: $input");
        }
    }
}
