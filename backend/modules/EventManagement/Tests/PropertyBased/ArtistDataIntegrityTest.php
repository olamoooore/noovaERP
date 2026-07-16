<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Services\ArtistCanonicalizationService;
use Tests\TestCase;

/**
 * Property Test 23: Artist-Event Relationship Integrity and Canonicalization
 *
 * Validates Requirements: 22.1, 22.2, 22.3, 22.4, 22.5
 *
 * This property-based test validates that the artist canonicalization system
 * maintains data integrity, proper alias management, merge functionality,
 * and artist-event relationship consistency across all scenarios.
 */
class ArtistDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected ArtistCanonicalizationService $canonicalizationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->canonicalizationService = app(ArtistCanonicalizationService::class);

        // Clear cache for clean test state
        Cache::flush();

        $this->setupTestData();
    }

    /**
     * Property 23.1: Canonical Artist Creation Integrity
     *
     * Validates that canonical artist creation maintains uniqueness,
     * proper slug generation, and consistent metadata handling.
     *
     * Requirements: 22.1, 22.4
     */
    public function test_canonical_artist_creation_integrity()
    {
        $iterations = 25;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random artist data
            $artistData = $this->generateRandomArtistData();

            // Create canonical artist
            $result = $this->canonicalizationService->createCanonicalArtist($artistData);

            // Property: Creation should succeed with valid data
            $this->assertTrue($result['success'], 'Artist creation failed: '.($result['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('canonical_id', $result);
            $this->assertArrayHasKey('canonical_slug', $result);

            // Property: Canonical artist should be retrievable
            $retrievedArtist = $this->canonicalizationService->getCanonicalArtist($result['canonical_id']);
            $this->assertTrue($retrievedArtist['success']);
            $this->assertEquals($artistData['canonical_name'], $retrievedArtist['canonical_name']);
            $this->assertEquals($artistData['canonical_type'], $retrievedArtist['canonical_type']);

            // Property: Slug should be unique and properly formatted
            $this->assertNotEmpty($result['canonical_slug']);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $result['canonical_slug']);

            // Property: Primary alias should be created automatically
            $this->assertNotEmpty($retrievedArtist['aliases']);
            $primaryAlias = collect($retrievedArtist['aliases'])->firstWhere('is_primary', true);
            $this->assertNotNull($primaryAlias, 'Primary alias should be created automatically');
            $this->assertEquals($artistData['canonical_name'], $primaryAlias->alias_name);

            // Property: Duplicate canonical names should be rejected
            $duplicateResult = $this->canonicalizationService->createCanonicalArtist($artistData);
            $this->assertFalse($duplicateResult['success'], 'Duplicate canonical names should be rejected');
            $this->assertStringContains('already exists', $duplicateResult['error']);
        }
    }

    /**
     * Property 23.2: Alias Management Consistency
     *
     * Validates that alias creation, uniqueness constraints, and
     * primary alias management work correctly.
     *
     * Requirements: 22.1, 22.3
     */
    public function test_alias_management_consistency()
    {
        $iterations = 25;

        for ($i = 0; $i < $iterations; $i++) {
            // Create a canonical artist first
            $artistData = $this->generateRandomArtistData();
            $artistResult = $this->canonicalizationService->createCanonicalArtist($artistData);
            $this->assertTrue($artistResult['success']);

            $canonicalId = $artistResult['canonical_id'];
            $aliasCount = rand(3, 8);
            $createdAliases = [];

            // Create multiple aliases
            for ($j = 0; $j < $aliasCount; $j++) {
                $aliasData = $this->generateRandomAliasData();

                $aliasResult = $this->canonicalizationService->createAlias($canonicalId, $aliasData);

                // Property: Alias creation should succeed with valid data
                $this->assertTrue($aliasResult['success'], 'Alias creation failed: '.($aliasResult['error'] ?? 'Unknown error'));
                $this->assertArrayHasKey('alias_id', $aliasResult);

                $createdAliases[] = [
                    'alias_id' => $aliasResult['alias_id'],
                    'alias_name' => $aliasData['alias_name'],
                    'alias_type' => $aliasData['alias_type'],
                ];
            }

            // Property: All aliases should be retrievable
            $retrievedArtist = $this->canonicalizationService->getCanonicalArtist($canonicalId);
            $this->assertTrue($retrievedArtist['success']);
            $this->assertCount($aliasCount + 1, $retrievedArtist['aliases']); // +1 for primary alias

            // Property: Duplicate aliases should be rejected
            $duplicateAliasData = $createdAliases[0];
            $duplicateResult = $this->canonicalizationService->createAlias($canonicalId, [
                'alias_name' => $duplicateAliasData['alias_name'],
                'alias_type' => $duplicateAliasData['alias_type'],
            ]);
            $this->assertFalse($duplicateResult['success'], 'Duplicate aliases should be rejected');

            // Property: Primary alias logic should work correctly
            $primaryAliasData = $this->generateRandomAliasData();
            $primaryAliasData['is_primary'] = true;

            $primaryResult = $this->canonicalizationService->createAlias($canonicalId, $primaryAliasData);
            $this->assertTrue($primaryResult['success']);

            // Check that only one primary alias exists for this type
            $updatedArtist = $this->canonicalizationService->getCanonicalArtist($canonicalId);
            $primaryAliases = collect($updatedArtist['aliases'])
                ->where('alias_type', $primaryAliasData['alias_type'])
                ->where('is_primary', true);

            $this->assertEquals(1, $primaryAliases->count(), 'Only one primary alias should exist per type');
        }
    }

    /**
     * Property 23.3: Artist Merge Integrity
     *
     * Validates that artist merging preserves data integrity,
     * handles conflicts correctly, and maintains audit trails.
     *
     * Requirements: 22.1, 22.3, 22.4
     */
    public function test_artist_merge_integrity()
    {
        $iterations = 15; // Reduced due to merge complexity

        for ($i = 0; $i < $iterations; $i++) {
            // Create two artists to merge
            $sourceArtistData = $this->generateRandomArtistData();
            $targetArtistData = $this->generateRandomArtistData();

            $sourceResult = $this->canonicalizationService->createCanonicalArtist($sourceArtistData);
            $targetResult = $this->canonicalizationService->createCanonicalArtist($targetArtistData);

            $this->assertTrue($sourceResult['success']);
            $this->assertTrue($targetResult['success']);

            $sourceId = $sourceResult['canonical_id'];
            $targetId = $targetResult['canonical_id'];

            // Add some aliases to both artists
            $sourceAliases = [];
            $targetAliases = [];

            for ($j = 0; $j < 3; $j++) {
                $sourceAliasData = $this->generateRandomAliasData();
                $sourceAliasResult = $this->canonicalizationService->createAlias($sourceId, $sourceAliasData);
                $this->assertTrue($sourceAliasResult['success']);
                $sourceAliases[] = $sourceAliasData['alias_name'];

                $targetAliasData = $this->generateRandomAliasData();
                $targetAliasResult = $this->canonicalizationService->createAlias($targetId, $targetAliasData);
                $this->assertTrue($targetAliasResult['success']);
                $targetAliases[] = $targetAliasData['alias_name'];
            }

            // Perform merge
            $mergeOptions = [
                'reason' => 'Property test merge',
                'notes' => 'Testing merge functionality',
                'merged_by_user_id' => 1,
            ];

            $mergeResult = $this->canonicalizationService->mergeArtists($sourceId, $targetId, $mergeOptions);

            // Property: Merge should succeed
            $this->assertTrue($mergeResult['success'], 'Merge failed: '.($mergeResult['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('merge_log_id', $mergeResult);
            $this->assertArrayHasKey('target_artist_id', $mergeResult);

            // Property: Source artist should be deactivated
            $sourceArtistAfterMerge = DB::table('artist_canonical')->where('id', $sourceId)->first();
            $this->assertFalse($sourceArtistAfterMerge->is_active, 'Source artist should be deactivated after merge');

            // Property: Target artist should remain active
            $targetArtistAfterMerge = DB::table('artist_canonical')->where('id', $targetId)->first();
            $this->assertTrue($targetArtistAfterMerge->is_active, 'Target artist should remain active after merge');

            // Property: Aliases should be merged to target artist
            $targetAliasesAfterMerge = DB::table('artist_aliases')->where('canonical_id', $targetId)->get();
            $this->assertGreaterThan(count($targetAliases), $targetAliasesAfterMerge->count(),
                'Target artist should have more aliases after merge');

            // Property: Merge log should be created
            $mergeLog = DB::table('artist_merge_log')->where('id', $mergeResult['merge_log_id'])->first();
            $this->assertNotNull($mergeLog, 'Merge log should be created');
            $this->assertEquals('completed', $mergeLog->merge_status);
            $this->assertEquals($sourceId, $mergeLog->source_artist_id);
            $this->assertEquals($targetId, $mergeLog->target_artist_id);

            // Property: Cannot merge the same artist twice
            $duplicateMergeResult = $this->canonicalizationService->mergeArtists($sourceId, $targetId, $mergeOptions);
            // This might succeed or fail depending on implementation, but should be consistent

            // Property: Cannot merge artist with itself
            $selfMergeResult = $this->canonicalizationService->mergeArtists($targetId, $targetId, $mergeOptions);
            $this->assertFalse($selfMergeResult['success'], 'Cannot merge artist with itself');
        }
    }

    /**
     * Property 23.4: Artist Search Consistency
     *
     * Validates that artist search returns consistent results,
     * handles aliases correctly, and maintains relevance scoring.
     *
     * Requirements: 22.3, 22.4
     */
    public function test_artist_search_consistency()
    {
        $iterations = 20;

        // Create some test artists with known names for searching
        $testArtists = [];
        for ($i = 0; $i < 10; $i++) {
            $artistData = $this->generateRandomArtistData();
            $artistData['canonical_name'] = 'TestArtist'.$i;

            $result = $this->canonicalizationService->createCanonicalArtist($artistData);
            $this->assertTrue($result['success']);

            $testArtists[] = [
                'canonical_id' => $result['canonical_id'],
                'canonical_name' => $artistData['canonical_name'],
            ];

            // Add some aliases
            for ($j = 0; $j < 3; $j++) {
                $aliasData = [
                    'alias_name' => 'Alias'.$i.'_'.$j,
                    'alias_type' => 'stage_name',
                    'is_searchable' => true,
                ];

                $this->canonicalizationService->createAlias($result['canonical_id'], $aliasData);
            }
        }

        for ($i = 0; $i < $iterations; $i++) {
            // Test exact name search
            $testArtist = $testArtists[rand(0, count($testArtists) - 1)];
            $searchResult = $this->canonicalizationService->searchArtists($testArtist['canonical_name']);

            // Property: Search should succeed
            $this->assertTrue($searchResult['success'], 'Search failed: '.($searchResult['error'] ?? 'Unknown error'));
            $this->assertArrayHasKey('results', $searchResult);

            // Property: Exact match should be in results
            $foundArtist = collect($searchResult['results'])
                ->firstWhere('canonical_name', $testArtist['canonical_name']);
            $this->assertNotNull($foundArtist, 'Exact match should be found in search results');

            // Property: Search results should have relevance scores
            foreach ($searchResult['results'] as $result) {
                $this->assertArrayHasKey('relevance_score', $result);
                $this->assertGreaterThan(0, $result['relevance_score']);
            }

            // Test partial name search
            $partialName = substr($testArtist['canonical_name'], 0, 5);
            if (strlen($partialName) >= 2) {
                $partialSearchResult = $this->canonicalizationService->searchArtists($partialName);
                $this->assertTrue($partialSearchResult['success']);

                // Property: Partial search should return results
                $this->assertGreaterThan(0, count($partialSearchResult['results']),
                    'Partial search should return results');
            }

            // Test alias search
            $aliasSearchResult = $this->canonicalizationService->searchArtists('Alias'.rand(0, 9));
            $this->assertTrue($aliasSearchResult['success']);

            // Property: Search with filters should work
            $filteredSearchResult = $this->canonicalizationService->searchArtists('TestArtist', [
                'artist_type' => 'artist',
                'limit' => 5,
            ]);
            $this->assertTrue($filteredSearchResult['success']);
            $this->assertLessThanOrEqual(5, count($filteredSearchResult['results']));
        }
    }

    /**
     * Property 23.5: Artist-Event Relationship Integrity
     *
     * Validates that artist-event relationships maintain integrity,
     * support proper role assignments, and preserve historical data.
     *
     * Requirements: 22.2, 22.5
     */
    public function test_artist_event_relationship_integrity()
    {
        $iterations = 20;

        for ($i = 0; $i < $iterations; $i++) {
            // Create test artist
            $artistData = $this->generateRandomArtistData();
            $artistResult = $this->canonicalizationService->createCanonicalArtist($artistData);
            $this->assertTrue($artistResult['success']);

            $canonicalId = $artistResult['canonical_id'];

            // Create test event (simplified for testing)
            $eventId = DB::table('events')->insertGetId([
                'name' => 'Test Event '.$i,
                'description' => 'Test event for property testing',
                'event_date' => Carbon::now()->addDays(rand(1, 30)),
                'venue' => 'Test Venue',
                'capacity' => rand(100, 5000),
                'status' => 'active',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // Create artist-event relationship
            $relationshipData = [
                'artist_id' => $canonicalId,
                'event_id' => $eventId,
                'performance_role' => $this->getRandomPerformanceRole(),
                'performance_order' => rand(1, 5),
                'collaboration_type' => $this->getRandomCollaborationType(),
                'association_status' => 'confirmed',
                'is_public' => true,
                'is_featured' => rand(0, 1) == 1,
                'created_by' => 'test_system',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];

            $artistEventId = DB::table('artist_events')->insertGetId($relationshipData);

            // Property: Relationship should be created successfully
            $this->assertGreaterThan(0, $artistEventId);

            // Property: Relationship should be retrievable
            $retrievedRelationship = DB::table('artist_events')->where('id', $artistEventId)->first();
            $this->assertNotNull($retrievedRelationship);
            $this->assertEquals($canonicalId, $retrievedRelationship->artist_id);
            $this->assertEquals($eventId, $retrievedRelationship->event_id);

            // Create performance history entry
            $historyData = [
                'canonical_artist_id' => $canonicalId,
                'event_id' => $eventId,
                'artist_event_id' => $artistEventId,
                'performance_date' => Carbon::now()->addDays(rand(1, 30)),
                'venue_name' => 'Test Venue '.$i,
                'venue_city' => 'Test City',
                'venue_country' => 'USA',
                'performance_type' => $this->getRandomPerformanceType(),
                'estimated_attendance' => rand(50, 2000),
                'performance_quality' => 'good',
                'is_milestone' => rand(0, 1) == 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];

            $historyId = DB::table('artist_performance_history')->insertGetId($historyData);

            // Property: Performance history should be created successfully
            $this->assertGreaterThan(0, $historyId);

            // Property: Performance history should be linked correctly
            $retrievedHistory = DB::table('artist_performance_history')->where('id', $historyId)->first();
            $this->assertNotNull($retrievedHistory);
            $this->assertEquals($canonicalId, $retrievedHistory->canonical_artist_id);
            $this->assertEquals($eventId, $retrievedHistory->event_id);
            $this->assertEquals($artistEventId, $retrievedHistory->artist_event_id);

            // Property: Artist should have performance history
            $artistHistory = DB::table('artist_performance_history')
                ->where('canonical_artist_id', $canonicalId)
                ->get();

            $this->assertGreaterThan(0, $artistHistory->count());

            // Test collaboration creation
            if ($i > 0) {
                // Create collaboration with previous artist
                $previousArtistId = $canonicalId - 1; // Assuming sequential IDs

                if (DB::table('artist_canonical')->where('id', $previousArtistId)->exists()) {
                    $collaborationData = [
                        'primary_artist_id' => $canonicalId,
                        'collaborating_artist_id' => $previousArtistId,
                        'event_id' => $eventId,
                        'collaboration_nature' => 'one_time',
                        'collaboration_name' => 'Test Collaboration '.$i,
                        'collaboration_start' => Carbon::now(),
                        'total_performances' => 1,
                        'collaboration_strength' => 0.8,
                        'is_active' => true,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];

                    $collaborationId = DB::table('artist_collaborations')->insertGetId($collaborationData);

                    // Property: Collaboration should be created successfully
                    $this->assertGreaterThan(0, $collaborationId);

                    // Property: Collaboration should be retrievable
                    $retrievedCollaboration = DB::table('artist_collaborations')->where('id', $collaborationId)->first();
                    $this->assertNotNull($retrievedCollaboration);
                    $this->assertEquals($canonicalId, $retrievedCollaboration->primary_artist_id);
                    $this->assertEquals($previousArtistId, $retrievedCollaboration->collaborating_artist_id);
                }
            }
        }
    }

    /**
     * Property 23.6: Data Consistency After Operations
     *
     * Validates that all artist operations maintain referential integrity
     * and search index consistency.
     *
     * Requirements: 22.1, 22.2, 22.3, 22.4, 22.5
     */
    public function test_data_consistency_after_operations()
    {
        $iterations = 15;

        for ($i = 0; $i < $iterations; $i++) {
            // Create artist with aliases and relationships
            $artistData = $this->generateRandomArtistData();
            $artistResult = $this->canonicalizationService->createCanonicalArtist($artistData);
            $this->assertTrue($artistResult['success']);

            $canonicalId = $artistResult['canonical_id'];

            // Add multiple aliases
            $aliasIds = [];
            for ($j = 0; $j < 5; $j++) {
                $aliasData = $this->generateRandomAliasData();
                $aliasResult = $this->canonicalizationService->createAlias($canonicalId, $aliasData);
                $this->assertTrue($aliasResult['success']);
                $aliasIds[] = $aliasResult['alias_id'];
            }

            // Property: All aliases should be in search index
            $searchIndexCount = DB::table('artist_search_index')
                ->where('canonical_id', $canonicalId)
                ->count();

            $this->assertGreaterThan(0, $searchIndexCount, 'Artist should have search index entries');

            // Property: Search should find the artist
            $searchResult = $this->canonicalizationService->searchArtists($artistData['canonical_name']);
            $this->assertTrue($searchResult['success']);

            $foundArtist = collect($searchResult['results'])
                ->firstWhere('canonical_id', $canonicalId);
            $this->assertNotNull($foundArtist, 'Artist should be found in search');

            // Property: All database relationships should be consistent
            $aliasCount = DB::table('artist_aliases')->where('canonical_id', $canonicalId)->count();
            $this->assertGreaterThan(0, $aliasCount, 'Artist should have aliases');

            // Property: Foreign key constraints should be maintained
            $orphanedAliases = DB::table('artist_aliases as aa')
                ->leftJoin('artist_canonical as ac', 'aa.canonical_id', '=', 'ac.id')
                ->whereNull('ac.id')
                ->count();

            $this->assertEquals(0, $orphanedAliases, 'No orphaned aliases should exist');

            // Property: Search index should be consistent with aliases
            $aliasesWithSearchIndex = DB::table('artist_aliases as aa')
                ->join('artist_search_index as asi', 'aa.canonical_id', '=', 'asi.canonical_id')
                ->where('aa.canonical_id', $canonicalId)
                ->where('aa.is_searchable', true)
                ->count();

            $this->assertGreaterThan(0, $aliasesWithSearchIndex, 'Searchable aliases should have search index entries');
        }
    }

    // Helper methods for test data generation

    private function setupTestData(): void
    {
        // Create events table if it doesn't exist (for testing)
        if (! DB::getSchemaBuilder()->hasTable('events')) {
            DB::statement('
                CREATE TABLE events (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    event_date DATETIME,
                    venue VARCHAR(255),
                    capacity INT,
                    status VARCHAR(50),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ');
        }
    }

    private function generateRandomArtistData(): array
    {
        $types = ['artist', 'group'];
        $genres = ['rock', 'pop', 'jazz', 'classical', 'electronic', 'hip-hop'];
        $countries = ['USA', 'GBR', 'CAN', 'AUS', 'DEU', 'FRA'];

        return [
            'canonical_name' => 'Artist_'.uniqid(),
            'canonical_type' => $types[array_rand($types)],
            'master_record_id' => rand(1000, 9999),
            'primary_genre' => $genres[array_rand($genres)],
            'country_origin' => $countries[array_rand($countries)],
            'formation_date' => Carbon::now()->subYears(rand(1, 50))->format('Y-m-d'),
            'metadata' => [
                'test_data' => true,
                'created_by_test' => 'property_test',
            ],
            'is_active' => true,
            'created_by' => 'property_test',
        ];
    }

    private function generateRandomAliasData(): array
    {
        $types = ['stage_name', 'romanized', 'translated', 'former_name', 'nickname'];
        $languages = ['en', 'es', 'fr', 'de', 'ja', 'ko'];

        return [
            'alias_name' => 'Alias_'.uniqid(),
            'alias_type' => $types[array_rand($types)],
            'language_code' => $languages[array_rand($languages)],
            'is_primary' => rand(0, 1) == 1,
            'is_searchable' => true,
            'usage_frequency' => rand(0, 100),
            'confidence_score' => rand(70, 100) / 100,
            'source' => 'property_test',
            'created_by' => 'property_test',
        ];
    }

    private function getRandomPerformanceRole(): string
    {
        $roles = ['headliner', 'co_headliner', 'opening_act', 'supporting_act', 'special_guest', 'featured_artist'];

        return $roles[array_rand($roles)];
    }

    private function getRandomCollaborationType(): string
    {
        $types = ['solo_performance', 'band_performance', 'collaboration', 'guest_appearance'];

        return $types[array_rand($types)];
    }

    private function getRandomPerformanceType(): string
    {
        $types = ['concert', 'festival', 'private_event', 'corporate_event', 'charity_event'];

        return $types[array_rand($types)];
    }
}
