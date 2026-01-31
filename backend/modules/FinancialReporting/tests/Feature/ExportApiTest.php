<?php

namespace Modules\FinancialReporting\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class ExportApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    /** @test */
    public function it_can_export_report_to_csv()
    {
        $reportData = [
            'title' => 'Test Report',
            'data' => [
                ['name' => 'Item 1', 'value' => 100],
                ['name' => 'Item 2', 'value' => 200],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/reports/export/csv', [
                'report_type' => 'profit-loss',
                'report_data' => $reportData,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'file_path',
                'download_url',
            ]);
    }

    /** @test */
    public function it_can_export_report_to_excel()
    {
        $reportData = [
            'title' => 'Test Report',
            'data' => [
                ['name' => 'Item 1', 'value' => 100],
                ['name' => 'Item 2', 'value' => 200],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/reports/export/excel', [
                'report_type' => 'profit-loss',
                'report_data' => $reportData,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'file_path',
                'download_url',
            ]);
    }

    /** @test */
    public function it_requires_authentication_for_export()
    {
        $response = $this->postJson('/api/reports/export/csv', [
            'report_type' => 'profit-loss',
            'report_data' => [],
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function it_validates_export_request()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/reports/export/csv', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['report_type', 'report_data']);
    }

    /** @test */
    public function it_handles_invalid_report_type()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/reports/export/csv', [
                'report_type' => 'invalid-type',
                'report_data' => ['data' => []],
            ]);

        $response->assertStatus(422);
    }
}
