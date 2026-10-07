<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChatterModelRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #633 — Decision (7 Oct 2026, product owner): Option B, keep and fix.
 * The Communication engine's ChatterModelRegistry pointed `order`, `invoice`
 * and `contact` at classes that do not exist, so those aliases could never
 * resolve a record through /api/communication. The registry must expose only
 * aliases backed by a real Eloquent model, drawn from the single subject list
 * in config/chatter.php.
 */
class Chatter633RegistryAliasesTest extends TenantTestCase
{
    public function test_every_exposed_alias_resolves_to_an_existing_eloquent_model(): void
    {
        $registry = app(ChatterModelRegistry::class);

        $aliases = $registry->allowedAliases();
        $this->assertNotEmpty($aliases);

        foreach ($aliases as $alias) {
            $class = $registry->resolveClass($alias);
            $this->assertNotNull($class, "Alias '{$alias}' must resolve to a class");
            $this->assertTrue(class_exists($class), "Alias '{$alias}' points at missing class {$class}");
            $this->assertTrue(is_subclass_of($class, Model::class), "Alias '{$alias}' class {$class} must be an Eloquent model");
        }
    }

    public function test_registry_is_derived_from_the_config_subject_list(): void
    {
        $registry = app(ChatterModelRegistry::class);
        $allowed = (array) config('chatter.allowed_models', []);
        $permissions = (array) config('chatter.subject_permissions', []);

        foreach ($registry->allowedAliases() as $alias) {
            $class = $registry->resolveClass($alias);
            $this->assertContains($class, $allowed, "Alias '{$alias}' ({$class}) is not in config chatter.allowed_models");
            // Exact-class permission mapping: no basename fallback needed.
            $this->assertArrayHasKey($class, $permissions, "Alias '{$alias}' ({$class}) has no exact chatter.subject_permissions entry");
        }

        // Every configured subject is reachable through exactly one alias.
        $resolved = array_map(fn ($a) => $registry->resolveClass($a), $registry->allowedAliases());
        $this->assertEqualsCanonicalizing($allowed, array_values(array_unique($resolved)));
    }

    public function test_repaired_aliases_point_at_the_decided_classes(): void
    {
        $registry = app(ChatterModelRegistry::class);

        $this->assertSame(\Modules\Sales\Models\SalesOrder::class, $registry->resolveClass('order'));
        $this->assertSame(Invoice::class, $registry->resolveClass('invoice'));
        // No real Contact model exists and it is not a configured subject.
        $this->assertNull($registry->resolveClass('contact'));
        $this->assertNotContains('contact', $registry->allowedAliases());
    }

    public function test_invoice_alias_resolves_a_real_invoice_through_the_communication_route(): void
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $tenantId = (string) $tenant->id;
        $this->bindTenantContext($tenantId);
        $this->withoutMiddleware([\App\Http\Middleware\EnsureEntitlementAccess::class]);
        $this->seed(RolePermissionSeeder::class);

        $customer = Customer::factory()->create(['legal_entity_id' => null]);
        $invoice = Invoice::factory()->create(['customer_id' => $customer->id]);

        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        DB::table('user_company_access')->insert([
            'user_id' => $viewer->id,
            'tenant_id' => $tenantId,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($viewer)
            ->getJson("/api/v1/api/communication/messages?model=invoice&record_id={$invoice->id}");

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'invoice alias must resolve the record — got '.$response->getStatusCode().' body='.$response->getContent()
        );
    }
}
