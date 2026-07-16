<?php

namespace Modules\EventManagement\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArtistCanonicalizationService
{
    private const CACHE_PREFIX = 'artist_canonical:';

    private const CACHE_TTL = 3600; // 1 hour

    private const SEARCH_CACHE_TTL = 1800; // 30 minutes

    /**
     * Create canonical artist entry
     */
    public function createCanonicalArtist(int $artistId, string $canonicalName, string $canonicalType, array $metadata = []): int
    {
        try {
            DB::beginTransaction();

            // Check if artist already has canonical entry
            $existing = DB::table('artist_canonical')
                ->where('master_record_id', $artistId)
                ->first();

            if ($existing) {
                throw new Exception("Artist {$artistId} already has canonical entry: {$existing->canonical_name}");
            }

            // Create canonical entry
            $canonicalId = DB::table('artist_canonical')->insertGetId([
                'canonical_name' => $canonicalName,
                'canonical_type' => $canonicalType,
                'master_record_id' => $artistId,
                'metadata' => json_encode($metadata),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // Create primary alias
            $this->createAlias($canonicalId, $canonicalName, 'stage_name', null, true);

            // Build search index
            $this->buildSearchIndexForArtist($artistId, $canonicalId);

            DB::commit();

            // Clear relevant caches
            $this->clearArtistCaches($artistId);

            Log::info('Created canonical artist', [
                'artist_id' => $artistId,
                'canonical_id' => $canonicalId,
                'canonical_name' => $canonicalName,
                'canonical_type' => $canonicalType,
            ]);

            return $canonicalId;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to create canonical artist', [
                'artist_id' => $artistId,
                'canonical_name' => $canonicalName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create alias for canonical artist
     */
    public function createAlias(int $canonicalId, string $aliasName, string $aliasType, ?string $languageCode = null, bool $isPrimary = false): int
    {
        try {
            // If setting as primary, unset other primary aliases of same type
            if ($isPrimary) {
                DB::table('artist_aliases')
                    ->where('canonical_id', $canonicalId)
                    ->where('alias_type', $aliasType)
                    ->update(['is_primary' => false]);
            }

            $aliasId = DB::table('artist_aliases')->insertGetId([
                'canonical_id' => $canonicalId,
                'alias_name' => $aliasName,
                'alias_type' => $aliasType,
                'language_code' => $languageCode,
                'is_primary' => $isPrimary,
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // Update search index
            $canonical = DB::table('artist_canonical')->find($canonicalId);
            if ($canonical) {
                $this->addToSearchIndex($canonical->master_record_id, $canonicalId, $aliasName, $aliasType, $languageCode);
            }

            // Clear caches
            $this->clearCanonicalCaches($canonicalId);

            return $aliasId;

        } catch (Exception $e) {
            Log::error('Failed to create artist alias', [
                'canonical_id' => $canonicalId,
                'alias_name' => $aliasName,
                'alias_type' => $aliasType,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Merge two artists with conflict resolution
     */
    public function mergeArtists(int $sourceArtistId, int $targetArtistId, string $mergeReason, int $mergedByUserId, array $conflictResolution = []): bool
    {
        try {
            DB::beginTransaction();

            // Get source and target artist data
            $sourceArtist = DB::table('artists')->find($sourceArtistId);
            $targetArtist = DB::table('artists')->find($targetArtistId);

            if (! $sourceArtist || ! $targetArtist) {
                throw new Exception('Source or target artist not found');
            }

            // Get canonical entries
            $sourceCanonical = DB::table('artist_canonical')
                ->where('master_record_id', $sourceArtistId)
                ->first();
            $targetCanonical = DB::table('artist_canonical')
                ->where('master_record_id', $targetArtistId)
                ->first();

            // Prepare merge data snapshot
            $mergeData = [
                'source_artist' => $sourceArtist,
                'target_artist' => $targetArtist,
                'source_canonical' => $sourceCanonical,
                'target_canonical' => $targetCanonical,
                'merge_timestamp' => Carbon::now()->toISOString(),
            ];

            // Log the merge
            $mergeLogId = DB::table('artist_merge_log')->insertGetId([
                'source_artist_id' => $sourceArtistId,
                'target_artist_id' => $targetArtistId,
                'merge_reason' => $mergeReason,
                'merged_data' => json_encode($mergeData),
                'conflict_resolution' => json_encode($conflictResolution),
                'merged_by_user_id' => $mergedByUserId,
                'merged_at' => Carbon::now(),
                'is_reversible' => true,
            ]);

            // Merge aliases if both have canonical entries
            if ($sourceCanonical && $targetCanonical) {
                $this->mergeAliases($sourceCanonical->id, $targetCanonical->id);
            } elseif ($sourceCanonical && ! $targetCanonical) {
                // Update canonical to point to target artist
                DB::table('artist_canonical')
                    ->where('id', $sourceCanonical->id)
                    ->update(['master_record_id' => $targetArtistId]);
            } elseif (! $sourceCanonical && $targetCanonical) {
                // Target already has canonical, nothing to do
            } else {
                // Neither has canonical, create one for target
                $this->createCanonicalArtist($targetArtistId, $targetArtist->name, 'artist');
            }

            // Update event associations
            $this->mergeEventAssociations($sourceArtistId, $targetArtistId);

            // Update artist relationships
            $this->mergeArtistRelationships($sourceArtistId, $targetArtistId);

            // Update search index
            $this->rebuildSearchIndexForArtist($targetArtistId);

            // Deactivate source artist (don't delete to preserve audit trail)
            DB::table('artists')
                ->where('id', $sourceArtistId)
                ->update([
                    'is_active' => false,
                    'merged_into_artist_id' => $targetArtistId,
                    'updated_at' => Carbon::now(),
                ]);

            DB::commit();

            // Clear caches
            $this->clearArtistCaches($sourceArtistId);
            $this->clearArtistCaches($targetArtistId);

            Log::info('Successfully merged artists', [
                'source_artist_id' => $sourceArtistId,
                'target_artist_id' => $targetArtistId,
                'merge_log_id' => $mergeLogId,
                'merged_by_user_id' => $mergedByUserId,
            ]);

            return true;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to merge artists', [
                'source_artist_id' => $sourceArtistId,
                'target_artist_id' => $targetArtistId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Search artists with alias support
     */
    public function searchArtists(string $query, int $limit = 20, array $filters = []): array
    {
        $cacheKey = self::CACHE_PREFIX.'search:'.md5($query.serialize($filters).$limit);

        return Cache::remember($cacheKey, self::SEARCH_CACHE_TTL, function () use ($query, $limit, $filters) {
            $searchQuery = DB::table('artist_search_index as asi')
                ->join('artists as a', 'asi.artist_id', '=', 'a.id')
                ->join('artist_canonical as ac', 'asi.canonical_id', '=', 'ac.id')
                ->where('a.is_active', true)
                ->where('asi.search_term', 'LIKE', "%{$query}%");

            // Apply filters
            if (isset($filters['canonical_type'])) {
                $searchQuery->where('ac.canonical_type', $filters['canonical_type']);
            }

            if (isset($filters['language_code'])) {
                $searchQuery->where('asi.language_code', $filters['language_code']);
            }

            if (isset($filters['term_type'])) {
                $searchQuery->where('asi.term_type', $filters['term_type']);
            }

            $results = $searchQuery
                ->select([
                    'a.id as artist_id',
                    'a.name as artist_name',
                    'ac.id as canonical_id',
                    'ac.canonical_name',
                    'ac.canonical_type',
                    'asi.search_term as matched_term',
                    'asi.term_type as match_type',
                    'asi.search_weight',
                    DB::raw('CASE 
                        WHEN asi.search_term = ? THEN 100
                        WHEN asi.search_term LIKE ? THEN 90
                        WHEN asi.search_term LIKE ? THEN 80
                        ELSE asi.search_weight * 10
                    END as relevance_score'),
                ])
                ->addBinding([$query, "{$query}%", "%{$query}%"])
                ->orderBy('relevance_score', 'desc')
                ->orderBy('asi.search_weight', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();

            // Group by artist and add aliases
            $groupedResults = [];
            foreach ($results as $result) {
                $artistId = $result->artist_id;

                if (! isset($groupedResults[$artistId])) {
                    $groupedResults[$artistId] = [
                        'artist_id' => $result->artist_id,
                        'artist_name' => $result->artist_name,
                        'canonical_id' => $result->canonical_id,
                        'canonical_name' => $result->canonical_name,
                        'canonical_type' => $result->canonical_type,
                        'relevance_score' => $result->relevance_score,
                        'matched_terms' => [],
                        'aliases' => $this->getArtistAliases($result->canonical_id),
                    ];
                }

                $groupedResults[$artistId]['matched_terms'][] = [
                    'term' => $result->matched_term,
                    'type' => $result->match_type,
                    'weight' => $result->search_weight,
                ];
            }

            return array_values($groupedResults);
        });
    }

    /**
     * Get artist aliases
     */
    public function getArtistAliases(int $canonicalId): array
    {
        return DB::table('artist_aliases')
            ->where('canonical_id', $canonicalId)
            ->where('is_active', true)
            ->orderBy('is_primary', 'desc')
            ->orderBy('alias_type')
            ->get()
            ->toArray();
    }

    /**
     * Get canonical artist information
     */
    public function getCanonicalArtist(int $artistId): ?array
    {
        $cacheKey = self::CACHE_PREFIX."artist:{$artistId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($artistId) {
            $canonical = DB::table('artist_canonical as ac')
                ->join('artists as a', 'ac.master_record_id', '=', 'a.id')
                ->where('a.id', $artistId)
                ->select([
                    'ac.id as canonical_id',
                    'ac.canonical_name',
                    'ac.canonical_type',
                    'ac.metadata',
                    'a.id as artist_id',
                    'a.name as artist_name',
                    'a.bio',
                    'a.image_url',
                ])
                ->first();

            if (! $canonical) {
                return null;
            }

            $result = (array) $canonical;
            $result['metadata'] = json_decode($result['metadata'], true);
            $result['aliases'] = $this->getArtistAliases($canonical->canonical_id);
            $result['relationships'] = $this->getArtistRelationships($artistId);

            return $result;
        });
    }

    /**
     * Get artist relationships
     */
    public function getArtistRelationships(int $artistId): array
    {
        return DB::table('artist_relationships as ar')
            ->join('artists as related', 'ar.related_artist_id', '=', 'related.id')
            ->leftJoin('artist_canonical as ac', 'related.id', '=', 'ac.master_record_id')
            ->where('ar.primary_artist_id', $artistId)
            ->where('ar.is_active', true)
            ->select([
                'ar.id as relationship_id',
                'ar.relationship_type',
                'ar.relationship_start',
                'ar.relationship_end',
                'ar.relationship_metadata',
                'related.id as related_artist_id',
                'related.name as related_artist_name',
                'ac.canonical_name as related_canonical_name',
            ])
            ->orderBy('ar.relationship_start', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Merge aliases from source to target canonical
     */
    private function mergeAliases(int $sourceCanonicalId, int $targetCanonicalId): void
    {
        $sourceAliases = DB::table('artist_aliases')
            ->where('canonical_id', $sourceCanonicalId)
            ->get();

        foreach ($sourceAliases as $alias) {
            // Check if alias already exists for target
            $existing = DB::table('artist_aliases')
                ->where('canonical_id', $targetCanonicalId)
                ->where('alias_name', $alias->alias_name)
                ->where('alias_type', $alias->alias_type)
                ->first();

            if (! $existing) {
                // Move alias to target canonical
                DB::table('artist_aliases')
                    ->where('id', $alias->id)
                    ->update([
                        'canonical_id' => $targetCanonicalId,
                        'updated_at' => Carbon::now(),
                    ]);
            } else {
                // Mark source alias as inactive
                DB::table('artist_aliases')
                    ->where('id', $alias->id)
                    ->update(['is_active' => false]);
            }
        }
    }

    /**
     * Merge event associations
     */
    private function mergeEventAssociations(int $sourceArtistId, int $targetArtistId): void
    {
        $sourceEvents = DB::table('artist_events')
            ->where('artist_id', $sourceArtistId)
            ->get();

        foreach ($sourceEvents as $event) {
            // Check if target artist is already associated with this event
            $existing = DB::table('artist_events')
                ->where('artist_id', $targetArtistId)
                ->where('event_id', $event->event_id)
                ->first();

            if (! $existing) {
                // Update association to target artist
                DB::table('artist_events')
                    ->where('id', $event->id)
                    ->update(['artist_id' => $targetArtistId]);
            } else {
                // Remove duplicate association
                DB::table('artist_events')
                    ->where('id', $event->id)
                    ->delete();
            }
        }
    }

    /**
     * Merge artist relationships
     */
    private function mergeArtistRelationships(int $sourceArtistId, int $targetArtistId): void
    {
        // Update relationships where source is primary
        DB::table('artist_relationships')
            ->where('primary_artist_id', $sourceArtistId)
            ->update(['primary_artist_id' => $targetArtistId]);

        // Update relationships where source is related
        DB::table('artist_relationships')
            ->where('related_artist_id', $sourceArtistId)
            ->update(['related_artist_id' => $targetArtistId]);
    }

    /**
     * Build search index for artist
     */
    private function buildSearchIndexForArtist(int $artistId, int $canonicalId): void
    {
        // Clear existing index entries
        DB::table('artist_search_index')
            ->where('artist_id', $artistId)
            ->delete();

        // Get canonical name
        $canonical = DB::table('artist_canonical')->find($canonicalId);
        if ($canonical) {
            $this->addToSearchIndex($artistId, $canonicalId, $canonical->canonical_name, 'canonical');
        }

        // Get all aliases
        $aliases = DB::table('artist_aliases')
            ->where('canonical_id', $canonicalId)
            ->where('is_active', true)
            ->get();

        foreach ($aliases as $alias) {
            $this->addToSearchIndex($artistId, $canonicalId, $alias->alias_name, $alias->alias_type, $alias->language_code);
        }
    }

    /**
     * Add entry to search index
     */
    private function addToSearchIndex(int $artistId, int $canonicalId, string $searchTerm, string $termType, ?string $languageCode = null): void
    {
        $weight = $this->calculateSearchWeight($termType);

        DB::table('artist_search_index')->insert([
            'artist_id' => $artistId,
            'canonical_id' => $canonicalId,
            'search_term' => $searchTerm,
            'term_type' => $termType,
            'search_weight' => $weight,
            'language_code' => $languageCode,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Calculate search weight based on term type
     */
    private function calculateSearchWeight(string $termType): float
    {
        $weights = [
            'canonical' => 1.0,
            'stage_name' => 0.9,
            'romanized' => 0.8,
            'translated' => 0.7,
            'former_name' => 0.6,
            'nickname' => 0.5,
        ];

        return $weights[$termType] ?? 0.5;
    }

    /**
     * Rebuild search index for artist
     */
    private function rebuildSearchIndexForArtist(int $artistId): void
    {
        $canonical = DB::table('artist_canonical')
            ->where('master_record_id', $artistId)
            ->first();

        if ($canonical) {
            $this->buildSearchIndexForArtist($artistId, $canonical->id);
        }
    }

    /**
     * Clear artist-related caches
     */
    private function clearArtistCaches(int $artistId): void
    {
        Cache::forget(self::CACHE_PREFIX."artist:{$artistId}");
        Cache::flush(); // Clear search caches (they use complex keys)
    }

    /**
     * Clear canonical-related caches
     */
    private function clearCanonicalCaches(int $canonicalId): void
    {
        $canonical = DB::table('artist_canonical')->find($canonicalId);
        if ($canonical) {
            $this->clearArtistCaches($canonical->master_record_id);
        }
    }
}
