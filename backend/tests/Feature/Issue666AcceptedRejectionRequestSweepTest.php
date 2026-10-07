<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #666 (EI4) — "buyer rejection requests go unnoticed".
 *
 * LHDN only lets the buyer reject a document that is already Valid, within
 * 72 hours of validation, and the document stays Valid while the request is
 * pending (MyInvois SDK, "Reject Document": "Rejection is permitted within the
 * 72-hour window from the date and time that the document was marked valid";
 * "a document is still considered 'Valid' until the Seller 'cancels' the
 * document"). The Get Submission documentSummary carries the request as
 * `rejectRequestDateTime`.
 *
 * So the realistic sequence is: sweep N sees Valid → the submission becomes
 * `accepted`; the buyer then requests rejection; sweep N+1 must still poll the
 * accepted submission to see `rejectRequestDateTime`. These tests drive that
 * sequence through the real scheduled command and read the result back
 * through the real compliance document route.
 */
class Issue666AcceptedRejectionRequestSweepTest extends TenantTestCase
{
    private const PROVIDER_DOC_UUID = 'c1c2c3c4-0000-4000-8000-0000000006aa';

    private User $ownerUser;

    private User $financeUser;

    private string $tenantId;

    private array $httpContainer = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        Cache::flush();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

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
        $this->financeUser->assignRole(UserRole::ACCOUNTANT->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->financeUser->id,
            'tenant_id' => $this->tenantId,
            'role' => UserRole::ACCOUNTANT->value,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    /**
     * Responses are consumed FIFO: OAuth token, then the Get Submission GET.
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

    private function validResponse(?string $rejectRequestDateTime = null): Response
    {
        $summary = [
            'uuid' => self::PROVIDER_DOC_UUID,
            'longId' => 'LHDN-666',
            'status' => 'Valid',
        ];
        if ($rejectRequestDateTime !== null) {
            $summary['rejectRequestDateTime'] = $rejectRequestDateTime;
        }

        return new Response(200, [], json_encode([
            'overallStatus' => 'Valid',
            'documentSummary' => [$summary],
        ]));
    }

    private function sweep(array $responses): void
    {
        // Token caching is not under test; give every sweep a fresh token.
        Cache::flush();
        // The command ends tenancy after each tenant. Without re-binding, the
        // next sweep's tenancy()->initialize() bootstraps a fresh DB
        // connection outside this test's transaction and cannot see the
        // (uncommitted) test rows — a harness artefact, not production
        // behaviour. Re-bind so every sweep starts in the state the first does.
        $this->bindTenantContext($this->tenantId);
        $this->fakeProvider($responses);
        Artisan::call('compliance:sync-einvoice-status');
    }

    /**
     * @return array{0: ComplianceDocument, 1: ComplianceSubmission}
     */
    private function submittedInvoice(string $status, \DateTimeInterface $submittedAt): array
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

        $document = ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'document_number' => $invoice->invoice_number,
            'issued_date' => $invoice->invoice_date,
            'total_amount' => $invoice->total,
            'currency_code' => 'MYR',
            'compliance_status' => $status === 'accepted' ? 'accepted' : 'pending',
            'created_by' => $this->ownerUser->id,
        ]);

        $submission = ComplianceSubmission::create([
            'tenant_id' => $this->tenantId,
            'document_id' => $document->id,
            'country_code' => 'MY',
            'submission_status' => $status,
            'provider_reference' => 'REF-666-'.Str::upper(Str::random(6)),
            'external_document_uuid' => $status === 'accepted' ? self::PROVIDER_DOC_UUID : null,
            'submitted_at' => $submittedAt,
            'submitted_by' => $this->ownerUser->id,
        ]);

        return [$document, $submission];
    }

    /**
     * The realistic order of events: Valid first, rejection request later.
     * The second sweep must still poll the now-accepted submission, notify
     * owner + finance, and the document route must show the request.
     */
    public function test_rejection_request_after_validation_is_picked_up_by_a_later_sweep(): void
    {
        [$document, $submission] = $this->submittedInvoice('ack', now()->subMinutes(10));

        // Sweep 1 — LHDN has validated the document; no rejection yet.
        $this->sweep([$this->tokenResponse(), $this->validResponse()]);
        $submission->refresh();
        $this->assertSame('accepted', $submission->submission_status, 'sweep 1: Valid maps to accepted');

        // Two hours later, inside the 72-hour window, the buyer requests rejection.
        $this->travel(2)->hours();
        $this->sweep([$this->tokenResponse(), $this->validResponse(now()->toIso8601String())]);

        $this->assertCount(
            2,
            $this->httpContainer,
            'sweep 2 must poll the accepted submission (token + Get Submission) — a rejection request can only arrive after Valid'
        );

        $submission->refresh();
        $this->assertSame('rejection_requested', $submission->submission_status);

        $this->assertCount(
            1,
            NotificationLog::where('event', 'einvoice.status_changed')->where('user_id', $this->financeUser->id)->get(),
            'finance must be notified of the buyer rejection request'
        );
        $this->assertCount(
            1,
            NotificationLog::where('event', 'einvoice.status_changed')->where('user_id', $this->ownerUser->id)->get(),
            'the invoice owner must be notified of the buyer rejection request'
        );

        // User-visible: the compliance document the invoice's e-invoice panel reads.
        $this->bindTenantContext($this->tenantId);
        $response = $this->actingAs($this->financeUser)
            ->getJson("/api/v1/accounting/compliance/documents/{$document->id}");
        $response->assertOk();
        $this->assertSame('rejection_requested', $response->json('data.compliance_status'));
        $this->assertSame('rejection_requested', $response->json('data.submissions.0.submission_status'));
    }

    /**
     * Polling accepted submissions stays bounded: an accepted submission well
     * past any rejection window (10 days old) is never polled, and one checked
     * within the last hour is not re-polled on every 10-minute sweep.
     */
    public function test_accepted_submissions_are_polled_only_inside_the_window(): void
    {
        [, $stale] = $this->submittedInvoice('accepted', now()->subDays(10));
        [, $recentlyChecked] = $this->submittedInvoice('accepted', now()->subHours(5));
        $recentlyChecked->update(['last_status_check' => now()->subMinutes(10)]);

        $this->sweep([]);

        $this->assertCount(0, $this->httpContainer, 'no provider call for out-of-window or recently checked accepted submissions');
        $this->assertNull($stale->fresh()->last_status_check);
    }

    /**
     * Re-polling an accepted submission whose LHDN answer has not changed is
     * a no-op: no duplicate acceptance event, no status_sync event, and no
     * notification — only last_status_check moves.
     */
    public function test_unchanged_accepted_repoll_records_nothing_new(): void
    {
        [, $submission] = $this->submittedInvoice('ack', now()->subMinutes(10));
        $this->sweep([$this->tokenResponse(), $this->validResponse()]);
        $this->assertSame('accepted', $submission->fresh()->submission_status);
        $eventsAfterAcceptance = ComplianceEvent::where('submission_id', $submission->id)->count();

        $this->travel(2)->hours();
        $this->sweep([$this->tokenResponse(), $this->validResponse()]);

        $this->assertCount(2, $this->httpContainer, 'the accepted submission is polled');
        $fresh = $submission->fresh();
        $this->assertSame('accepted', $fresh->submission_status);
        $this->assertTrue($fresh->last_status_check->greaterThan(now()->subMinute()));
        $this->assertSame(
            $eventsAfterAcceptance,
            ComplianceEvent::where('submission_id', $submission->id)->count(),
            'an unchanged Valid answer must not log another accepted/status_sync event'
        );
        $this->assertSame(0, NotificationLog::where('event', 'einvoice.status_changed')->count());
    }
}
