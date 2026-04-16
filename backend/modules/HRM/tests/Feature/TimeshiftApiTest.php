<?php

namespace Modules\HRM\Tests\Feature;

use App\Models\User;
use Modules\HRM\Models\Timeshift;
use Modules\HRM\Tests\HrmTestCase;

class TimeshiftApiTest extends HrmTestCase
{

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    private function schedulePayload(): array
    {
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        return array_map(fn($day) => [
            'day'     => $day,
            'enabled' => in_array($day, ['monday','tuesday','wednesday','thursday','friday']),
            'start'   => '09:00',
            'end'     => '18:00',
        ], $days);
    }

    public function test_can_list_timeshifts(): void
    {
        Timeshift::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/hrm/timeshifts');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_can_create_timeshift(): void
    {
        $payload = [
            'name'       => 'Morning Shift',
            'is_default' => true,
            'schedule'   => $this->schedulePayload(),
        ];

        $response = $this->postJson('/api/v1/hrm/timeshifts', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Morning Shift');
        $response->assertJsonPath('data.is_default', true);
    }

    public function test_only_one_default_timeshift_allowed(): void
    {
        Timeshift::factory()->create(['is_default' => true]);

        $response = $this->postJson('/api/v1/hrm/timeshifts', [
            'name'       => 'Night Shift',
            'is_default' => true,
            'schedule'   => $this->schedulePayload(),
        ]);

        $response->assertStatus(201);
        $this->assertEquals(1, Timeshift::where('is_default', true)->count());
    }

    public function test_create_timeshift_requires_name_and_schedule(): void
    {
        $response = $this->postJson('/api/v1/hrm/timeshifts', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'schedule']);
    }

    public function test_can_update_timeshift(): void
    {
        $timeshift = Timeshift::factory()->create(['name' => 'Old Name']);

        $response = $this->putJson("/api/v1/hrm/timeshifts/{$timeshift->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'New Name');
    }

    public function test_can_delete_timeshift(): void
    {
        $timeshift = Timeshift::factory()->create();

        $response = $this->deleteJson("/api/v1/hrm/timeshifts/{$timeshift->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('hrm_timeshifts', ['id' => $timeshift->id]);
    }
}
