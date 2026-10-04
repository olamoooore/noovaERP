<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #905 (SALES-PLAT / PCU1): every Sales document needs a read-only
 * "Audit" tab backed by the #895 trail — field-level before/after (observer
 * rows) plus explicit lifecycle rows (audit_logs), scoped to the document
 * type + id, gated by the same view permission that opens the record, and
 * exported as CSV through the shared #898 formula guard.
 *
 * These tests run through the real HTTP routes (router -> permission
 * middleware -> controller) so a passing assertion cannot be satisfied by a
 * source-string or direct-instantiation shortcut.
 */
class Issue905SalesDocumentAuditTabTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            [
                'name' => 'Issue 905 Audit Tab Tenant',
                'email' => 'audit905-'.substr(md5($this->tenantId), 0, 8).'@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContextWithPermissions(
            $this->tenantId,
            null,
            UserRole::ADMIN->spatieName(),
            autoAssignRole: false,
        );
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => UserRole::ADMIN->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole(UserRole::ADMIN->spatieName());

        return $user;
    }

    /**
     * A tenant user with no role and no permission row: the document's view
     * gate must reject them. Owner/Admin bypasses must not apply.
     */
    private function actingAsUserWithoutDocumentPermission(): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );

        return $user;
    }

    private function createDraftInvoice(User $admin, float $unitPrice = 500, ?string $correlationId = null): Invoice
    {
        $customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 100000]);
        $product = Product::factory()->create([
            'name' => 'Audited Widget',
            'price' => $unitPrice,
            'track_inventory' => false,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 2,
                'unit_price' => $unitPrice,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $correlationId !== null ? ['X-Correlation-ID' => $correlationId] : []);

        $response->assertCreated();

        return Invoice::query()->with('lines')->findOrFail($response->json('id'));
    }

    public function test_invoice_audit_feed_shows_field_level_price_change_with_old_new_actor_and_request_id(): void
    {
        $admin = $this->actingAsAdmin();
        $invoice = $this->createDraftInvoice($admin, 500);
        $line = $invoice->lines->first();

        $this->actingAs($admin)->putJson("/api/v1/invoices/{$invoice->id}", [
            'customer_id' => $invoice->customer_id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => 2,
                'unit_price' => 875,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], ['X-Correlation-ID' => 'issue905-price-change'])->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/v1/invoices/{$invoice->id}/audit");
        $response->assertOk();

        $entry = collect($response->json('data'))->firstWhere('field', 'unit_price');

        $this->assertNotNull($entry, 'The invoice audit feed must show the changed line price, not just the ids.');
        $this->assertSame('field', $entry['kind']);
        $this->assertSame('invoice_updated', $entry['event']);
        $this->assertEquals(500.0, (float) $entry['old_value'], 'The audit row must keep the old price.');
        $this->assertEquals(875.0, (float) $entry['new_value'], 'The audit row must keep the new price.');
        $this->assertSame((int) $admin->id, (int) ($entry['user']['id'] ?? 0), 'The audit row must name the user.');
        $this->assertSame('issue905-price-change', $entry['request_id'] ?? null, 'The audit row must carry the request id.');
    }

    public function test_invoice_audit_feed_shows_lifecycle_events_posted_cancelled_and_reset(): void
    {
        $admin = $this->actingAsAdmin();
        $invoice = $this->createDraftInvoice($admin);

        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/send")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/cancel")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/reset-to-draft")->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/v1/invoices/{$invoice->id}/audit");
        $response->assertOk();

        $events = collect($response->json('data'))->where('kind', 'lifecycle')->pluck('event')->all();

        $this->assertContains('invoice_posted', $events, 'Posting must appear as an explicit lifecycle event.');
        $this->assertContains('invoice_cancelled', $events, 'Cancelling must appear as an explicit lifecycle event.');
        $this->assertContains('invoice_reset_to_draft', $events, 'Resetting to draft must appear as an explicit lifecycle event.');

        $posted = collect($response->json('data'))->firstWhere('event', 'invoice_posted');
        $this->assertSame('status', $posted['field']);
        $this->assertSame('draft', $posted['old_value']);
        $this->assertSame('posted', $posted['new_value']);
        $this->assertSame((int) $admin->id, (int) ($posted['user']['id'] ?? 0));
    }

    public function test_audit_feed_filters_by_field_and_user(): void
    {
        $admin = $this->actingAsAdmin();
        $invoice = $this->createDraftInvoice($admin, 500);
        $line = $invoice->lines->first();

        $this->actingAs($admin)->putJson("/api/v1/invoices/{$invoice->id}", [
            'customer_id' => $invoice->customer_id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => 2,
                'unit_price' => 875,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ])->assertOk();

        // Field filter: only the fields that actually changed come back.
        $byField = $this->actingAs($admin)->getJson("/api/v1/invoices/{$invoice->id}/audit?field=unit_price");
        $byField->assertOk();
        $this->assertTrue(
            collect($byField->json('data'))->every(fn ($row) => ($row['field'] ?? null) === 'unit_price'),
            'Filtering by field must only return rows for that field.'
        );
        $this->assertNotEmpty($byField->json('data'), 'Filtering by the changed field must keep the price row.');

        $otherField = $this->actingAs($admin)->getJson("/api/v1/invoices/{$invoice->id}/audit?field=no_such_field");
        $otherField->assertOk();
        $this->assertSame([], $otherField->json('data'), 'An unmatched field filter returns an empty feed.');

        // User filter: the acting admin owns the change; another id owns nothing.
        $byUser = $this->actingAs($admin)->getJson("/api/v1/invoices/{$invoice->id}/audit?user_id={$admin->id}");
        $byUser->assertOk();
        $this->assertNotEmpty($byUser->json('data'));

        $otherUser = $this->actingAs($admin)->getJson("/api/v1/invoices/{$invoice->id}/audit?user_id=999999");
        $otherUser->assertOk();
        $this->assertSame([], $otherUser->json('data'), 'A user filter for another actor returns an empty feed.');

        // The filter options travel with the payload so the UI can offer them.
        $this->assertContains('unit_price', $byField->json('filters.fields'));
        $this->assertNotEmpty($byField->json('filters.users'));
    }

    public function test_audit_feed_is_read_only_and_gated_by_the_documents_view_permission(): void
    {
        $admin = $this->actingAsAdmin();
        $invoice = $this->createDraftInvoice($admin);

        // Read-only: no write verbs exist on the audit route.
        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/audit")->assertStatus(405);
        $this->actingAs($admin)->putJson("/api/v1/invoices/{$invoice->id}/audit", [])->assertStatus(405);
        $this->actingAs($admin)->deleteJson("/api/v1/invoices/{$invoice->id}/audit")->assertStatus(405);
        $this->actingAs($admin)->postJson("/api/v1/invoices/{$invoice->id}/audit/export", [])->assertStatus(405);

        // A user without the document's view permission gets no audit feed.
        $outsider = $this->actingAsUserWithoutDocumentPermission();
        $this->actingAs($outsider)->getJson("/api/v1/invoices/{$invoice->id}/audit")->assertForbidden();
        $this->actingAs($outsider)->get("/api/v1/invoices/{$invoice->id}/audit/export")->assertForbidden();
    }

    public function test_customer_audit_feed_is_backed_by_the_shared_observer_field_changes(): void
    {
        $admin = $this->actingAsAdmin();
        $customer = Customer::factory()->create(['name' => 'Before Name']);

        $this->actingAs($admin)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'After Name',
        ])->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/v1/customers/{$customer->id}/audit");
        $response->assertOk();

        $entry = collect($response->json('data'))->firstWhere('field', 'name');

        $this->assertNotNull($entry, 'The customer audit tab must show the observed name change.');
        $this->assertSame('field', $entry['kind']);
        $this->assertSame('Before Name', $entry['old_value']);
        $this->assertSame('After Name', $entry['new_value']);
        $this->assertSame((int) $admin->id, (int) ($entry['user']['id'] ?? 0));
    }

    public function test_credit_note_audit_feed_lives_under_the_accounting_prefix_and_is_gated(): void
    {
        $admin = $this->actingAsAdmin();
        $invoice = $this->createDraftInvoice($admin);
        $line = $invoice->lines->first();

        $create = $this->actingAs($admin)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'lines' => [[
                'invoice_line_id' => $line->id,
                'description' => $line->description,
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $create->assertCreated();
        $creditNoteId = (int) ($create->json('id') ?? $create->json('data.id'));

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/audit");
        $response->assertOk();
        $this->assertIsArray($response->json('data'), 'The credit-note audit feed must be reachable where the page calls it.');

        $outsider = $this->actingAsUserWithoutDocumentPermission();
        $this->actingAs($outsider)
            ->getJson("/api/v1/accounting/customers/credit-notes/{$creditNoteId}/audit")
            ->assertForbidden();
    }

    public function test_audit_export_is_csv_and_neutralises_formula_injection(): void
    {
        $admin = $this->actingAsAdmin();
        $invoice = $this->createDraftInvoice($admin);

        $this->actingAs($admin)->putJson("/api/v1/invoices/{$invoice->id}", [
            'notes' => "=cmd|' /C calc'!A0",
        ])->assertOk();

        $response = $this->actingAs($admin)->get("/api/v1/invoices/{$invoice->id}/audit/export");
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->getContent();

        $this->assertStringContainsString('Field', $csv, 'The export must carry a header row.');
        $this->assertStringContainsString("'=cmd", $csv, 'Issue #898: formula-trigger strings must be neutralised in CSV.');
        $this->assertStringNotContainsString("\n=cmd", str_replace("\r\n", "\n", $csv), 'The raw formula must not survive in a cell.');
    }

    public function test_sales_order_audit_feed_uses_the_sales_view_permission_and_shows_lifecycle(): void
    {
        $this->mockFeatureGate(true);
        $admin = $this->actingAsAdmin();
        $customer = Customer::factory()->create();

        $create = $this->actingAs($admin)->postJson('/api/v1/sales/orders', [
            'customer_id' => $customer->id,
            'channel' => 'admin',
            'lines' => [[
                'description' => 'Audited order line',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('id');

        $this->actingAs($admin)
            ->postJson("/api/v1/sales/orders/{$orderId}/cancel", ['reason' => 'Audit tab test'])
            ->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/v1/sales/orders/{$orderId}/audit");
        $response->assertOk();
        $this->assertContains(
            'sales_order_cancelled',
            collect($response->json('data'))->pluck('event')->all(),
            'The sales order audit feed must include its lifecycle rows.'
        );

        $outsider = $this->actingAsUserWithoutDocumentPermission();
        $this->actingAs($outsider)->getJson("/api/v1/sales/orders/{$orderId}/audit")->assertForbidden();
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']] : []);

        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }
}
