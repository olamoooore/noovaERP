<?php

namespace Modules\FinancialReporting\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_get_sales_by_product()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/analytics/sales/by-product?' . http_build_query([
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
    public function it_can_get_sales_by_customer()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/analytics/sales/by-customer?' . http_build_query([
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
    public function it_can_get_sales_trends()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/analytics/sales/trends?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
                'interval' => 'day',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'interval',
                'period',
                'data',
                'summary',
            ]);
    }

    /** @test */
    public function it_can_get_sales_by_category()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/analytics/sales/by-category?' . http_build_query([
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
    public function it_can_get_expenses_by_category()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/analytics/expenses/by-category?' . http_build_query([
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
    public function it_can_get_expenses_by_vendor()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/analytics/expenses/by-vendor?' . http_build_query([
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'data',
            ]);
    }

    /** @test */
    public function it_can_get_customer_lifetime_value()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/analytics/customers/lifetime-value?' . http_build_query([
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
    public function it_requires_authentication_for_analytics()
    {
        $response = $this->getJson('/api/analytics/sales/by-product');

        $response->assertStatus(401);
    }

    /** @test */
    public function it_supports_different_trend_intervals()
    {
        $intervals = ['day', 'week', 'month', 'year'];

        foreach ($intervals as $interval) {
            $response = $this->actingAs($this->user)
                ->getJson('/api/analytics/sales/trends?' . http_build_query([
                    'start_date' => '2024-01-01',
                    'end_date' => '2024-12-31',
                    'interval' => $interval,
                ]));

            $response->assertStatus(200)
                ->assertJson(['interval' => $interval]);
        }
    }
}
