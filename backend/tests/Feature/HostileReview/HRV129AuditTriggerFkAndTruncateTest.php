<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Hostile review repro (#129, raw PostgreSQL): the audit immutability trigger
 * must not block FK-initiated writes (deleting a referenced product/user) and
 * TRUNCATE must not bypass it.
 */
class HRV129AuditTriggerFkAndTruncateTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => (string) Str::uuid(),
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['product_audit_logs', 'products', 'users']);
    }

    public function test_deleting_a_referenced_product_is_not_blocked_by_the_audit_trigger(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        DB::table('product_audit_logs')->insert([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'action' => 'created',
            'created_at' => now(),
        ]);

        // FK is ON DELETE CASCADE — this write originates from the FK action.
        DB::table('products')->where('id', $product->id)->delete();

        $this->assertSame(0, DB::table('product_audit_logs')->where('product_id', $product->id)->count());
    }

    public function test_deleting_a_referenced_user_is_not_blocked_by_the_audit_trigger(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        DB::table('product_audit_logs')->insert([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'action' => 'created',
            'created_at' => now(),
        ]);

        // FK is ON DELETE SET NULL — must be permitted.
        DB::table('users')->where('id', $user->id)->delete();

        $this->assertNull(DB::table('product_audit_logs')->where('product_id', $product->id)->value('user_id'));
    }

    public function test_direct_modification_and_truncate_are_still_rejected(): void
    {
        User::factory()->create();
        $product = Product::factory()->create();
        DB::table('product_audit_logs')->insert([
            'product_id' => $product->id,
            'action' => 'created',
            'created_at' => now(),
        ]);

        $directDeleteRejected = false;
        try {
            DB::table('product_audit_logs')->where('product_id', $product->id)->delete();
        } catch (\Throwable $e) {
            $directDeleteRejected = true;
        }
        $this->assertTrue($directDeleteRejected, 'Direct DELETE must still be rejected');

        $truncateRejected = false;
        try {
            DB::statement('TRUNCATE TABLE product_audit_logs');
        } catch (\Throwable $e) {
            $truncateRejected = true;
        }
        $this->assertTrue($truncateRejected, 'TRUNCATE must not bypass the immutability guard');
    }
}
