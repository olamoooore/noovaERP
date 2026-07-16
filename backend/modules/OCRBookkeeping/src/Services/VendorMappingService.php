<?php

namespace Modules\OCRBookkeeping\Services;

use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Models\OcrVendorMapping;

/**
 * Vendor Mapping Service
 *
 * Provides intelligent vendor matching and learning capabilities
 * Uses fuzzy search with Levenshtein distance for name matching
 */
class VendorMappingService
{
    /**
     * Confidence threshold for automatic matching
     */
    private const AUTO_MATCH_THRESHOLD = 0.85;

    /**
     * Maximum number of suggestions to return
     */
    private const MAX_SUGGESTIONS = 3;

    /**
     * Find matching vendor using fuzzy search
     *
     * @param  string  $extractedName  The vendor name extracted from OCR
     * @return array Array of vendor matches with confidence scores
     */
    public function findVendorMatch(string $extractedName): array
    {
        if (empty($extractedName) || $extractedName === 'Unknown') {
            return [];
        }

        Log::info("Finding vendor match for: {$extractedName}");

        // Step 1: Check exact match in mappings table
        $normalizedName = OcrVendorMapping::normalizeName($extractedName);
        $existingMapping = OcrVendorMapping::byNormalizedName($normalizedName)
            ->with('vendor')
            ->first();

        if ($existingMapping && $existingMapping->vendor) {
            Log::info("Found exact mapping for: {$extractedName} -> {$existingMapping->vendor->name}");

            return [[
                'vendor_id' => $existingMapping->vendor_id,
                'name' => $existingMapping->vendor->name,
                'confidence' => min(1.0, $existingMapping->confidence_score ?? 0.95),
                'source' => 'learned_mapping',
            ]];
        }

        // Step 2: Fuzzy search in customers table
        $matches = $this->fuzzySearchVendors($extractedName, $normalizedName);

        Log::info("Found {count} fuzzy matches for: {$extractedName}", [
            'count' => count($matches),
        ]);

        return array_slice($matches, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Perform fuzzy search on vendors using Levenshtein distance
     *
     * @param  string  $extractedName  Original extracted name
     * @param  string  $normalizedName  Normalized version
     * @return array Array of matches with confidence scores
     */
    private function fuzzySearchVendors(string $extractedName, string $normalizedName): array
    {
        // Get all active vendors
        $vendors = Vendor::select('id', 'name', 'email', 'phone')
            ->whereNull('deleted_at')
            ->get();

        $matches = [];

        foreach ($vendors as $vendor) {
            $vendorNormalized = OcrVendorMapping::normalizeName($vendor->name);

            // Calculate similarity using multiple methods
            $confidence = $this->calculateSimilarity($normalizedName, $vendorNormalized, $extractedName, $vendor->name);

            if ($confidence > 0.5) { // Minimum threshold to consider
                $matches[] = [
                    'vendor_id' => $vendor->id,
                    'name' => $vendor->name,
                    'confidence' => $confidence,
                    'source' => 'fuzzy_search',
                    'email' => $vendor->email,
                    'phone' => $vendor->phone,
                ];
            }
        }

        // Sort by confidence descending
        usort($matches, function ($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });

        return $matches;
    }

    /**
     * Calculate similarity between two names using multiple algorithms
     *
     * @param  string  $normalizedExtracted  Normalized extracted name
     * @param  string  $normalizedVendor  Normalized vendor name
     * @param  string  $originalExtracted  Original extracted name
     * @param  string  $originalVendor  Original vendor name
     * @return float Confidence score between 0 and 1
     */
    private function calculateSimilarity(
        string $normalizedExtracted,
        string $normalizedVendor,
        string $originalExtracted,
        string $originalVendor
    ): float {
        // Method 1: Exact match on normalized names
        if ($normalizedExtracted === $normalizedVendor) {
            return 1.0;
        }

        // Method 2: Contains check
        if (str_contains($normalizedVendor, $normalizedExtracted) ||
            str_contains($normalizedExtracted, $normalizedVendor)) {
            return 0.9;
        }

        // Method 3: Levenshtein distance
        $levenshteinScore = $this->levenshteinSimilarity($normalizedExtracted, $normalizedVendor);

        // Method 4: Similar text percentage
        similar_text($normalizedExtracted, $normalizedVendor, $similarTextPercent);
        $similarTextScore = $similarTextPercent / 100;

        // Method 5: Word overlap
        $wordOverlapScore = $this->wordOverlapSimilarity($normalizedExtracted, $normalizedVendor);

        // Weighted average of all methods
        $confidence = (
            $levenshteinScore * 0.4 +
            $similarTextScore * 0.3 +
            $wordOverlapScore * 0.3
        );

        return min(1.0, max(0.0, $confidence));
    }

    /**
     * Calculate similarity using Levenshtein distance
     *
     * @return float Similarity score between 0 and 1
     */
    private function levenshteinSimilarity(string $str1, string $str2): float
    {
        $maxLen = max(strlen($str1), strlen($str2));

        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);

        return 1 - ($distance / $maxLen);
    }

    /**
     * Calculate similarity based on word overlap
     *
     * @return float Similarity score between 0 and 1
     */
    private function wordOverlapSimilarity(string $str1, string $str2): float
    {
        $words1 = array_filter(explode(' ', $str1));
        $words2 = array_filter(explode(' ', $str2));

        if (empty($words1) || empty($words2)) {
            return 0.0;
        }

        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        return count($intersection) / count($union);
    }

    /**
     * Store confirmed vendor mapping for learning
     *
     * @param  string  $extractedName  The extracted vendor name
     * @param  int  $vendorId  The confirmed vendor ID
     * @param  float|null  $confidenceScore  Optional confidence score
     */
    public function storeMapping(string $extractedName, int $vendorId, ?float $confidenceScore = null): OcrVendorMapping
    {
        $normalizedName = OcrVendorMapping::normalizeName($extractedName);

        Log::info('Storing vendor mapping', [
            'extracted_name' => $extractedName,
            'normalized_name' => $normalizedName,
            'vendor_id' => $vendorId,
        ]);

        // Check if mapping already exists
        $mapping = OcrVendorMapping::byNormalizedName($normalizedName)->first();

        if ($mapping) {
            // Update existing mapping
            $mapping->recordUsage();

            if ($confidenceScore !== null) {
                $mapping->updateConfidence($confidenceScore);
            }

            Log::info('Updated existing vendor mapping', [
                'mapping_id' => $mapping->id,
                'usage_count' => $mapping->usage_count,
            ]);

            return $mapping;
        }

        // Create new mapping
        $mapping = OcrVendorMapping::create([
            'extracted_name' => $extractedName,
            'normalized_name' => $normalizedName,
            'vendor_id' => $vendorId,
            'confidence_score' => $confidenceScore ?? 0.95,
            'usage_count' => 1,
        ]);

        Log::info('Created new vendor mapping', [
            'mapping_id' => $mapping->id,
        ]);

        return $mapping;
    }

    /**
     * Get the best match (highest confidence) for a vendor name
     *
     * @return array|null Best match or null if no good match found
     */
    public function getBestMatch(string $extractedName): ?array
    {
        $matches = $this->findVendorMatch($extractedName);

        if (empty($matches)) {
            return null;
        }

        $bestMatch = $matches[0];

        // Only return if confidence is above threshold
        if ($bestMatch['confidence'] >= self::AUTO_MATCH_THRESHOLD) {
            return $bestMatch;
        }

        return null;
    }

    /**
     * Check if a vendor name should be auto-matched
     */
    public function shouldAutoMatch(string $extractedName): bool
    {
        $bestMatch = $this->getBestMatch($extractedName);

        return $bestMatch !== null;
    }

    /**
     * Get vendor mapping statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_mappings' => OcrVendorMapping::count(),
            'high_confidence_mappings' => OcrVendorMapping::where('confidence_score', '>=', self::AUTO_MATCH_THRESHOLD)->count(),
            'most_used_mappings' => OcrVendorMapping::mostUsed()->take(10)->get(),
            'recently_used_mappings' => OcrVendorMapping::recentlyUsed()->take(10)->get(),
        ];
    }

    /**
     * Bulk import vendor mappings from array
     *
     * @param  array  $mappings  Array of ['extracted_name' => 'vendor_id'] pairs
     * @return int Number of mappings created
     */
    public function bulkImport(array $mappings): int
    {
        $count = 0;

        DB::beginTransaction();
        try {
            foreach ($mappings as $extractedName => $vendorId) {
                $this->storeMapping($extractedName, $vendorId);
                $count++;
            }

            DB::commit();
            Log::info("Bulk imported {$count} vendor mappings");

            return $count;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Bulk import failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Clear old unused mappings
     *
     * @param  int  $daysOld  Number of days since last use
     * @return int Number of mappings deleted
     */
    public function clearOldMappings(int $daysOld = 365): int
    {
        $cutoffDate = now()->subDays($daysOld);

        $count = OcrVendorMapping::where('last_used_at', '<', $cutoffDate)
            ->where('usage_count', '<', 5) // Keep frequently used mappings
            ->delete();

        Log::info("Cleared {$count} old vendor mappings");

        return $count;
    }
}
