<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Accounting\CountryConfiguration;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #666 (EI4) AC1 — "it respects LHDN rate limits".
 *
 * No published LHDN quota numbers exist in this repository, so none are
 * encoded. What the sweep must do is honour the provider's own throttling
 * answer: when the status endpoint replies HTTP 429 Too Many Requests, the
 * sweep stops calling it for that tenant/country instead of firing one more
 * request per pending submission (plus a second "probe" request each), and
 * resumes once the provider's Retry-After has passed.
 */
class EinvoiceStatusSyncRateLimitTest extends TenantTestCase
{
    private const PROVIDER_DOC_UUID = 'b1b2c3d4-0000-4000-8000-0000000000aa';

    private User $ownerUser;

    private User $financeUser;

    private string $tenantId;

    private array $httpContainer = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The sweep runs in-process (Artisan::call); keep any observer-dispatched
        // jobs from touching tenancy mid-test (see JournalEntryObserver →
        // UpdateDailyLedgerSummary, which ends tenancy on the sync queue).
        Queue::fake();
        Mail::fake();

        // A token fetched by an earlier test in this process would otherwise
        // survive in the array cache and desynchronise the mocked HTTP queue.
        \Illuminate\Support\Facades\Cache::flush();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady([
            'invoices',
            'invoice_lines',
            'customers',
            'acct_compliance_documents',
            'acct_compliance_submissions',
            'notification_logs',
        ]);

        $this->ownerUser = User::factory()->create();
        $this->financeUser = User::factory()->create();
        DB::table('user_company_access')->insert([
            'user_id' => $this->financeUser->id,
            'tenant_id' => $this->tenantId,
            'role' => 'accountant',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->configureMalaysia();
    }

    /**
     * Mock the provider's HTTP stack. Responses are consumed FIFO: the first
     * call is always the OAuth token request, then the submission-status GET
     * (and, for an Invalid document, the document-details GET).
     *
     * @param  array<int, Response>  $responses
     */
    private function fakeProvider(array $responses): void
    {
        $this->httpContainer = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->httpContainer));

        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    private function tokenResponse(): Response
    {
        return new Response(200, [], json_encode([
            'access_token' => 'test-token',
            'expires_in' => 3600,
            'scope' => 'InvoicingAPI',
        ]));
    }

    private function configureMalaysia(): void
    {
        // Written through the model: api_credentials is stored encrypted.
        CountryConfiguration::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'provider_name' => 'myinvoice',
            'is_enabled' => true,
            'test_mode' => true,
            'api_credentials' => [
                'myinvoice_client_id' => 'test-client-id',
                'myinvoice_client_secret' => 'test-client-secret',
                'supplier_tin' => 'C1234567890',
                'supplier_name' => 'NoovaERP Test Sdn Bhd',
            ],
            'settings' => ['auto_submit' => true, 'environment' => 'preprod'],
        ]);
    }

    private function invoice(): Invoice
    {
        $customer = Customer::factory()->create();

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'currency_code' => 'MYR',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->ownerUser->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 6,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        return $invoice->fresh();
    }

    /**
     * @return array{0: ComplianceDocument, 1: ComplianceSubmission}
     */
    private function pendingSubmission(
        Invoice $invoice,
        string $reference = 'REF-SYNC-1',
        ?\DateTimeInterface $submittedAt = null,
        ?\DateTimeInterface $lastStatusCheck = null,
    ): array {
        $document = ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'document_number' => $invoice->invoice_number,
            'issued_date' => $invoice->invoice_date,
            'total_amount' => $invoice->total,
            'currency_code' => 'MYR',
            'compliance_status' => 'pending',
            'created_by' => $this->ownerUser->id,
        ]);

        $submission = ComplianceSubmission::create([
            'tenant_id' => $this->tenantId,
            'document_id' => $document->id,
            'country_code' => 'MY',
            'submission_status' => 'ack',
            'provider_reference' => $reference,
            'external_document_uuid' => null,
            'submitted_at' => $submittedAt ?? now(),
            'submitted_by' => $this->ownerUser->id,
            'last_status_check' => $lastStatusCheck,
        ]);

        return [$document, $submission];
    }

    private function runSweep(): void
    {
        // The command initialises and ends tenancy per tenant; re-bind the
        // test tenant context so every run starts from the same state.
        $this->bindTenantContext($this->tenantId);
        \Illuminate\Support\Facades\Artisan::call('compliance:sync-einvoice-status');
    }

    private function throttled(int $retryAfterSeconds): Response
    {
        return new Response(429, ['Retry-After' => (string) $retryAfterSeconds], json_encode([
            'error' => 'Too Many Requests',
        ]));
    }

    public function test_a_429_from_the_provider_stops_the_sweep_until_retry_after_passes(): void
    {
        $invoice = $this->invoice();
        [, $first] = $this->pendingSubmission($invoice, 'REF-RL-1');
        [, $second] = $this->pendingSubmission($invoice, 'REF-RL-2');
        [, $third] = $this->pendingSubmission($invoice, 'REF-RL-3');

        // Plenty of 429s queued: the assertion is on how many the sweep uses.
        $this->fakeProvider(array_merge(
            [$this->tokenResponse()],
            array_fill(0, 10, $this->throttled(120)),
        ));

        $this->runSweep();

        $this->assertCount(
            2,
            $this->httpContainer,
            'after a 429 the sweep must stop calling the provider (token + the one throttled status call only)'
        );

        foreach ([$first, $second, $third] as $submission) {
            $this->assertSame('ack', $submission->fresh()->submission_status, 'a throttled poll must not change the submission');
        }

        // Inside the provider's Retry-After window the next run makes no call.
        $this->fakeProvider([$this->tokenResponse()]);
        $this->travel(60)->seconds();
        $this->runSweep();
        $statusCalls = array_filter($this->httpContainer, fn ($t) => ! str_contains((string) $t['request']->getUri(), '/connect/token'));
        $this->assertCount(0, $statusCalls, 'no provider call may be made before Retry-After has passed');

        // Once Retry-After has passed polling resumes and updates submissions.
        $valid = fn () => new Response(200, [], json_encode([
            'overallStatus' => 'Valid',
            'documentSummary' => [['uuid' => self::PROVIDER_DOC_UUID, 'longId' => 'LHDN-1']],
        ]));
        // Answer by URL: the token may or may not still be cached.
        $this->fakeProvider(array_fill(0, 8, fn ($request) => str_contains((string) $request->getUri(), '/connect/token')
            ? $this->tokenResponse()
            : $valid()));
        $this->travel(120)->seconds();
        $this->runSweep();

        foreach ([$first, $second, $third] as $submission) {
            $this->assertSame('accepted', $submission->fresh()->submission_status, 'polling must resume after Retry-After');
        }
    }
}
