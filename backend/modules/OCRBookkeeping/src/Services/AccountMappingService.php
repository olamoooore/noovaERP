<?php

namespace Modules\OCRBookkeeping\Services;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Models\OcrAccountMapping;

/**
 * Account Mapping Service
 *
 * Suggests Chart of Accounts based on vendor history and description patterns
 * Uses keyword matching and machine learning from historical data
 */
class AccountMappingService
{
    /**
     * Minimum confidence threshold for suggestions
     */
    private const MIN_CONFIDENCE_THRESHOLD = 0.6;

    /**
     * Suggest account for vendor and description
     *
     * @param  int|null  $vendorId  The vendor ID (optional)
     * @param  string  $description  The line item description
     * @return int|null Suggested account ID or null if no good match
     */
    public function suggestAccount(?int $vendorId, string $description): ?int
    {
        if (empty($description)) {
            return null;
        }

        Log::info('Suggesting account', [
            'vendor_id' => $vendorId,
            'description' => $description,
        ]);

        // Step 1: Check vendor-specific mappings (highest priority)
        if ($vendorId) {
            $vendorMapping = $this->findVendorSpecificMapping($vendorId, $description);
            if ($vendorMapping) {
                Log::info('Found vendor-specific mapping', [
                    'account_id' => $vendorMapping['account_id'],
                    'confidence' => $vendorMapping['confidence'],
                ]);

                return $vendorMapping['account_id'];
            }
        }

        // Step 2: Check keyword-based mappings
        $keywordMapping = $this->findKeywordMapping($description);
        if ($keywordMapping) {
            Log::info('Found keyword-based mapping', [
                'account_id' => $keywordMapping['account_id'],
                'confidence' => $keywordMapping['confidence'],
            ]);

            return $keywordMapping['account_id'];
        }

        // Step 3: Fallback to default expense account
        $defaultAccount = $this->getDefaultExpenseAccount();
        if ($defaultAccount) {
            Log::info('Using default expense account', [
                'account_id' => $defaultAccount->id,
            ]);

            return $defaultAccount->id;
        }

        return null;
    }

    /**
     * Find vendor-specific account mapping
     *
     * @return array|null ['account_id' => int, 'confidence' => float]
     */
    private function findVendorSpecificMapping(int $vendorId, string $description): ?array
    {
        // Get all mappings for this vendor
        $mappings = OcrAccountMapping::byVendor($vendorId)
            ->with('account')
            ->mostUsed()
            ->get();

        if ($mappings->isEmpty()) {
            return null;
        }

        $bestMatch = null;
        $highestScore = 0;

        foreach ($mappings as $mapping) {
            // Calculate match score based on description similarity
            $score = $this->calculateMappingScore($mapping, $description);

            if ($score > $highestScore && $score >= self::MIN_CONFIDENCE_THRESHOLD) {
                $highestScore = $score;
                $bestMatch = [
                    'account_id' => $mapping->account_id,
                    'confidence' => $score,
                    'source' => 'vendor_history',
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Find keyword-based account mapping
     *
     * @return array|null ['account_id' => int, 'confidence' => float]
     */
    private function findKeywordMapping(string $description): ?array
    {
        $keywords = OcrAccountMapping::extractKeywords($description);

        if (empty($keywords)) {
            return null;
        }

        // Find mappings that match any of the keywords
        $mappings = OcrAccountMapping::whereNull('vendor_id') // Generic mappings only
            ->with('account')
            ->get();

        $bestMatch = null;
        $highestScore = 0;

        foreach ($mappings as $mapping) {
            $matchCount = $mapping->countMatchingKeywords($description);

            if ($matchCount > 0) {
                // Calculate confidence based on keyword matches and usage
                $keywordScore = $matchCount / max(1, count($keywords));
                $usageBonus = min(0.2, $mapping->usage_count * 0.01); // Up to 0.2 bonus
                $score = min(1.0, $keywordScore * 0.8 + $usageBonus);

                if ($score > $highestScore && $score >= self::MIN_CONFIDENCE_THRESHOLD) {
                    $highestScore = $score;
                    $bestMatch = [
                        'account_id' => $mapping->account_id,
                        'confidence' => $score,
                        'source' => 'keyword_match',
                        'matched_keywords' => $matchCount,
                    ];
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Calculate mapping score for a specific mapping and description
     *
     * @return float Score between 0 and 1
     */
    private function calculateMappingScore(OcrAccountMapping $mapping, string $description): float
    {
        $score = 0.0;

        // Check pattern match
        if ($mapping->description_pattern && $mapping->matchesDescription($description)) {
            $score += 0.5;
        }

        // Check keyword matches
        $keywordMatches = $mapping->countMatchingKeywords($description);
        if ($keywordMatches > 0) {
            $keywords = OcrAccountMapping::extractKeywords($description);
            $keywordScore = $keywordMatches / max(1, count($keywords));
            $score += $keywordScore * 0.4;
        }

        // Usage frequency bonus
        $usageBonus = min(0.1, $mapping->usage_count * 0.005);
        $score += $usageBonus;

        return min(1.0, $score);
    }

    /**
     * Get default expense account
     */
    private function getDefaultExpenseAccount(): ?ChartOfAccount
    {
        // Look for a general expense account (typically code 5000-5999)
        return ChartOfAccount::where('code', 'like', '5%')
            ->where('name', 'like', '%expense%')
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Store confirmed account mapping for learning
     *
     * @param  int|null  $vendorId  The vendor ID (null for generic mapping)
     * @param  string  $description  The description
     * @param  int  $accountId  The confirmed account ID
     */
    public function storeMapping(?int $vendorId, string $description, int $accountId): OcrAccountMapping
    {
        $keywords = OcrAccountMapping::extractKeywords($description);

        Log::info('Storing account mapping', [
            'vendor_id' => $vendorId,
            'description' => substr($description, 0, 50),
            'account_id' => $accountId,
            'keywords_count' => count($keywords),
        ]);

        // Check if similar mapping exists
        $query = OcrAccountMapping::byAccount($accountId);

        if ($vendorId) {
            $query->byVendor($vendorId);
        } else {
            $query->whereNull('vendor_id');
        }

        $existingMapping = $query->first();

        if ($existingMapping) {
            // Update existing mapping
            $existingMapping->recordUsage();

            // Merge keywords
            $existingKeywords = $existingMapping->keywords ?? [];
            $mergedKeywords = array_unique(array_merge($existingKeywords, $keywords));
            $existingMapping->update(['keywords' => $mergedKeywords]);

            Log::info('Updated existing account mapping', [
                'mapping_id' => $existingMapping->id,
                'usage_count' => $existingMapping->usage_count,
            ]);

            return $existingMapping;
        }

        // Create new mapping
        $mapping = OcrAccountMapping::create([
            'vendor_id' => $vendorId,
            'description_pattern' => substr($description, 0, 100), // Store first 100 chars as pattern
            'keywords' => $keywords,
            'account_id' => $accountId,
            'confidence_score' => 0.75,
            'usage_count' => 1,
        ]);

        Log::info('Created new account mapping', [
            'mapping_id' => $mapping->id,
        ]);

        return $mapping;
    }

    /**
     * Suggest accounts for multiple line items
     *
     * @param  array  $lineItems  Array of line items with 'description' field
     * @return array Array of suggestions indexed by line item index
     */
    public function suggestAccountsForLineItems(?int $vendorId, array $lineItems): array
    {
        $suggestions = [];

        foreach ($lineItems as $index => $item) {
            $description = $item['description'] ?? '';

            if (! empty($description)) {
                $accountId = $this->suggestAccount($vendorId, $description);

                if ($accountId) {
                    $account = ChartOfAccount::find($accountId);

                    $suggestions[$index] = [
                        'account_id' => $accountId,
                        'account_code' => $account->code ?? null,
                        'account_name' => $account->name ?? null,
                    ];
                }
            }
        }

        return $suggestions;
    }

    /**
     * Get account mapping statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_mappings' => OcrAccountMapping::count(),
            'vendor_specific_mappings' => OcrAccountMapping::whereNotNull('vendor_id')->count(),
            'generic_mappings' => OcrAccountMapping::whereNull('vendor_id')->count(),
            'most_used_mappings' => OcrAccountMapping::with('account')->mostUsed()->take(10)->get(),
            'recently_used_mappings' => OcrAccountMapping::with('account')->recentlyUsed()->take(10)->get(),
        ];
    }

    /**
     * Get all mappings for a specific vendor
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getVendorMappings(int $vendorId)
    {
        return OcrAccountMapping::byVendor($vendorId)
            ->with('account')
            ->mostUsed()
            ->get();
    }

    /**
     * Bulk import account mappings from array
     *
     * @param  array  $mappings  Array of mapping data
     * @return int Number of mappings created
     */
    public function bulkImport(array $mappings): int
    {
        $count = 0;

        DB::beginTransaction();
        try {
            foreach ($mappings as $mapping) {
                $this->storeMapping(
                    $mapping['vendor_id'] ?? null,
                    $mapping['description'],
                    $mapping['account_id']
                );
                $count++;
            }

            DB::commit();
            Log::info("Bulk imported {$count} account mappings");

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

        $count = OcrAccountMapping::where('last_used_at', '<', $cutoffDate)
            ->where('usage_count', '<', 3) // Keep frequently used mappings
            ->delete();

        Log::info("Cleared {$count} old account mappings");

        return $count;
    }

    /**
     * Train mappings from historical journal entries
     * This can be run periodically to improve suggestions
     *
     * @param  int  $limit  Number of recent entries to analyze
     * @return int Number of mappings created/updated
     */
    public function trainFromHistory(int $limit = 1000): int
    {
        // TODO: Implement training from historical journal entries
        // This would analyze past journal entries and create mappings
        // based on patterns in descriptions and account usage

        Log::info('Training from history not yet implemented');

        return 0;
    }
}
