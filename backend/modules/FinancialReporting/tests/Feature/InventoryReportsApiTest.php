<?php

namespace Modules\FinancialReporting\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

class InventoryReportsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_generate_stock_valuation_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/inventory/valuation?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data',
                'summary',
            ]);
    }

    /** @test */
    public function it_can_generate_stock_movement_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/inventory/movement?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'period',
                'data',
                'summary',
            ]);
    }

    /** @test */
    public function it_can_generate_reorder_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/inventory/reorder');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data',
                'summary',
            ]);
    }

    /** @test */
    public function it_can_generate_inventory_aging_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/inventory/aging?' . http_build_query([
                'as_of_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data',
                'summary',
            ]);
    }

    /** @test */
    public function it_can_generate_inventory_turnover_report()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/inventory/turnover?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'period',
                'data',
                'summary',
            ]);
    }

    /** @test */
    public function it_requires_authentication_for_inventory_reports()
    {
        $response = $this->getJson('/api/reports/inventory/valuation');

        $response->assertStatus(401);
    }
}
