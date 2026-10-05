<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Features\ActivityLogging\Models\ActivityLogEntry;
use App\Mail\CustomerEmail;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\Services\SalesOrderAcceptanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #334 (Q5) — send a quotation with an acceptance link and accept it
 * through the public portal.
 *
 * The #149 acceptance service was unreachable (no caller) and built on the
 * SalesOrder; and no "Send by email" existed for quotations, so `sent` was
 * never set while confirm always logged `from_state: 'sent'`.
 *
 * These are router-level tests (repo SOP §3): every step goes through the real
 * HTTP route chain — the authenticated send route and the public, token-based
 * acceptance route (#149) — never a direct service call.
 */
class QuotationSendAcceptanceTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private User $reviewer;

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
        $this->reviewer = $this->makeUser('manager', UserRole::MANAGER);

        $this->customer = Customer::factory()->create([
            'name' => 'Quote Acceptance Customer',
            'email' => 'acceptance-customer@example.com',
            'payment_terms' => 30,
            'credit_limit' => 1000000,
            'billing_address' => 'Acceptance Billing',
            'shipping_address' => 'Acceptance Shipping',
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Acceptance Widget',
            'sku' => 'ACC-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);
        Setting::setValue('sales.quotation.reviewer_roles', []);

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

    private function draftQuotation(float $total = 250): Quotation
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => max(1, (int) ceil($total / 100)),
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated();

        return Quotation::query()->findOrFail((int) $response->json('data.id'));
    }

    private function sendQuotation(Quotation $quotation): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$quotation->id}/send");
    }

    private function latestChatter(Quotation $quotation, string $event): ?ActivityLogEntry
    {
        return ActivityLogEntry::query()
            ->where('subject_type', Quotation::class)
            ->where('subject_id', $quotation->id)
            ->where('event', $event)
            ->latest('id')
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. Send: PDF + acceptance link, `sent` via the workflow engine
    // ─────────────────────────────────────────────────────────────────────

    public function test_send_emails_pdf_and_acceptance_link_and_marks_quotation_sent(): void
    {
        Mail::fake();

        $quotation = $this->draftQuotation();

        $response = $this->sendQuotation($quotation);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'sent');
        $response->assertJsonPath('data.quotation_number', $quotation->quotation_number);

        $quotation->refresh();
        $this->assertSame('sent', $quotation->status, 'Send must move the quotation to sent.');
        $this->assertNotNull($quotation->acceptance_token, 'Send must issue an acceptance token.');
        $this->assertNotNull($quotation->acceptance_expires_at);
        $this->assertTrue($quotation->acceptance_expires_at->isFuture());

        // The status write went through the #900 engine, not a literal write.
        $log = DB::table('workflow_logs')
            ->where('entity_type', 'quotation')
            ->where('entity_id', (string) $quotation->id)
            ->where('action', 'send')
            ->where('result', 'applied')
            ->first();
        $this->assertNotNull($log, 'The send transition must be recorded by the workflow engine.');
        $this->assertSame('draft', $log->old_status);
        $this->assertSame('sent', $log->new_status);

        $token = $quotation->acceptance_token;

        Mail::assertSent(CustomerEmail::class, function (CustomerEmail $mail) use ($token, $quotation) {
            $this->assertStringContainsString(
                rtrim((string) config('frontend.url'), '/')."/quotation-accept/{$token}",
                $mail->emailBody,
                'The email must carry the public acceptance link.',
            );
            $this->assertCount(1, $mail->emailAttachments, 'The email must attach the quotation PDF.');
            $this->assertSame('application/pdf', $mail->emailAttachments[0]['mime']);
            $this->assertStringContainsString($quotation->quotation_number, $mail->emailAttachments[0]['name']);

            return true;
        });

        // Activity history records the REAL previous status.
        $chatter = $this->latestChatter($quotation, 'status_changed');
        $this->assertNotNull($chatter, 'Sending must leave an activity entry.');
        $this->assertSame('draft', $chatter->properties['from_state'] ?? null);
        $this->assertSame('sent', $chatter->properties['to_state'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Public portal: accept → confirm through the same approval gate
    // ─────────────────────────────────────────────────────────────────────

    public function test_public_portal_accepts_quotation_and_confirms_it_into_a_sales_order(): void
    {
        Mail::fake();

        $quotation = $this->draftQuotation();
        $this->sendQuotation($quotation)->assertOk();

        $token = $quotation->fresh()->acceptance_token;

        $show = $this->getJson("/api/v1/quotation-accept/{$token}");

        $show->assertOk();
        $show->assertJsonPath('order_number', $quotation->quotation_number);
        $show->assertJsonPath('status', 'sent');
        $show->assertJsonPath('customer_name', 'Quote Acceptance Customer');
        $show->assertJsonPath('already_accepted', false);
        $show->assertJsonPath('lines.0.description', $this->product->name);

        $accept = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
            'signature' => 'data:image/png;base64,iVBORw0KGgo=',
        ]);

        $accept->assertOk();
        $accept->assertJsonPath('status', 'confirmed');
        $accept->assertJsonPath('quotation_number', $quotation->quotation_number);

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status, 'Portal acceptance must confirm the quotation.');
        $this->assertNotNull($quotation->accepted_at);
        $this->assertSame('Carla Customer', $quotation->signer_name);
        $this->assertSame('carla@example.com', $quotation->signer_email);
        $this->assertSame(SalesOrderAcceptanceService::SOURCE_PORTAL, $quotation->acceptance_source);

        // Confirm behaves exactly like the internal confirm route: a Sales
        // Order is created and confirmed from the quotation.
        $this->assertNotNull($quotation->sales_order_id);
        $order = SalesOrder::query()->findOrFail((int) $quotation->sales_order_id);
        $this->assertSame('confirmed', $order->status);

        // The confirm transition is engine-owned, with the real previous state.
        $log = DB::table('workflow_logs')
            ->where('entity_type', 'quotation')
            ->where('entity_id', (string) $quotation->id)
            ->where('action', 'confirm')
            ->where('result', 'applied')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->old_status);
        $this->assertSame('confirmed', $log->new_status);

        $chatter = $this->latestChatter($quotation, 'status_changed');
        $this->assertNotNull($chatter, 'Portal confirmation must leave an activity entry.');
        $this->assertSame('sent', $chatter->properties['from_state'] ?? null);
        $this->assertSame('confirmed', $chatter->properties['to_state'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. The confirm activity records the real previous status (draft)
    // ─────────────────────────────────────────────────────────────────────

    public function test_confirming_a_draft_records_draft_as_the_previous_state(): void
    {
        $quotation = $this->draftQuotation();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $chatter = $this->latestChatter($quotation, 'status_changed');
        $this->assertNotNull($chatter);
        $this->assertSame(
            'draft',
            $chatter->properties['from_state'] ?? null,
            'Confirm must log the document\'s real previous status, not a hardcoded sent.',
        );
        $this->assertSame('confirmed', $chatter->properties['to_state'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Approval gate: the portal acceptance runs the standard flow
    // ─────────────────────────────────────────────────────────────────────

    public function test_portal_acceptance_of_an_over_threshold_quotation_raises_the_standard_approval(): void
    {
        Mail::fake();

        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);

        $quotation = $this->draftQuotation(2500);
        $this->sendQuotation($quotation)->assertOk();

        $token = $quotation->fresh()->acceptance_token;

        $accept = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ]);

        $accept->assertStatus(202);
        $accept->assertJsonPath('requires_approval', true);

        $quotation->refresh();
        $this->assertSame('sent', $quotation->status, 'An unapproved quotation must not be confirmed.');
        $this->assertNull($quotation->sales_order_id, 'No Sales Order may be created without approval.');
        $this->assertNotNull($quotation->accepted_at, 'The customer acceptance itself is recorded.');

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->where('status', 'pending')
            ->firstOrFail();

        // Standard flow: the reviewer approves, then the quotation confirms.
        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve")
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNotNull($quotation->sales_order_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Token / state guards
    // ─────────────────────────────────────────────────────────────────────

    public function test_invalid_token_is_rejected(): void
    {
        $this->getJson('/api/v1/quotation-accept/not-a-real-token')->assertStatus(404);

        $this->postJson('/api/v1/quotation-accept/not-a-real-token/accept', [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ])->assertStatus(404);
    }

    public function test_expired_token_is_rejected(): void
    {
        Mail::fake();

        $quotation = $this->draftQuotation();
        $this->sendQuotation($quotation)->assertOk();

        $quotation->refresh();
        $quotation->update(['acceptance_expires_at' => now()->subDay()]);

        $response = $this->postJson("/api/v1/quotation-accept/{$quotation->acceptance_token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ]);

        $response->assertStatus(410);
        $this->assertSame('sent', $quotation->fresh()->status, 'An expired token must not confirm the quotation.');
    }

    public function test_re_accepting_a_used_token_is_idempotent(): void
    {
        Mail::fake();

        $quotation = $this->draftQuotation();
        $this->sendQuotation($quotation)->assertOk();

        $token = $quotation->fresh()->acceptance_token;

        $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ])->assertOk();

        $second = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Different Person',
            'signer_email' => 'other@example.com',
        ]);

        $second->assertOk();
        $second->assertJsonPath('signer_name', 'Carla Customer');

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertSame('Carla Customer', $quotation->signer_name);
        $this->assertSame(1, SalesOrder::where('customer_id', $this->customer->id)->count(), 'Only one Sales Order may be created.');
    }

    public function test_acceptance_of_a_cancelled_quotation_is_rejected(): void
    {
        Mail::fake();

        $quotation = $this->draftQuotation();
        $this->sendQuotation($quotation)->assertOk();
        $token = $quotation->fresh()->acceptance_token;

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/cancel")
            ->assertOk();

        $response = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ]);

        $response->assertStatus(410);
        $this->assertSame('cancelled', $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->sales_order_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6. Auth / permission denial
    // ─────────────────────────────────────────────────────────────────────

    public function test_send_requires_authentication(): void
    {
        $quotation = $this->draftQuotation();

        // Drop the guard created by the setup request so this request is
        // genuinely unauthenticated.
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/quotations/{$quotation->id}/send")->assertStatus(401);
        $this->assertSame('draft', $quotation->fresh()->status);
    }

    public function test_send_requires_the_quotations_write_permission(): void
    {
        $quotation = $this->draftQuotation();
        $cashier = $this->makeUser('cashier', UserRole::CASHIER);

        $this->actingAs($cashier)
            ->postJson("/api/v1/quotations/{$quotation->id}/send")
            ->assertStatus(403);

        $this->assertSame('draft', $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->acceptance_token);
    }

    public function test_send_is_rejected_for_a_confirmed_quotation(): void
    {
        $quotation = $this->draftQuotation();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $this->sendQuotation($quotation)->assertStatus(422);
        $this->assertSame('confirmed', $quotation->fresh()->status);
    }
}
