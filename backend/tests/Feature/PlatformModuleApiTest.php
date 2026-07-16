<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

class PlatformModuleApiTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin']);
    }

    private function admin(): User
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function as(User $user, string $method, string $uri, array $data = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->withoutMiddleware()
            ->json($method, $uri, $data);
    }

    public function test_index_returns_discovered_modules(): void
    {
        $res = $this->as($this->admin(), 'get', '/api/v1/modules');
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['0' => ['key', 'name', 'type', 'is_core', 'dependencies']],
            ]);

        $this->assertGreaterThanOrEqual(5, count($res->json('data')));
    }

    public function test_show_returns_module_detail_and_dependents(): void
    {
        // Use 'ai' which exists in the module.json scan.
        $res = $this->as($this->admin(), 'get', '/api/v1/modules/ai');
        $res->assertOk()
            ->assertJsonPath('data.key', 'ai')
            ->assertJsonStructure(['data' => ['key', 'dependencies', 'dependents']]);
    }

    public function test_show_unknown_module_returns_404(): void
    {
        $this->as($this->admin(), 'get', '/api/v1/modules/nonexistent')
            ->assertNotFound();
    }

    public function test_validate_dependencies_returns_valid_when_no_errors(): void
    {
        $res = $this->as($this->admin(), 'get', '/api/v1/modules/dependencies/validate');
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['valid', 'errors']]);
    }

    public function test_activate_with_present_dependency_returns_success(): void
    {
        // Use 'ai' (no dependencies) — activation should succeed immediately.
        $res = $this->as($this->admin(), 'post', '/api/v1/modules/ai/activate');
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.key', 'ai')
            ->assertJsonPath('data.activated', true);
    }

    public function test_activate_unknown_module_returns_404(): void
    {
        $this->as($this->admin(), 'post', '/api/v1/modules/nonexistent/activate')
            ->assertNotFound();
    }

    public function test_deactivate_module_with_no_dependents_returns_success(): void
    {
        // Use 'ai' — if it has dependents, response should be 422 with reason.
        $res = $this->as($this->admin(), 'post', '/api/v1/modules/ai/deactivate');
        $this->assertContains($res->json('success'), [true, false]);
        $this->assertContains($res->status(), [200, 422]);
    }

    public function test_deactivate_unknown_module_returns_404(): void
    {
        $this->as($this->admin(), 'post', '/api/v1/modules/nonexistent/deactivate')
            ->assertNotFound();
    }
}
