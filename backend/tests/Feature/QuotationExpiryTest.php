<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #335 (Q6) — quotation validity expiry.
 *
 * `valid_until` was stored but never read: expired quotations could still be
 * confirmed and converted, and there was no expiry job or `expired` status.
 * The daily `quotations:expire` command marks past-due draft/sent quotations
 * expired through the #900 workflow engine (audited); confirm, convert (invoice
 * and delivery order) and the public portal acceptance all refuse a past-due
 * quotation until it is re-validated by a new valid-until date.
 *
 * Router-level tests (repo SOP §3): every step goes through the real HTTP route
 * chain or the real scheduled command — never a direct service instantiation.
 */
class QuotationExpiryTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = $this->makeUser('admin', UserRole::ADMIN);

        $this->customer = Customer::factory()->create([
            'name' => 'Expiry Customer',
            'email' => 'expiry-customer@example.com',
            'payment_terms' => 30,
            'credit_limit' => 1000000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Expiry Widget',
            'sku' => 'EXP-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Warehouse::factory()->create(['is_default' => true]);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function makeQuotation(array $attributes = [], float $total = 200): Quotation
    {
        $quotation = Quotation::create(array_merge([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-'.uniqid(),
            'quotation_date' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'created_by' => $this->admin->id,
        ], $attributes));

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => (int) ceil($total / 100),
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => $total,
        ]);

        return $quotation->fresh('lines');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. The scheduled command: past-due draft/sent → expired, nothing else
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_expire_command_marks_only_past_due_unconverted_quotations_expired(): void
    {
        $pastDraft = $this->makeQuotation([
            'status' => 'draft',
            'valid_until' => now()->subDay()->toDateString(),
        ]);
        $pastSent = $this->makeQuotation([
            'status' => 'sent',
            'valid_until' => now()->subDays(3)->toDateString(),
        ]);
        $futureDraft = $this->makeQuotation([
            'status' => 'draft',
            'valid_until' => now()->addDay()->toDateString(),
        ]);
        $noValidity = $this->makeQuotation([
            'status' => 'sent',
            'valid_until' => null,
        ]);
        $validThroughToday = $this->makeQuotation([
            'status' => 'sent',
            'valid_until' => now()->toDateString(),
        ]);
        $confirmedPast = $this->makeQuotation([
            'status' => 'confirmed',
            'valid_until' => now()->subDay()->toDateString(),
        ]);
        $cancelledPast = $this->makeQuotation([
            'status' => 'cancelled',
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('quotations:expire', ['--tenant' => $this->tenantId])->assertExitCode(0);

        $this->assertSame('expired', $pastDraft->fresh()->status, 'A past-due draft quotation expires.');
        $this->assertSame('expired', $pastSent->fresh()->status, 'A past-due sent quotation expires.');

        $this->assertSame('draft', $futureDraft->fresh()->status, 'A future validity date never expires.');
        $this->assertSame('sent', $noValidity->fresh()->status, 'A null validity date never expires.');
        $this->assertSame('sent', $validThroughToday->fresh()->status, 'A quotation valid through today is not yet expired.');
        $this->assertSame('confirmed', $confirmedPast->fresh()->status, 'A confirmed quotation is never expired by the command.');
        $this->assertSame('cancelled', $cancelledPast->fresh()->status, 'A cancelled quotation is never expired by the command.');

        // The transition is engine-owned (audited + transition log).
        $log = DB::table('workflow_logs')
            ->where('entity_type', 'quotation')
            ->where('entity_id', (string) $pastDraft->id)
            ->where('action', 'expire')
            ->where('result', 'applied')
            ->first();
        $this->assertNotNull($log, 'The expire transition must be recorded by the workflow engine.');
        $this->assertSame('draft', $log->old_status);
        $this->assertSame('expired', $log->new_status);

        $this->assertSame(1, DB::table('audit_logs')
            ->where('reference_type', 'quotation')
            ->where('reference_id', $pastDraft->id)
            ->where('operation', 'workflow_expire')
            ->count());

        // The #901 catalogue event is queued for the outbox.
        $this->assertTrue(DB::table('sales_event_outbox')
            ->where('event_name', 'quotation.expired.v1')
            ->where('document_id', (string) $pastDraft->id)
            ->exists(), 'Expiry must emit quotation.expired.v1.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Confirm is refused while expired; a future validity date restores it
    // ─────────────────────────────────────────────────────────────────────

    public function test_confirm_is_refused_for_a_past_due_quotation_until_the_validity_date_is_moved_forward(): void
    {
        $quotation = $this->makeQuotation([
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $refused = $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm");

        $refused->assertStatus(422);
        $this->assertStringContainsString('expired', (string) $refused->json('message'));
        $this->assertSame('draft', $quotation->fresh()->status, 'A refused confirm must not change the status.');
        $this->assertNull($quotation->fresh()->sales_order_id, 'No Sales Order may be created for an expired quotation.');
        $this->assertSame(0, SalesOrder::query()->count());

        // Re-validated: editing valid_until to a future date.
        $this->actingAs($this->admin)
            ->putJson("/api/v1/quotations/{$quotation->id}", [
                'valid_until' => now()->addDays(30)->toDateString(),
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNotNull($quotation->sales_order_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. A command-expired quotation is re-validated by the edit path
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_command_expired_quotation_returns_to_draft_when_its_validity_date_is_extended(): void
    {
        $quotation = $this->makeQuotation([
            'status' => 'sent',
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('quotations:expire', ['--tenant' => $this->tenantId])->assertExitCode(0);
        $this->assertSame('expired', $quotation->fresh()->status);

        // While expired, confirm stays refused with the expiry reason.
        $refused = $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm");
        $refused->assertStatus(422);
        $this->assertStringContainsString('expired', (string) $refused->json('message'));

        $this->actingAs($this->admin)
            ->putJson("/api/v1/quotations/{$quotation->id}", [
                'valid_until' => now()->addDays(14)->toDateString(),
            ])
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('draft', $quotation->status, 'Re-validating an expired quotation returns it to draft.');

        $log = DB::table('workflow_logs')
            ->where('entity_type', 'quotation')
            ->where('entity_id', (string) $quotation->id)
            ->where('action', 'revalidate')
            ->where('result', 'applied')
            ->first();
        $this->assertNotNull($log, 'The revalidation must be recorded by the workflow engine.');
        $this->assertSame('expired', $log->old_status);
        $this->assertSame('draft', $log->new_status);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();
        $this->assertSame('confirmed', $quotation->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Conversion paths (invoice + delivery order) refuse an expired quote
    // ─────────────────────────────────────────────────────────────────────

    public function test_conversion_to_invoice_and_delivery_order_is_refused_for_an_expired_quotation(): void
    {
        $quotation = $this->makeQuotation([
            'status' => 'sent',
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $invoiceResponse = $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice");
        $invoiceResponse->assertStatus(422);
        $this->assertStringContainsString('expired', (string) $invoiceResponse->json('message'));

        $deliveryResponse = $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order");
        $deliveryResponse->assertStatus(422);
        $this->assertStringContainsString('expired', (string) $deliveryResponse->json('message'));

        $quotation->refresh();
        $this->assertSame('sent', $quotation->status);
        $this->assertNull($quotation->sales_order_id, 'An expired quotation must not be released into a Sales Order.');
        $this->assertSame(0, Invoice::query()->where('quotation_id', $quotation->id)->count());
        $this->assertSame(0, DeliveryOrder::query()->where('quotation_id', $quotation->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Portal acceptance: the token outlives validity, the guard still bites
    // ─────────────────────────────────────────────────────────────────────

    public function test_portal_acceptance_is_refused_once_the_validity_date_has_passed(): void
    {
        Mail::fake();

        $quotation = $this->makeQuotation([
            'valid_until' => now()->addDay()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/send")
            ->assertOk();

        $token = $quotation->fresh()->acceptance_token;
        $this->assertNotNull($token);

        // Time passes: the 14-day acceptance token is still valid, the
        // quotation's own validity date is not.
        $quotation->fresh()->update([
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $accept = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ]);

        $accept->assertStatus(422);
        $this->assertStringContainsString('expired', (string) $accept->json('error'));

        $quotation->refresh();
        $this->assertSame('sent', $quotation->status, 'A refused portal acceptance must not confirm the quotation.');
        $this->assertNull($quotation->accepted_at, 'A refused portal acceptance must not record signer metadata.');
        $this->assertNull($quotation->sales_order_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6. The list filter returns only expired quotations
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_quotation_list_filters_by_expired_status(): void
    {
        $pastDue = $this->makeQuotation([
            'status' => 'draft',
            'valid_until' => now()->subDay()->toDateString(),
        ]);
        $open = $this->makeQuotation([
            'status' => 'sent',
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        $this->artisan('quotations:expire', ['--tenant' => $this->tenantId])->assertExitCode(0);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/quotations?status=expired')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($pastDue->id, $ids, 'The expired filter must return the expired quotation.');
        $this->assertNotContains($open->id, $ids, 'The expired filter must not return open quotations.');
    }
}
