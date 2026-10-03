<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Features\ActivityLogging\Models\ActivityLogEntry;
use App\Http\Middleware\EnsureAccountingWriteAccess;
use App\Http\Middleware\RequirePermission;
use App\Mail\CustomerStatementMail;
use App\Models\AccountMapping;
use App\Models\Bill;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerStatement;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\MedicalCase;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #632 (SALES-CHAT, CH4) — chatter coverage gaps.
 *
 *  - CustomerCreditNote, MedicalCase and CustomerStatement were not chatter
 *    subjects, so their lifecycle steps left no trail and could not be
 *    discussed (POST /api/v1/chatter/... answered 422 "Invalid subject type").
 *  - Quotation chatter was mapped to sales.view while the quotation routes
 *    are gated by quotations.view (which additionally includes ACCOUNTANT and
 *    CASHIER), so an accountant who can open a quotation got 403 on its
 *    chatter.
 *
 * This suite exercises the live router → middleware → controller chain and
 * asserts the configured chatter permission equals the permission string on
 * the route that actually opens each record (read from the router, not
 * re-read from config).
 */
class Sales632ChatterCoverageTest extends TenantTestCase
{
    private string $tenantId;

    private User $accountant;

    private User $sales;

    private User $cashier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Mail::fake();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);
        $this->sales = $this->makeUser('sales', UserRole::SALES);
        $this->cashier = $this->makeUser('cashier', UserRole::CASHIER);

        $this->customer = Customer::factory()->create([
            'name' => 'Issue 632 Customer',
            'email' => 'statements-632@example.com',
        ]);

        $revenue = ChartOfAccount::factory()->create(['code' => '3000-632', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
        $ar = ChartOfAccount::factory()->create(['code' => '1100-632', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
        $tax = ChartOfAccount::factory()->create(['code' => '2200-632', 'name' => 'Tax Payable', 'type' => 'liability', 'is_active' => true]);

        AccountMapping::create(['mapping_key' => 'sales_revenue', 'account_id' => $revenue->id]);
        AccountMapping::create(['mapping_key' => 'accounts_receivable', 'account_id' => $ar->id]);
        AccountMapping::create(['mapping_key' => 'sales_tax_payable', 'account_id' => $tax->id]);
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

    private function chatterUrl(string $subjectType, int $subjectId, string $suffix = ''): string
    {
        return '/api/v1/chatter/'.rawurlencode($subjectType)."/{$subjectId}{$suffix}";
    }

    private function makeQuotation(): Quotation
    {
        return Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'created_by' => $this->sales->id,
        ]);
    }

    private function makeCreditNote(): CustomerCreditNote
    {
        [$invoice] = $this->invoice();

        return CustomerCreditNote::create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 50,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 50,
            'reason' => 'Issue #632 chatter coverage',
            'created_by' => $this->accountant->id,
        ]);
    }

    private function makeMedicalCase(): MedicalCase
    {
        return MedicalCase::create([
            'customer_id' => $this->customer->id,
            'case_date' => now()->toDateString(),
            'created_by' => $this->sales->id,
        ]);
    }

    private function makeStatement(): CustomerStatement
    {
        return CustomerStatement::create([
            'customer_id' => $this->customer->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'period_type' => 'custom',
            'include_paid' => false,
            'generated_by' => $this->accountant->id,
            'payload' => ['transactions' => []],
        ]);
    }

    private function invoice(float $total = 500.0): array
    {
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->accountant->id,
        ]);

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Issue #632 Widget',
            'quantity' => 1,
            'unit_price' => $total,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);

        $invoice->calculateTotals();

        return [$invoice->fresh(), $line];
    }

    private function createCreditNoteViaRoute(): CustomerCreditNote
    {
        [$invoice, $line] = $this->invoice();

        $response = $this->actingAs($this->accountant)->postJson('/api/v1/accounting/customers/credit-notes', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'Issue #632 lifecycle',
            'lines' => [[
                'invoice_line_id' => $line->id,
                'description' => $line->description,
                'quantity' => 1,
                'unit_price' => $line->unit_price,
                'tax_rate' => 0,
            ]],
        ]);

        $response->assertCreated();

        return CustomerCreditNote::findOrFail($response->json('data.id'));
    }

    private function assertChatterEvent(string $subjectType, int $subjectId, string $event, string $descriptionPrefix): void
    {
        $found = ActivityLogEntry::query()
            ->where('log_name', 'chatter')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('event', $event)
            ->where('description', 'like', $descriptionPrefix.'%')
            ->count();

        $this->assertSame(
            1,
            $found,
            "chatter must contain one '{$event}' entry starting with '{$descriptionPrefix}' for {$subjectType}#{$subjectId}"
        );
    }

    private function findRoute(string $method, string $uri): ?RoutingRoute
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * The first RequirePermission permission string on the route, or null.
     */
    private function routePermission(string $method, string $uri): ?string
    {
        $route = $this->findRoute($method, $uri);
        if (! $route) {
            return null;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, RequirePermission::class.':')) {
                return substr($middleware, strlen(RequirePermission::class) + 1);
            }
        }

        return null;
    }

    // ─── CH4: accountant can open quotations -> must read their chatter ──

    public function test_accountant_can_read_quotation_chatter(): void
    {
        $quotation = $this->makeQuotation();

        $this->actingAs($this->accountant)
            ->getJson($this->chatterUrl(Quotation::class, $quotation->id))
            ->assertOk();
    }

    // ─── New subjects are no longer rejected as invalid subject types ────

    public function test_accountant_can_read_customer_credit_note_chatter(): void
    {
        $creditNote = $this->makeCreditNote();

        $this->actingAs($this->accountant)
            ->getJson($this->chatterUrl(CustomerCreditNote::class, $creditNote->id))
            ->assertOk();
    }

    public function test_sales_cannot_read_customer_credit_note_chatter(): void
    {
        $creditNote = $this->makeCreditNote();

        // Credit note reads require accounting.view; Sales does not hold it
        // (and cannot open the credit note route either).
        $this->actingAs($this->sales)
            ->getJson($this->chatterUrl(CustomerCreditNote::class, $creditNote->id))
            ->assertForbidden();
    }

    public function test_accountant_can_post_a_message_on_a_credit_note(): void
    {
        $creditNote = $this->makeCreditNote();

        $response = $this->actingAs($this->accountant)->postJson(
            $this->chatterUrl(CustomerCreditNote::class, $creditNote->id, '/messages'),
            ['body' => 'Please review this credit note.']
        );

        $response->assertCreated();
        $response->assertJsonPath('data.body', 'Please review this credit note.');
    }

    public function test_viewer_without_accounting_write_cannot_post_on_a_credit_note(): void
    {
        // A view-only role holds accounting.view but not accounting.write —
        // the gate on the credit note's mutating routes — so chatter writes
        // must refuse even though the record is readable.
        $viewer = $this->makeUser('viewer', UserRole::VIEWER);
        $creditNote = $this->makeCreditNote();

        $this->actingAs($viewer)->postJson(
            $this->chatterUrl(CustomerCreditNote::class, $creditNote->id, '/messages'),
            ['body' => 'A viewer must not be able to post here.']
        )->assertForbidden();
    }

    public function test_sales_can_read_medical_case_chatter(): void
    {
        $case = $this->makeMedicalCase();

        $this->actingAs($this->sales)
            ->getJson($this->chatterUrl(MedicalCase::class, $case->id))
            ->assertOk();
    }

    public function test_accountant_can_read_customer_statement_chatter(): void
    {
        $statement = $this->makeStatement();

        $this->actingAs($this->accountant)
            ->getJson($this->chatterUrl(CustomerStatement::class, $statement->id))
            ->assertOk();
    }

    public function test_cashier_cannot_read_customer_statement_chatter(): void
    {
        $statement = $this->makeStatement();

        // Statement reads are gated by customers.view; Cashier does not hold
        // it, so chatter must refuse too.
        $this->actingAs($this->cashier)
            ->getJson($this->chatterUrl(CustomerStatement::class, $statement->id))
            ->assertForbidden();
    }

    // ─── Lifecycle trail (credit note post/void, statement generate/send) ─

    public function test_posting_a_credit_note_logs_a_chatter_event(): void
    {
        $creditNote = $this->createCreditNoteViaRoute();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
            ->assertOk();

        $this->assertChatterEvent(CustomerCreditNote::class, $creditNote->id, 'status_changed', 'Posted credit note');
    }

    public function test_voiding_a_credit_note_logs_a_chatter_event(): void
    {
        $creditNote = $this->createCreditNoteViaRoute();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
            ->assertOk();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/void")
            ->assertOk();

        $this->assertChatterEvent(CustomerCreditNote::class, $creditNote->id, 'status_changed', 'Voided credit note');
    }

    public function test_generating_a_statement_logs_a_chatter_event(): void
    {
        $response = $this->actingAs($this->accountant)->postJson(
            "/api/v1/accounting/customers/{$this->customer->id}/statement",
            ['start_date' => '2026-01-01', 'end_date' => '2026-01-31']
        );
        $response->assertOk();

        $statementId = (int) $response->json('id');
        $this->assertChatterEvent(CustomerStatement::class, $statementId, 'generated', 'Generated statement');
    }

    public function test_sending_a_statement_logs_a_chatter_event(): void
    {
        $response = $this->actingAs($this->accountant)->postJson(
            "/api/v1/accounting/customers/{$this->customer->id}/statement",
            ['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'send_email' => true]
        );
        $response->assertOk();

        Mail::assertSent(CustomerStatementMail::class);

        $statementId = (int) $response->json('id');
        $this->assertChatterEvent(CustomerStatement::class, $statementId, 'generated', 'Generated statement');
        $this->assertChatterEvent(CustomerStatement::class, $statementId, 'sent', 'Sent statement');
    }

    public function test_rendering_the_statement_of_account_logs_a_chatter_event(): void
    {
        // The SPA's "Generate statement" action uses GET .../{customer}/statement
        // (format=json for exports); that path persists a CustomerStatement too
        // and must leave the same trail.
        $response = $this->actingAs($this->accountant)->getJson(
            "/api/v1/accounting/customers/{$this->customer->id}/statement?as_of=2026-01-31&format=json"
        );
        $response->assertOk();

        $statementId = (int) $response->json('statement_id');
        $this->assertChatterEvent(CustomerStatement::class, $statementId, 'generated', 'Generated statement');
    }

    // ─── Mapping contract: chatter permission == route gate ──────────────

    /**
     * Acceptance #632: each subject's chatter permission equals the permission
     * that gates opening that record. Read from the router's real middleware —
     * a config-only assertion would not catch the quotation drift.
     *
     * Bill / JournalEntry / Vendor are intentionally absent: their open routes
     * (/accounting/vendor-bills/{id}, /accounting/journal-entries/{id},
     * /accounting/vendors/{id}) carry no explicit RequirePermission string on
     * main, so there is no route gate to compare against; their chatter map
     * stays at accounting.view (at least as strict as the ungated route).
     * The completeness assertion below forces a decision whenever a new
     * subject is added.
     */
    public function test_chatter_view_permissions_match_the_routes_that_open_each_record(): void
    {
        $openRoutes = [
            Invoice::class => ['GET', 'api/v1/invoices/{invoice}'],
            Quotation::class => ['GET', 'api/v1/quotations/{quotation}'],
            Payment::class => ['GET', 'api/v1/payments/{payment}'],
            Customer::class => ['GET', 'api/v1/accounting/customers/{id}'],
            SalesOrder::class => ['GET', 'api/v1/sales/orders/{order}'],
            DeliveryOrder::class => ['GET', 'api/v1/sales/delivery-orders/{delivery_order}'],
            CustomerCreditNote::class => ['GET', 'api/v1/accounting/customers/credit-notes/{creditNote}'],
            MedicalCase::class => ['GET', 'api/v1/medical-cases/{medical_case}'],
            CustomerStatement::class => ['GET', 'api/v1/accounting/customers/{customer}/statement'],
        ];

        $allowed = config('chatter.allowed_models', []);

        $this->assertSame(
            [],
            array_diff($allowed, array_keys($openRoutes), [Bill::class, JournalEntry::class, Vendor::class]),
            'A chatter subject was added without a gated open route in this mapping test.'
        );

        foreach ($openRoutes as $model => [$method, $uri]) {
            $this->assertContains($model, $allowed, "{$model} must be a whitelisted chatter subject.");

            $routePermission = $this->routePermission($method, $uri);
            $this->assertNotNull($routePermission, "No RequirePermission gate found on {$method} {$uri}.");

            $this->assertSame(
                $routePermission,
                config('chatter.subject_permissions.'.$model),
                "{$model} chatter view permission must equal the {$method} {$uri} route gate."
            );
        }
    }

    public function test_chatter_write_permissions_match_the_routes_that_mutate_each_record(): void
    {
        $mutatingRoutes = [
            Invoice::class => ['PUT', 'api/v1/invoices/{invoice}'],
            Quotation::class => ['PUT', 'api/v1/quotations/{quotation}'],
            SalesOrder::class => ['PUT', 'api/v1/sales/orders/{order}'],
            CustomerCreditNote::class => ['PUT', 'api/v1/accounting/customers/credit-notes/{creditNote}'],
            MedicalCase::class => ['PUT', 'api/v1/medical-cases/{medical_case}'],
        ];

        foreach ($mutatingRoutes as $model => [$method, $uri]) {
            $routePermission = $this->routePermission($method, $uri);
            $this->assertNotNull($routePermission, "No RequirePermission gate found on {$method} {$uri}.");

            $this->assertSame(
                $routePermission,
                config('chatter.subject_write_permissions.'.$model),
                "{$model} chatter write permission must equal the {$method} {$uri} route gate."
            );
        }
    }

    public function test_customer_statement_chatter_write_follows_the_generation_gate(): void
    {
        // Statements have no update/delete route; the only mutation is
        // generation, gated by the accounting write middleware (no literal
        // permission string), so the write mapping mirrors accounting.write.
        $route = $this->findRoute('POST', 'api/v1/accounting/customers/{customer}/statement');
        $this->assertNotNull($route, 'The statement generation route must exist.');
        $this->assertContains(
            EnsureAccountingWriteAccess::class,
            $route->gatherMiddleware(),
            'Statement generation must be gated by EnsureAccountingWriteAccess.'
        );

        $this->assertSame('accounting.write', config('chatter.subject_write_permissions.'.CustomerStatement::class));
    }
}
