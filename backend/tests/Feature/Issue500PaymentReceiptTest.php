<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\CustomerEmail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationPreference;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Tests\TenantTestCase;

/**
 * Issue #500 ([SALES-ODOO]): a customer payment has an official receipt —
 * numbered, listing the invoices it paid (allocation lines) and any
 * unapplied credit — rendered through the tenant's document template, and it
 * can be emailed / WhatsApp'd to the customer from the payment detail.
 *
 * Router-level tests (router → middleware → controller). Posted payments are
 * created through the real customer-first receipt endpoint (#489), so they go
 * through PaymentPostingService / PostingEngine and the #155 allocations.
 */
class Issue500PaymentReceiptTest extends TenantTestCase
{
    private string $tenantId;

    private User $accountant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady(['payments', 'payment_applications', 'invoices', 'customers', 'journal_entries']);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);
        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'email' => 'ap@receipt-customer.example.test',
            'mobile' => '+6591234567',
        ]);
        $this->seedPostingFixtures($this->accountant->id);
    }

    private function makeUser(string $centralRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole->spatieName());

        return $user;
    }

    private function seedPostingFixtures(int $userId): void
    {
        $code = now()->format('Y-m');
        if (! DB::table('fiscal_periods')->where('code', $code)->exists()) {
            DB::table('fiscal_periods')->insert([
                'name' => now()->format('F Y'),
                'code' => $code,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['cash' => '1010', 'bank' => '1020', 'accounts_receivable' => '1100'] as $key => $accountCode) {
            if (DB::table('account_mappings')->where('mapping_key', $key)->whereNull('document_type')->exists()) {
                continue;
            }
            DB::table('account_mappings')->insert([
                'mapping_key' => $key,
                'account_id' => DB::table('chart_of_accounts')->where('code', $accountCode)->value('id'),
                'document_type' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function openInvoice(string $number, float $total): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => $number,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'created_by' => $this->accountant->id,
        ]);
    }

    /**
     * RM 1,050 received; RM 300 + RM 400 applied to two invoices, RM 350 left
     * on account as unapplied credit.
     */
    private function postedSplitReceipt(): Payment
    {
        $a = $this->openInvoice('INV-500-A', 300);
        $b = $this->openInvoice('INV-500-B', 900);

        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/payments/receipts', [
                'customer_id' => $this->customer->id,
                'amount' => 1050,
                'payment_date' => now()->toDateString(),
                'method' => 'bank_transfer',
                'reference' => 'TT-500',
                'applications' => [
                    ['invoice_id' => $a->id, 'amount' => 300],
                    ['invoice_id' => $b->id, 'amount' => 400],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('unapplied_amount', 350);

        $payment = Payment::findOrFail($response->json('id'));
        $this->assertNotNull($payment->journal_entry_id, 'Fixture must be a posted payment.');

        return $payment;
    }

    // ── Receipt document ───────────────────────────────────────────────────

    public function test_receipt_lists_each_allocated_invoice_and_the_unapplied_credit(): void
    {
        $payment = $this->postedSplitReceipt();

        $html = $this->actingAs($this->accountant)
            ->get("/api/v1/payments/{$payment->id}/html")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->getContent();

        // Numbered with the payment's own number.
        $this->assertStringContainsString($payment->payment_number, $html);
        // One allocation line per invoice paid, with the applied amount.
        $this->assertStringContainsString('INV-500-A', $html);
        $this->assertStringContainsString('INV-500-B', $html);
        $this->assertStringContainsString('300.00', $html);
        $this->assertStringContainsString('400.00', $html);
        // Amount received and the remainder held on account.
        $this->assertStringContainsString('1,050.00', $html);
        $this->assertStringContainsString('Unapplied Credit', $html);
        $this->assertStringContainsString('350.00', $html);

        $pdf = $this->actingAs($this->accountant)
            ->get("/api/v1/payments/{$payment->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    // ── Email ──────────────────────────────────────────────────────────────

    public function test_receipt_is_emailed_to_the_customer_with_the_pdf_and_a_delivery_record(): void
    {
        Mail::fake();
        $payment = $this->postedSplitReceipt();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payments/{$payment->id}/send-receipt", ['channel' => 'email'])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.recipient', 'ap@receipt-customer.example.test');

        Mail::assertSent(CustomerEmail::class, function (CustomerEmail $mail) use ($payment) {
            $attachment = $mail->emailAttachments[0] ?? null;

            return $mail->hasTo('ap@receipt-customer.example.test')
                && str_contains($mail->emailSubject, $payment->payment_number)
                && $attachment !== null
                && $attachment['name'] === "Receipt-{$payment->payment_number}.pdf"
                && str_starts_with($attachment['data'], '%PDF');
        });

        // Delivery history on the payment detail.
        $this->actingAs($this->accountant)
            ->getJson("/api/v1/payments/{$payment->id}/receipt-deliveries")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel', 'email')
            ->assertJsonPath('data.0.status', 'sent')
            ->assertJsonPath('data.0.recipient', 'ap@receipt-customer.example.test');
    }

    public function test_an_unposted_payment_has_no_receipt_to_send(): void
    {
        Mail::fake();

        $draft = Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'invoice_id' => null,
            'payment_status' => 'recorded',
            'journal_entry_id' => null,
        ]);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payments/{$draft->id}/send-receipt", ['channel' => 'email'])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_a_customer_who_turned_email_off_is_not_emailed(): void
    {
        Mail::fake();
        $payment = $this->postedSplitReceipt();

        CommunicationPreference::create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $this->customer->id,
            'email_enabled' => false,
            'in_app_enabled' => true,
            'delivery_mode' => CommunicationPreference::MODE_INSTANT,
        ]);

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payments/{$payment->id}/send-receipt", ['channel' => 'email'])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    // ── WhatsApp ───────────────────────────────────────────────────────────

    public function test_receipt_is_sent_by_whatsapp_to_the_customer_mobile(): void
    {
        Http::fake();
        WhatsAppApiKey::create([
            'key' => 'issue500-test-whatsapp-key',
            'name' => 'Issue 500 WhatsApp Key',
            'whatsapp_provider' => 'custom',
            'api_endpoint' => 'https://wa.example.test/send',
            'is_active' => true,
        ]);
        $payment = $this->postedSplitReceipt();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payments/{$payment->id}/send-receipt", ['channel' => 'whatsapp'])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.recipient', '+6591234567');

        Http::assertSent(fn ($request) => $request->url() === 'https://wa.example.test/send'
            && str_contains((string) ($request->data()['message'] ?? ''), $payment->payment_number)
            && str_contains((string) ($request->data()['message'] ?? ''), 'INV-500-A'));
    }

    public function test_whatsapp_send_without_a_configured_provider_fails_visibly_and_is_logged(): void
    {
        $payment = $this->postedSplitReceipt();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payments/{$payment->id}/send-receipt", ['channel' => 'whatsapp'])
            ->assertStatus(422);

        $this->actingAs($this->accountant)
            ->getJson("/api/v1/payments/{$payment->id}/receipt-deliveries")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');
    }

    // ── Access (#611 semantics) ────────────────────────────────────────────

    public function test_send_and_render_are_scoped_like_the_receipt_render(): void
    {
        Mail::fake();

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Receipt Group', 'code' => 'R500', 'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'R500-A', 'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'R500-B', 'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $scoped = $this->makeUser('accountant', UserRole::ACCOUNTANT);
        $sales = $this->makeUser('sales', UserRole::SALES);
        foreach ([$scoped, $sales] as $user) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id, 'legal_entity_id' => $entityA, 'role' => 'editor',
                'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $customerA = Customer::factory()->create(['legal_entity_id' => $entityA, 'email' => 'a@example.test']);
        $customerB = Customer::factory()->create(['legal_entity_id' => $entityB, 'email' => 'b@example.test']);
        $paymentA = Payment::factory()->create([
            'customer_id' => $customerA->id, 'invoice_id' => null, 'legal_entity_id' => $entityA,
        ]);
        $paymentB = Payment::factory()->create([
            'customer_id' => $customerB->id, 'invoice_id' => null, 'legal_entity_id' => $entityB,
        ]);

        // Another entity's payment answers exactly like a missing one.
        $crossEntity = $this->actingAs($scoped)->withHeader('X-Entity-Id', (string) $entityA)
            ->postJson("/api/v1/payments/{$paymentB->id}/send-receipt", ['channel' => 'email']);
        $missing = $this->actingAs($scoped)->withHeader('X-Entity-Id', (string) $entityA)
            ->postJson('/api/v1/payments/99999999/send-receipt', ['channel' => 'email']);

        $this->assertSame(403, $crossEntity->getStatusCode());
        $this->assertSame($crossEntity->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($crossEntity->json('message'), $missing->json('message'));

        $crossHtml = $this->actingAs($scoped)->withHeader('X-Entity-Id', (string) $entityA)
            ->get("/api/v1/payments/{$paymentB->id}/html");
        $missingHtml = $this->actingAs($scoped)->withHeader('X-Entity-Id', (string) $entityA)
            ->get('/api/v1/payments/99999999/html');
        $this->assertSame(403, $crossHtml->getStatusCode());
        $this->assertSame($crossHtml->json('message'), $missingHtml->json('message'));

        $this->actingAs($scoped)->withHeader('X-Entity-Id', (string) $entityA)
            ->getJson("/api/v1/payments/{$paymentB->id}/receipt-deliveries")
            ->assertStatus(403);

        // Without the receipt's own read permission (accounting.view) a user
        // cannot send it either.
        $this->actingAs($sales)->withHeader('X-Entity-Id', (string) $entityA)
            ->postJson("/api/v1/payments/{$paymentA->id}/send-receipt", ['channel' => 'email'])
            ->assertStatus(403);

        Mail::assertNothingSent();
    }
}
