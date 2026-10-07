<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Models\GeneratedDocument;
use Tests\TenantTestCase;

/**
 * Issue #337 — customer portal: one signed, expiring, revocable link per
 * customer that lists that customer's posted invoices and sent/accepted
 * quotations plus the statement, with download (issued PDF) and pay (the
 * existing payment link).
 *
 * Every step goes through the real HTTP route chain: the authenticated staff
 * route that issues/revokes the link and the public token routes the customer
 * opens. The portal must be scoped to exactly ONE customer (and the legal
 * entity it was issued for): another customer's document id is a 404, never
 * a leak.
 */
class Issue337CustomerPortalTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Customer $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['name' => 'Portal Customer', 'email' => 'portal@example.test']);
        $this->otherCustomer = Customer::factory()->create(['name' => 'Someone Else', 'email' => 'else@example.test']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function issueLink(Customer $customer, array $payload = []): array
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/customers/{$customer->id}/portal-links", $payload);

        $response->assertCreated();

        return $response->json('data');
    }

    private function postedInvoice(Customer $customer, string $number, array $overrides = []): Invoice
    {
        return Invoice::factory()->posted()->create(array_merge([
            'invoice_number' => $number,
            'customer_id' => $customer->id,
            'total' => 500,
            'subtotal' => 500,
            'tax_amount' => 0,
            'paid_amount' => 0,
            'balance' => 500,
            'currency_code' => 'USD',
        ], $overrides));
    }

    private function quotation(Customer $customer, string $number, string $status): Quotation
    {
        return Quotation::query()->forceCreate([
            'quotation_number' => $number,
            'customer_id' => $customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => $status,
            'subtotal' => 200,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 200,
            'created_by' => $this->admin->id,
        ]);
    }

    private function publicGet(string $uri): \Illuminate\Testing\TestResponse
    {
        // The customer is anonymous: never carry the staff session.
        $this->app['auth']->forgetGuards();

        return $this->getJson($uri);
    }

    private function publicPost(string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson($uri, $data);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Issuance
    // ─────────────────────────────────────────────────────────────────────

    public function test_staff_issues_a_portal_link_registered_centrally_with_only_a_hash_stored(): void
    {
        $link = $this->issueLink($this->customer, ['expires_in_days' => 14]);

        $this->assertNotEmpty($link['token']);
        $this->assertGreaterThanOrEqual(48, strlen($link['token']));
        $this->assertStringEndsWith('/customer-portal/'.$link['token'], $link['url']);

        $row = DB::table('public.document_token_lookup')
            ->where('token_hash', hash('sha256', $link['token']))
            ->first();
        $this->assertNotNull($row, 'The portal token must be registered in the central #466 lookup.');
        $this->assertSame($this->tenantId, (string) $row->tenant_id);
        $this->assertSame('customer_portal', $row->document_type);

        $stored = DB::table('customer_portal_links')->where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($stored);
        $this->assertSame(hash('sha256', $link['token']), $stored->token_hash);
        $this->assertNotContains($link['token'], array_map('strval', (array) $stored), 'The raw token must never be stored.');
        $this->assertTrue(now()->addDays(13)->lessThan($stored->expires_at));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Listing scope
    // ─────────────────────────────────────────────────────────────────────

    public function test_portal_lists_only_this_customers_posted_invoices_and_sent_or_accepted_quotations(): void
    {
        $mine = $this->postedInvoice($this->customer, 'INV-337-MINE');
        Invoice::factory()->create(['invoice_number' => 'INV-337-DRAFT', 'customer_id' => $this->customer->id]);
        $this->postedInvoice($this->otherCustomer, 'INV-337-OTHER');

        $sent = $this->quotation($this->customer, 'QT-337-SENT', 'sent');
        $accepted = $this->quotation($this->customer, 'QT-337-ACC', 'confirmed');
        $this->quotation($this->customer, 'QT-337-DRAFT', 'draft');
        $this->quotation($this->otherCustomer, 'QT-337-OTHER', 'sent');

        $link = $this->issueLink($this->customer);

        $response = $this->publicGet("/api/v1/customer-portal/{$link['token']}");

        $response->assertOk();
        $response->assertJsonPath('customer.name', 'Portal Customer');

        $invoiceNumbers = collect($response->json('invoices'))->pluck('invoice_number')->all();
        $this->assertSame(['INV-337-MINE'], $invoiceNumbers);
        $this->assertSame($mine->id, $response->json('invoices.0.id'));

        $quoteNumbers = collect($response->json('quotations'))->pluck('quotation_number')->sort()->values()->all();
        $this->assertSame(['QT-337-ACC', 'QT-337-SENT'], $quoteNumbers);
        $this->assertContains($sent->id, collect($response->json('quotations'))->pluck('id')->all());
        $this->assertContains($accepted->id, collect($response->json('quotations'))->pluck('id')->all());
    }

    public function test_portal_issued_for_a_legal_entity_hides_that_customers_invoices_in_other_entities(): void
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'I337 Group', 'code' => 'I337-GRP', 'base_currency' => 'USD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entity = fn (string $code) => DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => $code, 'code' => $code, 'base_currency' => 'USD',
            'fiscal_year_start' => '01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityA = $entity('I337-LE-A');
        $entityB = $entity('I337-LE-B');

        $this->customer->forceFill(['legal_entity_id' => $entityA])->save();

        $this->postedInvoice($this->customer, 'INV-337-LEA', ['legal_entity_id' => $entityA]);
        $this->postedInvoice($this->customer, 'INV-337-LEB', ['legal_entity_id' => $entityB]);

        $link = $this->issueLink($this->customer);

        $response = $this->publicGet("/api/v1/customer-portal/{$link['token']}");
        $response->assertOk();

        $this->assertSame(['INV-337-LEA'], collect($response->json('invoices'))->pluck('invoice_number')->all());
    }

    public function test_another_customers_document_id_is_a_404_on_every_document_route(): void
    {
        $this->postedInvoice($this->customer, 'INV-337-OWN');
        $foreignInvoice = $this->postedInvoice($this->otherCustomer, 'INV-337-FOREIGN', [
            'payment_link_url' => 'https://pay.example.test/foreign',
            'payment_link_expires_at' => now()->addDays(5),
        ]);
        $foreignQuote = $this->quotation($this->otherCustomer, 'QT-337-FOREIGN', 'sent');
        $draft = Invoice::factory()->create(['invoice_number' => 'INV-337-OWN-DRAFT', 'customer_id' => $this->customer->id]);

        $link = $this->issueLink($this->customer);
        $base = "/api/v1/customer-portal/{$link['token']}";

        $this->publicGet("{$base}/invoices/{$foreignInvoice->id}/pdf")->assertNotFound();
        $this->publicPost("{$base}/invoices/{$foreignInvoice->id}/pay")->assertNotFound();
        $this->publicGet("{$base}/quotations/{$foreignQuote->id}/pdf")->assertNotFound();

        // The customer's own unposted draft is not on the portal either.
        $this->publicGet("{$base}/invoices/{$draft->id}/pdf")->assertNotFound();
        $this->publicPost("{$base}/invoices/{$draft->id}/pay")->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Token lifecycle
    // ─────────────────────────────────────────────────────────────────────

    public function test_unknown_token_is_404_and_expired_token_is_410(): void
    {
        $this->publicGet('/api/v1/customer-portal/'.Str::random(64))->assertNotFound();

        $link = $this->issueLink($this->customer);
        DB::table('customer_portal_links')->where('customer_id', $this->customer->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->publicGet("/api/v1/customer-portal/{$link['token']}")
            ->assertStatus(410)
            ->assertJsonPath('code', 'EXPIRED');
    }

    public function test_revoked_token_stops_working_and_leaves_the_central_lookup(): void
    {
        $link = $this->issueLink($this->customer);

        $this->publicGet("/api/v1/customer-portal/{$link['token']}")->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/customers/{$this->customer->id}/portal-links/{$link['id']}")
            ->assertOk();

        $this->publicGet("/api/v1/customer-portal/{$link['token']}")->assertNotFound();

        $this->assertNull(
            DB::table('public.document_token_lookup')->where('token_hash', hash('sha256', $link['token']))->first(),
            'A revoked portal token must no longer resolve a tenant.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Download + pay + statement
    // ─────────────────────────────────────────────────────────────────────

    public function test_download_returns_the_issued_immutable_pdf(): void
    {
        Storage::fake('local');
        config(['templatebuilder.pdf.snapshot_disk' => 'local']);

        $invoice = $this->postedInvoice($this->customer, 'INV-337-PDF');

        $bytes = "%PDF-1.4\n% issued copy of INV-337-PDF\n%%EOF";
        $path = "generated-documents/invoice/{$invoice->id}/issued.pdf";
        Storage::disk('local')->put($path, $bytes);

        GeneratedDocument::query()->create([
            'id' => (string) Str::uuid(),
            'document_type' => 'invoice',
            'document_id' => (string) $invoice->id,
            'template_version' => 1,
            'render_hash' => hash('sha256', $bytes),
            'output_format' => 'pdf',
            'disk' => 'local',
            'disk_path' => $path,
            'checksum' => hash('sha256', $bytes),
            'is_immutable' => true,
            'size_bytes' => strlen($bytes),
            'generated_at' => now(),
        ]);

        $link = $this->issueLink($this->customer);

        $response = $this->publicGet("/api/v1/customer-portal/{$link['token']}/invoices/{$invoice->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame($bytes, $response->getContent(), 'The portal must serve the issued (#613) copy, not a re-render.');
    }

    public function test_pay_returns_the_existing_payment_link(): void
    {
        $invoice = $this->postedInvoice($this->customer, 'INV-337-PAY', [
            'payment_link_url' => 'https://pay.example.test/checkout/abc',
            'payment_link_expires_at' => now()->addDays(3),
        ]);

        $link = $this->issueLink($this->customer);

        $response = $this->publicPost("/api/v1/customer-portal/{$link['token']}/invoices/{$invoice->id}/pay");

        $response->assertOk();
        $response->assertJsonPath('url', 'https://pay.example.test/checkout/abc');
    }

    public function test_pay_on_a_fully_paid_invoice_is_rejected(): void
    {
        $invoice = $this->postedInvoice($this->customer, 'INV-337-PAID', [
            'paid_amount' => 500,
            'balance' => 0,
            'status' => 'paid',
            'settlement_status' => 'paid',
        ]);

        $link = $this->issueLink($this->customer);

        $this->publicPost("/api/v1/customer-portal/{$link['token']}/invoices/{$invoice->id}/pay")
            ->assertStatus(422);
    }

    public function test_statement_is_this_customers_statement_only(): void
    {
        $this->postedInvoice($this->customer, 'INV-337-ST1', ['total' => 300, 'subtotal' => 300, 'balance' => 300]);
        $this->postedInvoice($this->otherCustomer, 'INV-337-ST-OTHER', ['total' => 999, 'subtotal' => 999, 'balance' => 999]);

        $link = $this->issueLink($this->customer);

        $response = $this->publicGet("/api/v1/customer-portal/{$link['token']}/statement");

        $response->assertOk();
        $this->assertEqualsWithDelta(300.0, (float) $response->json('closing_balance'), 0.001);

        $docs = collect($response->json('transactions'))->pluck('document_number')->filter()->values()->all();
        $this->assertNotContains('INV-337-ST-OTHER', $docs);
    }

    public function test_public_pay_route_is_rate_limited(): void
    {
        $link = $this->issueLink($this->customer);

        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->publicPost("/api/v1/customer-portal/{$link['token']}/invoices/999999/pay")->getStatusCode();
        }

        $this->assertSame(404, $statuses[0]);
        $this->assertSame(429, $statuses[10], 'The 11th pay request in the window must be throttled: '.implode(',', $statuses));
    }
}
