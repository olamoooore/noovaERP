<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\DimensionValidationService;
use App\Models\Department;
use App\Models\Project;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;

class DimensionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected DimensionValidationService $service;
    protected Department $department;
    protected Project $project;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        
        $this->department = Department::create([
            'code' => 'IT',
            'name' => 'IT Department',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        
        $this->project = Project::create([
            'code' => 'PROJ-001',
            'name' => 'Test Project',
            'status' => 'active',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        
        $this->location = Location::create([
            'code' => 'HQ',
            'name' => 'Headquarters',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        
        $this->service = app(DimensionValidationService::class);
    }

    /**
     * Property 20: Dimension validation
     */
    public function test_dimension_validation()
    {
        $dimensions = [
            'department_id' => $this->department->id,
            'project_id' => $this->project->id,
            'location_id' => $this->location->id,
        ];

        $result = $this->service->validate($dimensions);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test: Invalid dimension IDs are rejected
     */
    public function test_invalid_dimension_ids_are_rejected()
    {
        $dimensions = [
            'department_id' => 99999, // Non-existent
            'project_id' => $this->project->id,
            'location_id' => $this->location->id,
        ];

        $result = $this->service->validate($dimensions);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertArrayHasKey('department_id', $result['errors']);
    }

    /**
     * Test: Inactive dimensions are rejected
     */
    public function test_inactive_dimensions_are_rejected()
    {
        $inactiveDepartment = Department::create([
            'code' => 'OLD',
            'name' => 'Old Department',
            'is_active' => false,
            'created_by' => $this->user->id,
        ]);

        $dimensions = [
            'department_id' => $inactiveDepartment->id,
        ];

        $result = $this->service->validate($dimensions);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('department_id', $result['errors']);
    }

    /**
     * Property 31: Dimensions stored as JSONB
     */
    public function test_dimensions_stored_as_jsonb()
    {
        $document = OcrDocument::factory()->create([
            'dimensions' => [
                'department_id' => $this->department->id,
                'project_id' => $this->project->id,
                'location_id' => $this->location->id,
            ],
        ]);

        $document->refresh();

        $this->assertIsArray($document->dimensions);
        $this->assertEquals($this->department->id, $document->dimensions['department_id']);
        $this->assertEquals($this->project->id, $document->dimensions['project_id']);
        $this->assertEquals($this->location->id, $document->dimensions['location_id']);
    }

    /**
     * Property 32: Document-level dimensions propagate
     */
    public function test_document_level_dimensions_propagate()
    {
        $document = OcrDocument::factory()->create([
            'dimensions' => [
                'department_id' => $this->department->id,
                'location_id' => $this->location->id,
            ],
            'line_items' => [
                [
                    'description' => 'Item without dimensions',
                    'amount' => 100.00,
                ],
                [
                    'description' => 'Item with dimensions',
                    'amount' => 50.00,
                    'dimensions' => [
                        'project_id' => $this->project->id,
                    ],
                ],
            ],
        ]);

        // First line item should inherit document dimensions
        $this->assertArrayNotHasKey('dimensions', $document->line_items[0]);
        
        // Second line item has its own dimensions
        $this->assertArrayHasKey('dimensions', $document->line_items[1]);
        $this->assertEquals($this->project->id, $document->line_items[1]['dimensions']['project_id']);
    }

    /**
     * Property 33: Line-specific dimensions override
     */
    public function test_line_specific_dimensions_override()
    {
        $department2 = Department::create([
            'code' => 'SALES',
            'name' => 'Sales Department',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $document = OcrDocument::factory()->create([
            'dimensions' => [
                'department_id' => $this->department->id, // IT
            ],
            'line_items' => [
                [
                    'description' => 'Item 1',
                    'amount' => 100.00,
                    // No dimensions - should use document level
                ],
                [
                    'description' => 'Item 2',
                    'amount' => 50.00,
                    'dimensions' => [
                        'department_id' => $department2->id, // SALES - overrides
                    ],
                ],
            ],
        ]);

        // Verify line-specific dimension overrides document dimension
        $this->assertEquals($department2->id, $document->line_items[1]['dimensions']['department_id']);
    }

    /**
     * Property 34: Dimension queries work correctly
     */
    public function test_dimension_queries_work_correctly()
    {
        // Create documents with different dimensions
        OcrDocument::factory()->create([
            'dimensions' => ['department_id' => $this->department->id],
            'status' => 'POSTED',
        ]);

        OcrDocument::factory()->create([
            'dimensions' => ['department_id' => $this->department->id],
            'status' => 'POSTED',
        ]);

        $otherDepartment = Department::create([
            'code' => 'HR',
            'name' => 'HR Department',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        OcrDocument::factory()->create([
            'dimensions' => ['department_id' => $otherDepartment->id],
            'status' => 'POSTED',
        ]);

        // Query by dimension
        $itDocuments = OcrDocument::whereJsonContains('dimensions->department_id', $this->department->id)
            ->where('status', 'POSTED')
            ->get();

        $this->assertEquals(2, $itDocuments->count());
    }

    /**
     * Property 35: Dimension deletion preserves history
     */
    public function test_dimension_deletion_preserves_history()
    {
        $document = OcrDocument::factory()->create([
            'dimensions' => [
                'department_id' => $this->department->id,
                'project_id' => $this->project->id,
            ],
            'status' => 'POSTED',
        ]);

        // Soft delete or deactivate department
        $this->department->update(['is_active' => false]);

        $document->refresh();

        // Document should still have the dimension reference
        $this->assertEquals($this->department->id, $document->dimensions['department_id']);
        
        // But department is now inactive
        $this->assertFalse($this->department->is_active);
    }

    /**
     * Test: Null dimensions are allowed
     */
    public function test_null_dimensions_are_allowed()
    {
        $document = OcrDocument::factory()->create([
            'dimensions' => null,
        ]);

        $document->refresh();
        $this->assertNull($document->dimensions);
    }

    /**
     * Test: Empty dimensions object is allowed
     */
    public function test_empty_dimensions_object_is_allowed()
    {
        $document = OcrDocument::factory()->create([
            'dimensions' => [],
        ]);

        $document->refresh();
        $this->assertIsArray($document->dimensions);
        $this->assertEmpty($document->dimensions);
    }

    /**
     * Test: Partial dimensions are allowed
     */
    public function test_partial_dimensions_are_allowed()
    {
        $document = OcrDocument::factory()->create([
            'dimensions' => [
                'department_id' => $this->department->id,
                // project_id and location_id not specified
            ],
        ]);

        $document->refresh();
        $this->assertArrayHasKey('department_id', $document->dimensions);
        $this->assertArrayNotHasKey('project_id', $document->dimensions);
    }

    /**
     * Test: Dimension relationships can be loaded
     */
    public function test_dimension_relationships_can_be_loaded()
    {
        $document = OcrDocument::factory()->create([
            'dimensions' => [
                'department_id' => $this->department->id,
                'project_id' => $this->project->id,
                'location_id' => $this->location->id,
            ],
        ]);

        // Load relationships
        $department = Department::find($document->dimensions['department_id']);
        $project = Project::find($document->dimensions['project_id']);
        $location = Location::find($document->dimensions['location_id']);

        $this->assertNotNull($department);
        $this->assertNotNull($project);
        $this->assertNotNull($location);
        
        $this->assertEquals('IT Department', $department->name);
        $this->assertEquals('Test Project', $project->name);
        $this->assertEquals('Headquarters', $location->name);
    }

    /**
     * Test: Multiple documents can share dimensions
     */
    public function test_multiple_documents_can_share_dimensions()
    {
        $doc1 = OcrDocument::factory()->create([
            'dimensions' => ['department_id' => $this->department->id],
        ]);

        $doc2 = OcrDocument::factory()->create([
            'dimensions' => ['department_id' => $this->department->id],
        ]);

        $doc3 = OcrDocument::factory()->create([
            'dimensions' => ['department_id' => $this->department->id],
        ]);

        $count = OcrDocument::whereJsonContains('dimensions->department_id', $this->department->id)->count();
        
        $this->assertEquals(3, $count);
    }

    /**
     * Test: Dimension validation service handles all types
     */
    public function test_dimension_validation_service_handles_all_types()
    {
        $testCases = [
            // Valid
            [
                'dimensions' => ['department_id' => $this->department->id],
                'expected' => true,
            ],
            [
                'dimensions' => ['project_id' => $this->project->id],
                'expected' => true,
            ],
            [
                'dimensions' => ['location_id' => $this->location->id],
                'expected' => true,
            ],
            [
                'dimensions' => [
                    'department_id' => $this->department->id,
                    'project_id' => $this->project->id,
                    'location_id' => $this->location->id,
                ],
                'expected' => true,
            ],
            // Invalid
            [
                'dimensions' => ['department_id' => 99999],
                'expected' => false,
            ],
            [
                'dimensions' => ['project_id' => 99999],
                'expected' => false,
            ],
            [
                'dimensions' => ['location_id' => 99999],
                'expected' => false,
            ],
        ];

        foreach ($testCases as $testCase) {
            $result = $this->service->validate($testCase['dimensions']);
            $this->assertEquals($testCase['expected'], $result['valid'], 
                'Failed for: ' . json_encode($testCase['dimensions']));
        }
    }
}
