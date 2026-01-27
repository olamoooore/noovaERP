<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EventManagement\Services\ArtistCanonicalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * ArtistManagementController - Task 6.4
 * 
 * Provides admin interface for artist canonicalization, merge conflict resolution,
 * bulk alias import and management, and artist relationship visualization.
 * 
 * Requirements: 22.3, 22.4
 */
class ArtistManagementController extends Controller
{
    protected ArtistCanonicalizationService $canonicalizationService;

    public function __construct(ArtistCanonicalizationService $canonicalizationService)
    {
        $this->canonicalizationService = $canonicalizationService;
    }

    /**
     * Get artist management dashboard overview.
     * 
     * @return JsonResponse
     */
    public function getDashboardOverview(): JsonResponse
    {
        try {
            // Get artist statistics
            $artistStats = $this->getArtistStatistics();
            
            // Get recent merge activities
            $recentMerges = $this->getRecentMergeActivities();
            
            // Get canonicalization queue status
            $queueStatus = $this->getCanonicalizationQueueStatus();
            
            // Get data quality metrics
            $qualityMetrics = $this->getDataQualityMetrics();
            
            return response()->json([
                'success' => true,
                'dashboard' => [
                    'statistics' => $artistStats,
                    'recent_merges' => $recentMerges,
                    'queue_status' => $queueStatus,
                    'quality_metrics' => $qualityMetrics,
                    'last_updated' => Carbon::now()->toISOString(),
                ],
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search artists with canonicalization support.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function searchArtists(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'search_term' => 'required|string|min:2|max:255',
                'artist_type' => 'nullable|in:artist,group',
                'language_code' => 'nullable|string|max:5',
                'include_inactive' => 'nullable|boolean',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }
            
            $searchOptions = [
                'artist_type' => $request->artist_type,
                'language_code' => $request->language_code,
                'include_inactive' => $request->get('include_inactive', false),
                'limit' => $request->get('limit', 20),
            ];
            
            $result = $this->canonicalizationService->searchArtists(
                $request->search_term,
                $searchOptions
            );
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get canonical artist details with aliases and relationships.
     * 
     * @param int $canonicalId
     * @return JsonResponse
     */
    public function getCanonicalArtist(int $canonicalId): JsonResponse
    {
        try {
            $result = $this->canonicalizationService->getCanonicalArtist($canonicalId);
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create new canonical artist.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createCanonicalArtist(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'canonical_name' => 'required|string|max:255',
                'canonical_type' => 'required|in:artist,group',
                'master_record_id' => 'required|integer',
                'primary_genre' => 'nullable|string|max:100',
                'country_origin' => 'nullable|string|size:3',
                'formation_date' => 'nullable|date',
                'dissolution_date' => 'nullable|date|after:formation_date',
                'metadata' => 'nullable|array',
                'is_active' => 'nullable|boolean',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }
            
            $artistData = $request->all();
            $artistData['created_by'] = auth()->user()->name ?? 'admin';
            
            $result = $this->canonicalizationService->createCanonicalArtist($artistData);
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create artist alias.
     * 
     * @param Request $request
     * @param int $canonicalId
     * @return JsonResponse
     */
    public function createAlias(Request $request, int $canonicalId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'alias_name' => 'required|string|max:255',
                'alias_type' => 'required|in:stage_name,romanized,translated,former_name,nickname,legal_name,localized,abbreviated',
                'language_code' => 'nullable|string|max:5',
                'script_code' => 'nullable|string|max:4',
                'is_primary' => 'nullable|boolean',
                'is_searchable' => 'nullable|boolean',
                'usage_frequency' => 'nullable|integer|min:0',
                'valid_from' => 'nullable|date',
                'valid_until' => 'nullable|date|after:valid_from',
                'source' => 'nullable|string|max:100',
                'confidence_score' => 'nullable|numeric|min:0|max:1',
                'metadata' => 'nullable|array',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }
            
            $aliasData = $request->all();
            $aliasData['created_by'] = auth()->user()->name ?? 'admin';
            
            $result = $this->canonicalizationService->createAlias($canonicalId, $aliasData);
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Initiate artist merge with conflict resolution.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function mergeArtists(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'source_artist_id' => 'required|integer|exists:artist_canonical,id',
                'target_artist_id' => 'required|integer|exists:artist_canonical,id|different:source_artist_id',
                'reason' => 'required|string|max:500',
                'notes' => 'nullable|string',
                'conflict_resolution' => 'nullable|array',
                'preserve_aliases' => 'nullable|boolean',
                'preserve_relationships' => 'nullable|boolean',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }
            
            $mergeOptions = [
                'reason' => $request->reason,
                'notes' => $request->notes,
                'conflict_resolution' => $request->get('conflict_resolution', []),
                'preserve_aliases' => $request->get('preserve_aliases', true),
                'preserve_relationships' => $request->get('preserve_relationships', true),
                'merged_by_user_id' => auth()->id() ?? 1,
            ];
            
            $result = $this->canonicalizationService->mergeArtists(
                $request->source_artist_id,
                $request->target_artist_id,
                $mergeOptions
            );
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get merge conflicts for review before merging.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getMergeConflicts(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'source_artist_id' => 'required|integer|exists:artist_canonical,id',
                'target_artist_id' => 'required|integer|exists:artist_canonical,id|different:source_artist_id',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }
            
            $conflicts = $this->analyzeMergeConflicts(
                $request->source_artist_id,
                $request->target_artist_id
            );
            
            return response()->json([
                'success' => true,
                'conflicts' => $conflicts,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk import aliases from CSV or JSON.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkImportAliases(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'canonical_id' => 'required|integer|exists:artist_canonical,id',
                'import_format' => 'required|in:csv,json',
                'import_data' => 'required|string',
                'overwrite_existing' => 'nullable|boolean',
                'default_alias_type' => 'nullable|in:stage_name,romanized,translated,former_name,nickname,legal_name,localized,abbreviated',
                'default_language_code' => 'nullable|string|max:5',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }
            
            $result = $this->processBulkAliasImport($request->all());
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get artist relationship visualization data.
     * 
     * @param int $canonicalId
     * @return JsonResponse
     */
    public function getArtistRelationshipVisualization(int $canonicalId): JsonResponse
    {
        try {
            $visualization = $this->buildRelationshipVisualization($canonicalId);
            
            return response()->json([
                'success' => true,
                'visualization' => $visualization,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get merge history and audit trail.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getMergeHistory(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'artist_id' => 'nullable|integer',
                'status' => 'nullable|in:pending,processing,completed,failed,rolled_back',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }
            
            $history = $this->getMergeHistoryData($request->all());
            
            return response()->json($history);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Private helper methods

    private function getArtistStatistics(): array
    {
        $stats = DB::table('artist_canonical')
            ->selectRaw('
                COUNT(*) as total_artists,
                SUM(CASE WHEN canonical_type = "artist" THEN 1 ELSE 0 END) as individual_artists,
                SUM(CASE WHEN canonical_type = "group" THEN 1 ELSE 0 END) as groups,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_artists,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_artists
            ')
            ->first();
        
        $aliasStats = DB::table('artist_aliases')
            ->selectRaw('
                COUNT(*) as total_aliases,
                COUNT(DISTINCT canonical_id) as artists_with_aliases,
                AVG(usage_frequency) as avg_usage_frequency
            ')
            ->first();
        
        return [
            'total_artists' => $stats->total_artists,
            'individual_artists' => $stats->individual_artists,
            'groups' => $stats->groups,
            'active_artists' => $stats->active_artists,
            'inactive_artists' => $stats->inactive_artists,
            'total_aliases' => $aliasStats->total_aliases,
            'artists_with_aliases' => $aliasStats->artists_with_aliases,
            'avg_usage_frequency' => round($aliasStats->avg_usage_frequency, 2),
        ];
    }

    private function getRecentMergeActivities(): array
    {
        return DB::table('artist_merge_log as aml')
            ->leftJoin('artist_canonical as source', 'aml.source_artist_id', '=', 'source.id')
            ->leftJoin('artist_canonical as target', 'aml.target_artist_id', '=', 'target.id')
            ->select([
                'aml.id',
                'aml.merge_status',
                'aml.merge_reason',
                'source.canonical_name as source_name',
                'target.canonical_name as target_name',
                'aml.merge_initiated_at',
                'aml.merge_completed_at',
            ])
            ->orderBy('aml.merge_initiated_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    private function getCanonicalizationQueueStatus(): array
    {
        $queueStats = DB::table('artist_canonicalization_queue')
            ->selectRaw('
                status,
                COUNT(*) as count,
                AVG(retry_count) as avg_retries
            ')
            ->groupBy('status')
            ->get()
            ->keyBy('status');
        
        return [
            'pending' => $queueStats->get('pending')->count ?? 0,
            'processing' => $queueStats->get('processing')->count ?? 0,
            'completed' => $queueStats->get('completed')->count ?? 0,
            'failed' => $queueStats->get('failed')->count ?? 0,
            'avg_retries' => round($queueStats->avg('avg_retries'), 2),
        ];
    }

    private function getDataQualityMetrics(): array
    {
        // Artists without aliases
        $artistsWithoutAliases = DB::table('artist_canonical as ac')
            ->leftJoin('artist_aliases as aa', 'ac.id', '=', 'aa.canonical_id')
            ->whereNull('aa.canonical_id')
            ->count();
        
        // Duplicate potential (similar names)
        $potentialDuplicates = DB::table('artist_canonical')
            ->selectRaw('canonical_name, COUNT(*) as count')
            ->groupBy('canonical_name')
            ->having('count', '>', 1)
            ->count();
        
        // Low confidence aliases
        $lowConfidenceAliases = DB::table('artist_aliases')
            ->where('confidence_score', '<', 0.7)
            ->count();
        
        return [
            'artists_without_aliases' => $artistsWithoutAliases,
            'potential_duplicates' => $potentialDuplicates,
            'low_confidence_aliases' => $lowConfidenceAliases,
            'data_quality_score' => $this->calculateDataQualityScore(),
        ];
    }

    private function calculateDataQualityScore(): float
    {
        // Simple quality score calculation
        $totalArtists = DB::table('artist_canonical')->count();
        if ($totalArtists == 0) return 100.0;
        
        $artistsWithAliases = DB::table('artist_canonical as ac')
            ->join('artist_aliases as aa', 'ac.id', '=', 'aa.canonical_id')
            ->distinct('ac.id')
            ->count();
        
        $aliasRatio = $artistsWithAliases / $totalArtists;
        return round($aliasRatio * 100, 2);
    }

    private function analyzeMergeConflicts(int $sourceArtistId, int $targetArtistId): array
    {
        $conflicts = [];
        
        // Check alias conflicts
        $aliasConflicts = DB::table('artist_aliases as source_alias')
            ->join('artist_aliases as target_alias', function ($join) {
                $join->on('source_alias.alias_name', '=', 'target_alias.alias_name')
                     ->on('source_alias.alias_type', '=', 'target_alias.alias_type');
            })
            ->where('source_alias.canonical_id', $sourceArtistId)
            ->where('target_alias.canonical_id', $targetArtistId)
            ->select([
                'source_alias.alias_name',
                'source_alias.alias_type',
                'source_alias.confidence_score as source_confidence',
                'target_alias.confidence_score as target_confidence',
            ])
            ->get();
        
        if ($aliasConflicts->isNotEmpty()) {
            $conflicts['aliases'] = $aliasConflicts->toArray();
        }
        
        // Check relationship conflicts
        $relationshipConflicts = DB::table('artist_relationships as source_rel')
            ->join('artist_relationships as target_rel', function ($join) {
                $join->on('source_rel.related_artist_id', '=', 'target_rel.related_artist_id')
                     ->on('source_rel.relationship_type', '=', 'target_rel.relationship_type');
            })
            ->where('source_rel.primary_artist_id', $sourceArtistId)
            ->where('target_rel.primary_artist_id', $targetArtistId)
            ->select([
                'source_rel.related_artist_id',
                'source_rel.relationship_type',
                'source_rel.relationship_strength as source_strength',
                'target_rel.relationship_strength as target_strength',
            ])
            ->get();
        
        if ($relationshipConflicts->isNotEmpty()) {
            $conflicts['relationships'] = $relationshipConflicts->toArray();
        }
        
        return $conflicts;
    }

    private function processBulkAliasImport(array $importData): array
    {
        $canonicalId = $importData['canonical_id'];
        $format = $importData['import_format'];
        $data = $importData['import_data'];
        $overwrite = $importData['overwrite_existing'] ?? false;
        
        $imported = 0;
        $skipped = 0;
        $errors = [];
        
        try {
            if ($format === 'json') {
                $aliases = json_decode($data, true);
            } else {
                // Parse CSV
                $lines = str_getcsv($data, "\n");
                $aliases = [];
                foreach ($lines as $line) {
                    $fields = str_getcsv($line);
                    if (count($fields) >= 2) {
                        $aliases[] = [
                            'alias_name' => $fields[0],
                            'alias_type' => $fields[1] ?? $importData['default_alias_type'] ?? 'stage_name',
                            'language_code' => $fields[2] ?? $importData['default_language_code'] ?? null,
                        ];
                    }
                }
            }
            
            foreach ($aliases as $aliasData) {
                $aliasData['created_by'] = auth()->user()->name ?? 'bulk_import';
                
                $result = $this->canonicalizationService->createAlias($canonicalId, $aliasData);
                
                if ($result['success']) {
                    $imported++;
                } else {
                    if (str_contains($result['error'], 'already exists') && !$overwrite) {
                        $skipped++;
                    } else {
                        $errors[] = [
                            'alias_name' => $aliasData['alias_name'],
                            'error' => $result['error'],
                        ];
                    }
                }
            }
            
            return [
                'success' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function buildRelationshipVisualization(int $canonicalId): array
    {
        // Get the main artist
        $mainArtist = DB::table('artist_canonical')->where('id', $canonicalId)->first();
        
        if (!$mainArtist) {
            return ['nodes' => [], 'edges' => []];
        }
        
        // Get relationships
        $relationships = DB::table('artist_relationships as ar')
            ->leftJoin('artist_canonical as ac', 'ar.related_artist_id', '=', 'ac.id')
            ->where('ar.primary_artist_id', $canonicalId)
            ->where('ar.is_active', true)
            ->select([
                'ar.related_artist_id',
                'ac.canonical_name',
                'ac.canonical_type',
                'ar.relationship_type',
                'ar.relationship_strength',
            ])
            ->get();
        
        // Build nodes
        $nodes = [
            [
                'id' => $canonicalId,
                'label' => $mainArtist->canonical_name,
                'type' => $mainArtist->canonical_type,
                'is_main' => true,
            ]
        ];
        
        // Build edges
        $edges = [];
        
        foreach ($relationships as $rel) {
            $nodes[] = [
                'id' => $rel->related_artist_id,
                'label' => $rel->canonical_name,
                'type' => $rel->canonical_type,
                'is_main' => false,
            ];
            
            $edges[] = [
                'from' => $canonicalId,
                'to' => $rel->related_artist_id,
                'label' => $rel->relationship_type,
                'strength' => $rel->relationship_strength,
            ];
        }
        
        return [
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    private function getMergeHistoryData(array $filters): array
    {
        $query = DB::table('artist_merge_log as aml')
            ->leftJoin('artist_canonical as source', 'aml.source_artist_id', '=', 'source.id')
            ->leftJoin('artist_canonical as target', 'aml.target_artist_id', '=', 'target.id')
            ->select([
                'aml.id',
                'aml.source_artist_id',
                'aml.target_artist_id',
                'source.canonical_name as source_name',
                'target.canonical_name as target_name',
                'aml.merge_reason',
                'aml.merge_status',
                'aml.merged_by_user_id',
                'aml.merge_initiated_at',
                'aml.merge_completed_at',
                'aml.merge_notes',
            ]);
        
        // Apply filters
        if (isset($filters['artist_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('aml.source_artist_id', $filters['artist_id'])
                  ->orWhere('aml.target_artist_id', $filters['artist_id']);
            });
        }
        
        if (isset($filters['status'])) {
            $query->where('aml.merge_status', $filters['status']);
        }
        
        if (isset($filters['date_from'])) {
            $query->where('aml.merge_initiated_at', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('aml.merge_initiated_at', '<=', $filters['date_to']);
        }
        
        // Pagination
        $perPage = $filters['per_page'] ?? 20;
        $page = $filters['page'] ?? 1;
        $offset = ($page - 1) * $perPage;
        
        $total = $query->count();
        $history = $query->orderBy('aml.merge_initiated_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();
        
        return [
            'success' => true,
            'history' => $history,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
            ],
        ];
    }
}