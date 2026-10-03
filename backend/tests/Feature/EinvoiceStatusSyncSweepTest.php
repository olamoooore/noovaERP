<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceEvent;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Accounting\CountryConfiguration;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Notifications\NotificationLog;
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
 * Issue #666 (EI4) — LHDN validation is asynchronous: a submission moves later
 * to Valid/Invalid and the buyer may request rejection within 72 hours. Before
 * this issue nothing polled for those changes, so an Invalid e-invoice or a
 * buyer rejection request went unnoticed until someone pressed sync.
 *
 * Contract asserted here (AC1 / AC2 / AC3):
 *  - AC3: a run of the scheduled job takes a pending submission, asks the
 *    (mocked) provider, and updates the submission to what the provider says;
 *  - AC2: a status change to Invalid or to rejection-requested notifies the
 *    invoice owner and the finance team, and the rejection request is visible
 *    on the document the invoice pages render;
 *  - AC1: the scheduled sweep polls submissions inside the window (under 72h
 *    every run, up to 7 days hourly-ish, never past 7 days) instead of
 *    hammering every pending submission of every tenant on every run.
 */
class EinvoiceStatusSyncSweepTest extends TenantTestCase
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
        \Illuminate\Support\Facades\Artisan::call('compliance:sync-einvoice-status');
    }

    private function notificationsFor(User $user): array
    {
        return NotificationLog::where('event', 'einvoice.status_changed')
            ->where('user_id', $user->id)
            ->get()
            ->all();
    }

    /**
     * AC3 — a job run takes a pending submission and updates it from the
     * mocked provider response, recording the sync as a compliance event.
     */
    public function test_job_run_updates_a_pending_submission_from_the_mocked_provider(): void
    {
        $invoice = $this->invoice();
        [, $submission] = $this->pendingSubmission($invoice);

        $this->fakeProvider([
            $this->tokenResponse(),
            new Response(200, [], json_encode([
                'overallStatus' => 'Valid',
                'documentSummary' => [['uuid' => self::PROVIDER_DOC_UUID, 'longId' => 'LHDN-1']],
            ])),
        ]);

        $this->runSweep();

        $submission->refresh();

        $this->assertSame(
            'accepted',
            $submission->submission_status,
            'the scheduled job run must update the pending submission from the provider response'
        );
        $this->assertNotNull($submission->last_status_check, 'a polled submission records when it was last checked');
        $this->assertSame(
            self::PROVIDER_DOC_UUID,
            $submission->external_document_uuid,
            'the provider UUID returned by the status call must be recorded'
        );

        $this->assertTrue(
            ComplianceEvent::where('submission_id', $submission->id)
                ->where('event_type', 'status_sync')
                ->exists(),
            'the status change must be recorded as a compliance event'
        );

        $document = $submission->document;
        $this->assertSame('accepted', $document->compliance_status, 'the linked document must show the new status');
    }

    /**
     * AC2 — an Invalid result notifies the invoice owner and the finance team.
     */
    public function test_invalid_result_notifies_the_invoice_owner_and_finance(): void
    {
        $invoice = $this->invoice();
        [, $submission] = $this->pendingSubmission($invoice);

        $this->fakeProvider([
            $this->tokenResponse(),
            new Response(200, [], json_encode([
                'overallStatus' => 'Invalid',
                'documentSummary' => [['uuid' => self::PROVIDER_DOC_UUID, 'longId' => 'LHDN-1']],
            ])),
            new Response(200, [], json_encode([
                'status' => 'Invalid',
                'longId' => 'LHDN-1',
                'errors' => [['message' => 'Buyer TIN is not registered.']],
            ])),
        ]);

        $this->runSweep();

        $submission->refresh();
        $this->assertSame('rejected', $submission->submission_status, 'Invalid maps to a rejected submission');

        $ownerNotifications = $this->notificationsFor($this->ownerUser);
        $this->assertCount(
            1,
            $ownerNotifications,
            'an Invalid result must notify the invoice owner'
        );

        $financeNotifications = $this->notificationsFor($this->financeUser);
        $this->assertCount(
            1,
            $financeNotifications,
            'an Invalid result must notify the finance team'
        );

        $payload = $financeNotifications[0]->data ?? [];
        $this->assertSame($invoice->id, $payload['invoice_id'] ?? null, 'the notification must name the invoice');
        $this->assertSame(
            'invoice',
            $payload['primary']['type'] ?? null,
            'the notification must carry a linkable invoice reference so the bell links to the invoice'
        );
    }

    /**
     * AC2 — a buyer rejection request notifies finance and is visible on the
     * document the e-invoice pages render.
     */
    public function test_buyer_rejection_request_notifies_and_shows_on_the_document(): void
    {
        $invoice = $this->invoice();
        [$document, $submission] = $this->pendingSubmission($invoice);

        $this->fakeProvider([
            $this->tokenResponse(),
            new Response(200, [], json_encode([
                'overallStatus' => 'Valid',
                'documentSummary' => [[
                    'uuid' => self::PROVIDER_DOC_UUID,
                    'longId' => 'LHDN-1',
                    'rejectRequestDateTime' => now()->toIso8601String(),
                ]],
            ])),
        ]);

        $this->runSweep();

        $submission->refresh();
        $this->assertSame('rejection_requested', $submission->submission_status);

        $financeNotifications = $this->notificationsFor($this->financeUser);
        $this->assertCount(
            1,
            $financeNotifications,
            'a buyer rejection request must notify the finance team'
        );

        $document->refresh();
        $this->assertSame(
            'rejection_requested',
            $document->compliance_status,
            'the rejection request must show on the document the e-invoice pages render'
        );
    }

    /**
     * AC1 — the scheduled sweep only polls submissions inside the polling
     * window: never past 7 days, and only when an over-72h-old submission is
     * due its hourly check. Out-of-window submissions are not queried at all
     * (no HTTP call → last_status_check stays null).
     */
    public function test_scheduled_sweep_skips_submissions_outside_the_polling_window(): void
    {
        $invoice = $this->invoice();

        // Past the 7-day window: never polled again.
        [, $stale] = $this->pendingSubmission($invoice, 'REF-STALE', now()->subDays(10));

        // 72h–7d old and checked 10 minutes ago: not due its hourly check yet.
        [, $recentlyChecked] = $this->pendingSubmission(
            $invoice,
            'REF-HOURLY',
            now()->subDays(4),
            now()->subMinutes(10),
        );

        // 72h–7d old and last checked 90 minutes ago: due its hourly check.
        [, $dueHourly] = $this->pendingSubmission(
            $invoice,
            'REF-DUE',
            now()->subDays(4),
            now()->subMinutes(90),
        );

        // Only the due submission may be queried: token + status response.
        $this->fakeProvider([
            $this->tokenResponse(),
            new Response(200, [], json_encode([
                'overallStatus' => 'Valid',
                'documentSummary' => [['uuid' => self::PROVIDER_DOC_UUID, 'longId' => 'LHDN-1']],
            ])),
        ]);

        $this->runSweep();

        $stale->refresh();
        $recentlyChecked->refresh();
        $dueHourly->refresh();

        $this->assertNull(
            $stale->last_status_check,
            'a submission older than 7 days must be skipped by the scheduled sweep'
        );
        $this->assertTrue(
            $recentlyChecked->last_status_check->lessThan(now()->subMinutes(5)),
            'a submission over 72h old must not be re-polled before its hourly check is due'
        );
        $this->assertSame(
            'accepted',
            $dueHourly->submission_status,
            'a 72h–7d-old submission whose hourly check is due must be polled'
        );
        $this->assertCount(2, $this->httpContainer, 'exactly the due submission is queried (token + status)');
    }
}
