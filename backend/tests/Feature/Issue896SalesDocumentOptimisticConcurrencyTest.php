<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #896 (SALES-PLAT / PC2) — concurrent edits silently overwrote each
 * other because invoice, quotation and sales-order updates had no version
 * check: the last save won and the other user's line set disappeared.
 *
 * These are router-level feature tests (repo SOP §3): documents are fetched
 * through the real GET routes, edited through the real PUT routes, and the
 * concurrency token is whatever those responses actually expose.
 *
 * Backward compatibility (documented limitation, asserted below): a request
 * WITHOUT `version`, `updated_at` or an `If-Match` header keeps the legacy
 * last-write-wins behaviour, because existing clients do not send a token.
 */
class Issue896SalesDocumentOptimisticConcurrencyTest extends TenantTestCase
{
    private string $tenantId;

    private User $owner;

    private User $secondUser;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Optimistic Concurrency Tenant',
            'email' => 'optimistic-concurrency@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        // Sales-order routes carry the `sales` entitlement; the invoice and
        // quotation routes do not. Mock the platform gate as entitled so the
        // sales-order path is exercised through the real router.
        $this->mockFeatureGate();

        $this->owner = $this->makeUser('owner');
        $this->secondUser = $this->makeUser('owner');

        $this->customer = Customer::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function mockFeatureGate(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => true,
                'source' => 'tier_included',
                'name' => strtoupper($key),
            ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function makeInvoice(): Invoice
    {
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-896-'.Str::upper(Str::random(6)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);

        $invoice->lines()->create([
            'line_type' => 'product',
            'description' => 'Original line',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        $invoice->refresh();
        $invoice->calculateTotals();

        return $invoice;
    }

    private function makeQuotation(): Quotation
    {
        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-896-'.Str::upper(Str::random(6)),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'exchange_rate' => 1,
            'created_by' => $this->owner->id,
        ]);

        $quotation->lines()->create([
            'line_type' => 'product',
            'description' => 'Quotation line',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        return $quotation;
    }

    private function makeSalesOrder(): SalesOrder
    {
        return SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Acceptance: two updates with the same base version — the second gets
    // 409 and nothing is overwritten (invoice, quotation, sales order).
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_stale_updated_at_is_rejected_and_first_write_is_preserved(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');
        $invoice = $this->makeInvoice();
        $baseToken = $invoice->updated_at->toIso8601String();

        // The first writer saves (clock moved so the write produces a new
        // updated_at distinct from the base token the client is holding).
        Carbon::setTestNow('2026-10-03 09:00:07');
        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'customer_reference' => 'FIRST-WRITE',
                'updated_at' => $baseToken,
            ])
            ->assertOk();

        $this->assertSame('FIRST-WRITE', $invoice->fresh()->customer_reference);

        // The second writer submits an edit based on the SAME base version.
        $response = $this->actingAs($this->secondUser)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'customer_reference' => 'SECOND-WRITE',
                'updated_at' => $baseToken,
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'stale_record');
        $this->assertSame(
            'FIRST-WRITE',
            $invoice->fresh()->customer_reference,
            'The stale update must not overwrite the first write.'
        );
    }

    public function test_invoice_version_token_conflict_returns_409_with_current_state(): void
    {
        $invoice = $this->makeInvoice();

        $version = $this->actingAs($this->owner)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->json('lock_version');

        $this->assertNotNull($version, 'GET must expose the document lock version.');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'customer_reference' => 'FIRST-WRITE',
                'version' => $version,
            ])
            ->assertOk();

        $this->assertSame((int) $version + 1, (int) $invoice->fresh()->lock_version);

        $response = $this->actingAs($this->secondUser)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'customer_reference' => 'SECOND-WRITE',
                'version' => $version,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'stale_record')
            ->assertJsonPath('current_version', (int) $version + 1);

        $this->assertNotNull($response->json('current_updated_at'));
        $this->assertSame('FIRST-WRITE', $response->json('data.customer_reference'));
        $this->assertSame('FIRST-WRITE', $invoice->fresh()->customer_reference);
    }

    public function test_stale_invoice_update_cannot_wipe_lines_written_by_the_first_update(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');
        $invoice = $this->makeInvoice();
        $baseToken = $invoice->updated_at->toIso8601String();

        Carbon::setTestNow('2026-10-03 09:00:07');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'lines' => [[
                    'line_type' => 'product',
                    'description' => 'FIRST LINE',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ]],
                'updated_at' => $baseToken,
            ])
            ->assertOk();

        $this->assertSame(['FIRST LINE'], $invoice->fresh()->lines->pluck('description')->all());

        $response = $this->actingAs($this->secondUser)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'lines' => [[
                    'line_type' => 'product',
                    'description' => 'SECOND LINE',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ]],
                'updated_at' => $baseToken,
            ]);

        $response->assertStatus(409);
        $this->assertSame(
            ['FIRST LINE'],
            $invoice->fresh()->lines->pluck('description')->all(),
            'The stale writer must not replace the lines saved by the first writer.'
        );
    }

    public function test_quotation_stale_updated_at_is_rejected_and_first_write_is_preserved(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');
        $quotation = $this->makeQuotation();
        $baseToken = $quotation->updated_at->toIso8601String();

        Carbon::setTestNow('2026-10-03 09:00:07');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/quotations/{$quotation->id}", [
                'notes' => 'FIRST-WRITE',
                'updated_at' => $baseToken,
            ])
            ->assertOk();

        $this->assertSame('FIRST-WRITE', $quotation->fresh()->notes);

        $response = $this->actingAs($this->secondUser)
            ->putJson("/api/v1/quotations/{$quotation->id}", [
                'notes' => 'SECOND-WRITE',
                'updated_at' => $baseToken,
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'stale_record');
        $this->assertSame('FIRST-WRITE', $quotation->fresh()->notes);
    }

    public function test_sales_order_stale_version_is_rejected_and_first_write_is_preserved(): void
    {
        $order = $this->makeSalesOrder();

        $version = $this->actingAs($this->owner)
            ->getJson("/api/v1/sales/orders/{$order->id}")
            ->assertOk()
            ->json('lock_version');

        $this->assertNotNull($version, 'GET must expose the document lock version.');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/sales/orders/{$order->id}", [
                'notes' => 'FIRST-WRITE',
                'version' => $version,
            ])
            ->assertOk();

        $this->assertSame('FIRST-WRITE', $order->fresh()->notes);
        $this->assertSame((int) $version + 1, (int) $order->fresh()->lock_version);

        $response = $this->actingAs($this->secondUser)
            ->putJson("/api/v1/sales/orders/{$order->id}", [
                'notes' => 'SECOND-WRITE',
                'version' => $version,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'stale_record')
            ->assertJsonPath('current_version', (int) $version + 1);
        $this->assertSame('FIRST-WRITE', $order->fresh()->notes);
    }

    // ─────────────────────────────────────────────────────────────────────
    // If-Match support
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_if_match_header_conflict_returns_409(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->owner)
            ->withHeaders(['If-Match' => '"1"'])
            ->putJson("/api/v1/invoices/{$invoice->id}", ['customer_reference' => 'FIRST-WRITE'])
            ->assertOk();

        $response = $this->actingAs($this->secondUser)
            ->withHeaders(['If-Match' => '"1"'])
            ->putJson("/api/v1/invoices/{$invoice->id}", ['customer_reference' => 'SECOND-WRITE']);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'stale_record')
            ->assertJsonPath('current_version', 2);
        $this->assertSame('FIRST-WRITE', $invoice->fresh()->customer_reference);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Optional soft-lock exposure on conflict
    // ─────────────────────────────────────────────────────────────────────

    public function test_version_conflict_exposes_who_is_currently_editing(): void
    {
        $invoice = $this->makeInvoice();

        $version = $this->actingAs($this->owner)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->json('lock_version');

        $this->assertNotNull($version);

        // The owner saves first — the platform locking service records them
        // as the active editor (soft lock, with expiry).
        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'customer_reference' => 'FIRST-WRITE',
                'version' => $version,
            ])
            ->assertOk();

        $response = $this->actingAs($this->secondUser)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'customer_reference' => 'SECOND-WRITE',
                'version' => $version,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'stale_record')
            ->assertJsonPath('active_lock.user_id', $this->owner->id);

        $this->assertNotNull($response->json('active_lock.expires_at'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Documented backward-compatibility fallback
    // ─────────────────────────────────────────────────────────────────────

    public function test_update_without_a_version_token_keeps_legacy_last_write_wins_behaviour(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['customer_reference' => 'LEGACY-A'])
            ->assertOk();

        $this->actingAs($this->owner)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['customer_reference' => 'LEGACY-B'])
            ->assertOk();

        $this->assertSame('LEGACY-B', $invoice->fresh()->customer_reference);
    }
}
