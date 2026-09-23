<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Communication\Events\BusinessEvent;
use Tests\TenantTestCase;

/**
 * Issue #84 — Communication Module has zero business-logic integration.
 *
 * The platform ships a fully-featured Communication module (outbox, channels,
 * subscribers, preferences) but no module fires messages through it. This
 * test verifies the two-way integration we wired in:
 *
 *   1. \`InvoiceDeliveryService::deliver\` dispatches a \`BusinessEvent\` so
 *      the Communication engine (and any subscriber preferences) can act.
 *   2. \`InvoiceApprovalService::submit\` dispatches a \`BusinessEvent\` so
 *      approval submissions surface in chatter.
 *
 * We use Event::fake() and assert the dispatched event shape, since
 * Communication subscriber dispatching is queue-backed (the subscriber
 * itself is covered by the module's own tests).
 */
class CommunicationIntegrationTest extends TenantTestCase
{
    protected User $user;
    protected Customer $customer;
    protected Product $product;
    protected Warehouse $warehouse;
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });
        $this->bindTenantContext($tenant->id);
        $this->tenantId = $tenant->id;

        $this->assertTenantSchemaReady([
            'customers', 'products', 'invoices', 'invoice_lines',
        ]);

        $this->user = User::create([
            'name' => 'Comms Tester',
            'email' => 'comms-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Comms Customer',
            'payment_terms' => 30,
            'billing_address' => 'Comms Billing',
            'shipping_address' => 'Comms Shipping',
            'email' => 'comms-customer@example.com',
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Comms Widget',
            'sku' => 'COMM-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Comms Warehouse',
            'code' => 'COMM-'.uniqid(),
            'is_active' => true,
        ]);

        // The InvoiceDeliveryService calls a missing TemplateBuilder service
        // to render the PDF. Provide a minimal in-memory stand-in so the test
        // exercises the wiring we care about (the BusinessEvent dispatch).
        $this->app->bind(
            \Modules\TemplateBuilder\Services\DocumentRenderService::class,
            function () {
                return new class {
                    public function render($invoice, $documentType): string
                    {
                        return "PDF for {$invoice->invoice_number} ({$documentType})";
                    }
                };
            }
        );

        Mail::fake();
    }

    private function makeDraftInvoice(): \App\Models\Invoice
    {
        $invoice = \App\Models\Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'requires_approval' => false,
            'created_by' => $this->user->id,
        ]);
        \App\Models\InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 100,
        ]);
        return $invoice;
    }

    public function test_invoice_delivery_dispatches_communication_business_event(): void
    {
        Event::fake([BusinessEvent::class]);

        $invoice = $this->makeDraftInvoice();
        $invoice->update([
            'requires_approval' => true,
            'approval_status' => \App\Models\Invoice::APPROVAL_PENDING,
        ]);

        // Call the service directly rather than through the controller, so the
        // test does not depend on the full delivery pipeline (PDF render,
        // mail transport, etc.). The wiring we care about — the BusinessEvent
        // dispatch at the end of `deliver()` — runs after the persistence
        // step that this test exercises.
        $svc = app(\App\Services\InvoiceDeliveryService::class);
        $svc->deliver($invoice, 'email', $this->user, [
            'document_type' => 'acceptance_copy',
            'recipient' => $this->customer->email,
        ]);

        Event::assertDispatched(BusinessEvent::class, function (BusinessEvent $event) use ($invoice) {
            return $event->model === 'invoice'
                && $event->recordId === $invoice->id
                && $event->event === 'invoice.delivered'
                && str_contains($event->subject, $invoice->invoice_number)
                && $event->authorId === $this->user->id
                && ($event->context['channel'] ?? null) === 'email'
                && ($event->metadata['source'] ?? null) === 'InvoiceDeliveryService::deliver';
        });
    }

    public function test_invoice_approval_submission_dispatches_communication_business_event(): void
    {
        Event::fake([BusinessEvent::class]);

        $invoice = $this->makeDraftInvoice();
        $invoice->update(['requires_approval' => true]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoice->id}/submit-approval", [
                'reason' => 'Submitting for review',
            ])
            ->assertOk();

        Event::assertDispatched(BusinessEvent::class, function (BusinessEvent $event) use ($invoice) {
            return $event->model === 'invoice'
                && $event->recordId === $invoice->id
                && $event->event === 'invoice.approval_submitted'
                && str_contains($event->subject, $invoice->invoice_number)
                && $event->authorId === $this->user->id
                && ($event->metadata['source'] ?? null) === 'InvoiceApprovalService::submit';
        });
    }
}
