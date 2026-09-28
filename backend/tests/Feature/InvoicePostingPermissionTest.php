<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Posting a customer invoice to the GL is an accounting action.
 *
 * Before this change the sales role could post through two doors:
 *   1. the invoice detail "Post Invoice" button called the legacy
 *      POST /invoices/{id}/send route, which is gated by invoices.send
 *      (a permission Sales holds) even though the handler posts to the GL;
 *   2. the button itself was rendered for anyone when status = draft.
 *
 * Verified here with real HTTP requests through the router: Sales may view,
 * but every posting endpoint answers 403; Accountant posts successfully.
 * Denied attempts must not mutate the invoice or create GL linkage.
 */
class InvoicePostingPermissionTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'Invoice Posting Perm Tenant',
                'email' => 'inv-perm-'.substr(md5($this->tenantId), 0, 8).'@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContextWithPermissions($this->tenantId, null, UserRole::ADMIN->spatieName(), autoAssignRole: false);
    }

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $role->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($role->spatieName());

        return $user;
    }

    /**
     * Create a draft invoice through the API and return its id.
     */
    private function createDraftInvoice(User $actor): int
    {
        $customer = Customer::factory()->create(['name' => 'Posting Perm Co', 'payment_terms' => 30]);
        $product = Product::factory()->create([
            'track_inventory' => false,
            'name' => 'Posting Perm Item',
            'price' => 100,
            'cost' => 60,
        ]);

        $response = $this->actingAs($actor)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Posting permission line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated();

        return (int) $response->json('id');
    }

    public function test_sales_cannot_post_through_any_posting_endpoint(): void
    {
        $accountant = $this->actingAsRole(UserRole::ACCOUNTANT);
        $sales = $this->actingAsRole(UserRole::SALES);

        $invoiceId = $this->createDraftInvoice($accountant);

        // Positive control: sales can still read the invoice.
        $this->actingAs($sales)->getJson("/api/v1/invoices/{$invoiceId}")
            ->assertStatus(200);

        // NOTE: creation through /api/v1/invoices is additionally gated by
        // EnsureAccountingWriteAccess (AccountingAccessService::canWrite),
        // which a pure 'sales' role does not satisfy — so sales draft creation
        // through this route group is 403 today. That is a separate accounting
        // write-access decision, deliberately not loosened here.

        // Deny: every door that posts to the GL.
        $this->actingAs($sales)->postJson("/api/v1/invoices/{$invoiceId}/post")
            ->assertStatus(403);
        $this->actingAs($sales)->postJson("/api/v1/invoices/{$invoiceId}/send")
            ->assertStatus(403);
        $this->actingAs($sales)->postJson('/api/v1/invoices/bulk-post', ['ids' => [$invoiceId]])
            ->assertStatus(403);

        // Denied attempts must not mutate state.
        $this->assertSame('draft', Invoice::query()->find($invoiceId)->status);

        // No GL linkage row may exist for the invoice (denied attempts are inert).
        $this->assertDatabaseMissing('journal_entry_links', [
            'source_document_id' => $invoiceId,
        ]);
    }

    public function test_accountant_can_post_and_the_invoice_becomes_posted(): void
    {
        $accountant = $this->actingAsRole(UserRole::ACCOUNTANT);

        $invoiceId = $this->createDraftInvoice($accountant);

        $this->actingAs($accountant)->postJson("/api/v1/invoices/{$invoiceId}/post")
            ->assertStatus(200);

        $this->assertSame('posted', Invoice::query()->find($invoiceId)->status);
    }

    public function test_legacy_send_route_requires_accounting_post_permission(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/invoices/{invoice}/send');

        $this->assertNotNull($route, 'legacy /send route missing');

        $middleware = collect($route->gatherMiddleware())
            ->first(fn ($m) => is_string($m) && str_contains($m, 'RequirePermission'));

        $this->assertNotNull($middleware, '/send has no RequirePermission middleware');
        $this->assertStringContainsString('accounting.post', (string) $middleware);
    }
}
