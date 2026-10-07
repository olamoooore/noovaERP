<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerEmail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Modules\PaymentGateway\Services\PaymentService;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Tests\TenantTestCase;

/**
 * Issue #345: the one-click Send bundle must say which parts it left out and
 * why (acceptance turned off, draft / zero balance so no pay link, payment
 * link creation failed) instead of only logging it — through the real
 * POST /invoices/{id}/send-bundle route.
 */
class Issue345SendBundleSkippedPartsTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Send Bundle Skipped Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('public.user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(DocumentTemplateServiceContract::class)->seedDefaults();
        Mail::fake();
    }

    private function invoice(array $attributes = []): Invoice
    {
        $customer = Customer::factory()->create([
            'legal_entity_id' => null,
            'email' => 'billing@acme.example.test',
        ]);

        return Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'status' => 'posted',
            'total' => 250,
            'balance' => 250,
            'currency_code' => null,
            'exchange_rate' => 1,
        ], $attributes));
    }

    private function gatewayReturns(string $url): void
    {
        $gateway = Mockery::mock(PaymentService::class);
        $gateway->shouldReceive('createIntent')->andReturn([
            'payment_url' => $url,
            'transaction_id' => 'txn-345',
        ]);
        $this->app->instance(PaymentService::class, $gateway);
    }

    private function sendBundle(Invoice $invoice)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/send-bundle")
            ->assertCreated();
    }

    /** @return array<string, string> part => reason */
    private function skippedReasons($response): array
    {
        $skipped = $response->json('data.skipped');
        $this->assertIsArray($skipped, 'The response must list skipped parts.');

        $reasons = [];
        foreach ($skipped as $entry) {
            $this->assertNotEmpty($entry['message'] ?? null, 'Each skipped part carries a readable message.');
            $reasons[$entry['part']] = $entry['reason'];
        }

        return $reasons;
    }

    public function test_full_bundle_skips_nothing(): void
    {
        $this->gatewayReturns('https://pay.test/checkout/full');

        $response = $this->sendBundle($this->invoice());

        $this->assertSame([], $this->skippedReasons($response));
        $this->assertSame('Invoice sent with acceptance and pay-now links', $response->json('message'));
    }

    public function test_acceptance_disabled_is_reported_and_not_claimed(): void
    {
        Setting::setValue('sales.portal.acceptance_enabled', false);
        $this->gatewayReturns('https://pay.test/checkout/no-acceptance');

        $response = $this->sendBundle($this->invoice());

        $this->assertSame(['acceptance_link' => 'acceptance_disabled'], $this->skippedReasons($response));
        $this->assertNull($response->json('data.url'));
        $this->assertSame('https://pay.test/checkout/no-acceptance', $response->json('data.payment_url'));
        $this->assertStringNotContainsString('acceptance', strtolower((string) $response->json('message')));
    }

    public function test_draft_reports_the_missing_pay_link(): void
    {
        $response = $this->sendBundle($this->invoice(['status' => 'draft', 'approval_status' => 'approved']));

        $this->assertSame(['payment_link' => 'draft'], $this->skippedReasons($response));
        $this->assertNull($response->json('data.payment_url'));
    }

    public function test_zero_balance_reports_the_missing_pay_link(): void
    {
        $response = $this->sendBundle($this->invoice(['balance' => 0]));

        $this->assertSame(['payment_link' => 'zero_balance'], $this->skippedReasons($response));
    }

    public function test_payment_link_failure_is_logged_and_reported(): void
    {
        Log::spy();
        $gateway = Mockery::mock(PaymentService::class);
        $gateway->shouldReceive('createIntent')->andThrow(new \RuntimeException('Gateway down'));
        $this->app->instance(PaymentService::class, $gateway);

        $response = $this->sendBundle($this->invoice());

        $this->assertSame(['payment_link' => 'payment_link_failed'], $this->skippedReasons($response));
        $this->assertNull($response->json('data.payment_url'));
        $this->assertSame('sent', $response->json('data.delivery.status'));
        Mail::assertSentCount(1);
        Mail::assertSent(CustomerEmail::class);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'payment link creation failed'))
            ->once();
    }
}
