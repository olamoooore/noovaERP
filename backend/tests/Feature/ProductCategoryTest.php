<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #133 — ProductCategory.parent_id has no cycle guard, and (adjacent
 * finding) the create/update/delete routes were never registered so the
 * Category Manager UI 404'd. Covers:
 *   - the missing CRUD routes exist and are permission-gated
 *   - a category cannot be its own parent
 *   - a category cannot be re-parented under one of its own descendants
 *   - deleting a category with children/products is blocked
 */
class ProductCategoryTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '55555555-4444-3333-2222-111111111111',
        ]));
        $this->bindTenantContext($tenant->id);

        $this->user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function category(string $name, ?int $parentId = null): ProductCategory
    {
        return ProductCategory::create([
            'name' => $name,
            'parent_id' => $parentId,
            'is_active' => true,
        ]);
    }

    public function test_category_crud_routes_are_registered(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/product-categories', ['name' => 'Wine', 'is_active' => true]);

        $response->assertCreated();
        $this->assertDatabaseHas('product_categories', ['name' => 'Wine']);

        $id = $response->json('id');

        $this->actingAs($this->user)
            ->putJson('/api/v1/product-categories/'.$id, ['name' => 'Wine & Spirits'])
            ->assertOk();

        $this->actingAs($this->user)
            ->delete('/api/v1/product-categories/'.$id)
            ->assertOk();
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $category = $this->category('Root');

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/product-categories/'.$category->id, ['parent_id' => $category->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('own parent', $response->json('message'));
        $this->assertNull($category->fresh()->parent_id);
    }

    public function test_category_cannot_be_reparented_under_its_own_descendant(): void
    {
        $a = $this->category('A');
        $b = $this->category('B', $a->id);
        $c = $this->category('C', $b->id);

        // A → C would create the cycle A→B→C→A.
        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/product-categories/'.$a->id, ['parent_id' => $c->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('descendant', $response->json('message'));
        $this->assertNull($a->fresh()->parent_id);

        // A valid move (C promoted to root) still works.
        $this->actingAs($this->user)
            ->putJson('/api/v1/product-categories/'.$c->id, ['parent_id' => null])
            ->assertOk();
        $this->assertNull($c->fresh()->parent_id);
    }

    public function test_category_with_children_or_products_cannot_be_deleted(): void
    {
        $parent = $this->category('Parent');
        $child = $this->category('Child', $parent->id);

        $this->actingAs($this->user)
            ->delete('/api/v1/product-categories/'.$parent->id)
            ->assertStatus(422);

        $product = Product::factory()->create(['category_id' => $child->id]);
        $this->actingAs($this->user)
            ->delete('/api/v1/product-categories/'.$child->id)
            ->assertStatus(422);

        // Leaf with nothing attached deletes cleanly.
        $leaf = $this->category('Leaf');
        $this->actingAs($this->user)
            ->delete('/api/v1/product-categories/'.$leaf->id)
            ->assertOk();
    }
}
