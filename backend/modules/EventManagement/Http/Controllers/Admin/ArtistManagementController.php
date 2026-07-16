<?php

namespace Modules\EventManagement\Http\Controllers\Admin;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Services\ArtistCanonicalizationService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class ArtistManagementController extends Controller
{
    private ArtistCanonicalizationService $canonicalizationService;

    public function __construct(ArtistCanonicalizationService $canonicalizationService)
    {
        $this->canonicalizationService = $canonicalizationService;
    }

    /**
     * Get artist canonicalization dashboard
     */
    public function dashboard(): JsonResponse
    {
        try {
            $stats = [
                'total_artists' => DB::table('artists')->where('is_active', true)->count(),
                'canonical_artists' => DB::table('artist_canonical')->count(),
                'total_aliases' => DB::table('artist_aliases')->where('is_active', true)->count(),
                'recent_merges' => DB::table('artist_merge_log')
                    ->where('merged_at', '>=', Carbon::now()->subDays(30))
                    ->count(),
                'artists_without_canonical' => DB::table('artists as a')
                    ->leftJoin('artist_canonical as ac', 'a.id', '=', 'ac.master_record_id')
                    ->where('a.is_active', true)
                    ->whereNull('ac.id')
                    ->count(),
            ];

            $recentMerges = DB::table('artist_merge_log as aml')
                ->join('artists as source', 'aml.source_artist_id', '=', 'source.id')
                ->join('artists as target', 'aml.target_artist_id', '=', 'target.id')
                ->select([
                    'aml.id',
                    'aml.merge_reason',
                    'aml.merged_at',
                    'source.name as source_name',
                    'target.name as target_name',
                    'aml.is_reversible',
                ])
                ->orderBy('aml.merged_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'recent_merges' => $recentMerges,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load artist management dashboard',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search artists with canonicalization support
     */
    public function searchArtists(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'query' => 'required|string|min:2',
                'limit' => 'integer|min:1|max:100',
                'canonical_type' => 'in:artist,group',
                'language_code' => 'string|max:5',
                'term_type' => 'in:canonical,alias,romanized,translated',
            ]);

            $query = $request->get('query');
            $limit = $request->get('limit', 20);
            $filters = array_filter([
                'canonical_type' => $request->get('canonical_type'),
                'language_code' => $request->get('language_code'),
                'term_type' => $request->get('term_type'),
            ]);

            $results = $this->canonicalizationService->searchArtists($query, $limit, $filters);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Artist search failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get canonical artist details
     */
    public function getCanonicalArtist(int $artistId): JsonResponse
    {
        try {
            $canonical = $this->canonicalizationService->getCanonicalArtist($artistId);

            if (! $canonical) {
                return response()->json([
                    'success' => false,
                    'message' => 'Canonical artist not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $canonical,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get canonical artist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create canonical artist entry
     */
    public function createCanonicalArtist(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'artist_id' => 'required|integer|exists:artists,id',
                'canonical_name' => 'required|string|max:255',
                'canonical_type' => 'required|in:artist,group',
                'metadata' => 'array',
            ]);

            $canonicalId = $this->canonicalizationService->createCanonicalArtist(
                $request->get('artist_id'),
                $request->get('canonical_name'),
                $request->get('canonical_type'),
                $request->get('metadata', [])
            );

            return response()->json([
                'success' => true,
                'message' => 'Canonical artist created successfully',
                'data' => ['canonical_id' => $canonicalId],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create canonical artist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create artist alias
     */
    public function createAlias(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'canonical_id' => 'required|integer|exists:artist_canonical,id',
                'alias_name' => 'required|string|max:255',
                'alias_type' => 'required|in:stage_name,romanized,translated,former_name,nickname',
                'language_code' => 'nullable|string|max:5',
                'is_primary' => 'boolean',
            ]);

            $aliasId = $this->canonicalizationService->createAlias(
                $request->get('canonical_id'),
                $request->get('alias_name'),
                $request->get('alias_type'),
                $request->get('language_code'),
                $request->get('is_primary', false)
            );

            return response()->json([
                'success' => true,
                'message' => 'Artist alias created successfully',
                'data' => ['alias_id' => $aliasId],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create artist alias',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get merge candidates for an artist
     */
    public function getMergeCandidates(int $artistId): JsonResponse
    {
        try {
            $artist = DB::table('artists')->find($artistId);
            if (! $artist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Artist not found',
                ], 404);
            }

            // Find potential duplicates based on name similarity
            $candidates = $this->canonicalizationService->searchArtists($artist->name, 10);

            // Remove the source artist from candidates
            $candidates = array_filter($candidates, function ($candidate) use ($artistId) {
                return $candidate['artist_id'] !== $artistId;
            });

            // Add similarity scores and conflict analysis
            foreach ($candidates as &$candidate) {
                $candidate['similarity_score'] = $this->calculateSimilarityScore($artist->name, $candidate['canonical_name']);
                $candidate['conflicts'] = $this->analyzeConflicts($artistId, $candidate['artist_id']);
            }

            // Sort by similarity score
            usort($candidates, function ($a, $b) {
                return $b['similarity_score'] <=> $a['similarity_score'];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'source_artist' => $artist,
                    'candidates' => array_values($candidates),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get merge candidates',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Merge artists with conflict resolution
     */
    public function mergeArtists(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'source_artist_id' => 'required|integer|exists:artists,id',
                'target_artist_id' => 'required|integer|exists:artists,id',
                'merge_reason' => 'required|string|max:1000',
                'conflict_resolution' => 'array',
            ]);

            $sourceArtistId = $request->get('source_artist_id');
            $targetArtistId = $request->get('target_artist_id');

            if ($sourceArtistId === $targetArtistId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot merge artist with itself',
                ], 400);
            }

            $success = $this->canonicalizationService->mergeArtists(
                $sourceArtistId,
                $targetArtistId,
                $request->get('merge_reason'),
                TenantUserHelper::resolveTenantUserId(), // tenant-resolved user
                $request->get('conflict_resolution', [])
            );

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Artists merged successfully' : 'Artist merge failed',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to merge artists',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get merge history
     */
    public function getMergeHistory(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 50);
            $artistId = $request->get('artist_id');

            $query = DB::table('artist_merge_log as aml')
                ->join('artists as source', 'aml.source_artist_id', '=', 'source.id')
                ->join('artists as target', 'aml.target_artist_id', '=', 'target.id')
                ->select([
                    'aml.id',
                    'aml.source_artist_id',
                    'aml.target_artist_id',
                    'aml.merge_reason',
                    'aml.merged_data',
                    'aml.conflict_resolution',
                    'aml.merged_by_user_id',
                    'aml.merged_at',
                    'aml.is_reversible',
                    'aml.reversed_at',
                    'source.name as source_name',
                    'target.name as target_name',
                ]);

            if ($artistId) {
                $query->where(function ($q) use ($artistId) {
                    $q->where('aml.source_artist_id', $artistId)
                        ->orWhere('aml.target_artist_id', $artistId);
                });
            }

            $history = $query
                ->orderBy('aml.merged_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($merge) {
                    $merge->merged_data = json_decode($merge->merged_data, true);
                    $merge->conflict_resolution = json_decode($merge->conflict_resolution, true);

                    return $merge;
                });

            return response()->json([
                'success' => true,
                'data' => $history,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get merge history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk import aliases from CSV
     */
    public function bulkImportAliases(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,txt',
                'canonical_id' => 'required|integer|exists:artist_canonical,id',
            ]);

            $canonicalId = $request->get('canonical_id');
            $file = $request->file('file');

            $csvData = array_map('str_getcsv', file($file->getPathname()));
            $header = array_shift($csvData); // Remove header row

            $imported = 0;
            $errors = [];

            foreach ($csvData as $index => $row) {
                try {
                    if (count($row) < 2) {
                        continue; // Skip incomplete rows
                    }

                    $aliasName = trim($row[0]);
                    $aliasType = trim($row[1]);
                    $languageCode = isset($row[2]) ? trim($row[2]) : null;
                    $isPrimary = isset($row[3]) ? (bool) trim($row[3]) : false;

                    if (empty($aliasName) || empty($aliasType)) {
                        continue;
                    }

                    $this->canonicalizationService->createAlias(
                        $canonicalId,
                        $aliasName,
                        $aliasType,
                        $languageCode,
                        $isPrimary
                    );

                    $imported++;

                } catch (\Exception $e) {
                    $errors[] = 'Row '.($index + 2).': '.$e->getMessage();
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully imported {$imported} aliases",
                'data' => [
                    'imported_count' => $imported,
                    'errors' => $errors,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk import failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get artist relationship visualization data
     */
    public function getRelationshipVisualization(int $artistId): JsonResponse
    {
        try {
            $artist = DB::table('artists')->find($artistId);
            if (! $artist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Artist not found',
                ], 404);
            }

            // Get direct relationships
            $relationships = $this->canonicalizationService->getArtistRelationships($artistId);

            // Build visualization data
            $nodes = [
                [
                    'id' => $artistId,
                    'name' => $artist->name,
                    'type' => 'primary',
                    'canonical_name' => $this->canonicalizationService->getCanonicalArtist($artistId)['canonical_name'] ?? $artist->name,
                ],
            ];

            $edges = [];

            foreach ($relationships as $relationship) {
                $nodes[] = [
                    'id' => $relationship->related_artist_id,
                    'name' => $relationship->related_artist_name,
                    'type' => 'related',
                    'canonical_name' => $relationship->related_canonical_name ?? $relationship->related_artist_name,
                ];

                $edges[] = [
                    'source' => $artistId,
                    'target' => $relationship->related_artist_id,
                    'relationship_type' => $relationship->relationship_type,
                    'start_date' => $relationship->relationship_start,
                    'end_date' => $relationship->relationship_end,
                    'is_active' => is_null($relationship->relationship_end),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'nodes' => $nodes,
                    'edges' => $edges,
                    'center_artist' => $artistId,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get relationship visualization',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate similarity score between two names
     */
    private function calculateSimilarityScore(string $name1, string $name2): float
    {
        // Simple Levenshtein distance-based similarity
        $maxLength = max(strlen($name1), strlen($name2));
        if ($maxLength === 0) {
            return 1.0;
        }

        $distance = levenshtein(strtolower($name1), strtolower($name2));

        return 1.0 - ($distance / $maxLength);
    }

    /**
     * Analyze potential conflicts between two artists
     */
    private function analyzeConflicts(int $artistId1, int $artistId2): array
    {
        $conflicts = [];

        // Check for shared events
        $sharedEvents = DB::table('artist_events as ae1')
            ->join('artist_events as ae2', 'ae1.event_id', '=', 'ae2.event_id')
            ->where('ae1.artist_id', $artistId1)
            ->where('ae2.artist_id', $artistId2)
            ->count();

        if ($sharedEvents > 0) {
            $conflicts[] = [
                'type' => 'shared_events',
                'count' => $sharedEvents,
                'description' => "Both artists are associated with {$sharedEvents} shared events",
            ];
        }

        // Check for different canonical types
        $canonical1 = DB::table('artist_canonical')->where('master_record_id', $artistId1)->first();
        $canonical2 = DB::table('artist_canonical')->where('master_record_id', $artistId2)->first();

        if ($canonical1 && $canonical2 && $canonical1->canonical_type !== $canonical2->canonical_type) {
            $conflicts[] = [
                'type' => 'canonical_type_mismatch',
                'description' => "Different canonical types: {$canonical1->canonical_type} vs {$canonical2->canonical_type}",
            ];
        }

        return $conflicts;
    }
}
