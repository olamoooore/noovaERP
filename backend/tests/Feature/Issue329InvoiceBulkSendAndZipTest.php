<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerEmail;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TenantTestCase;
use ZipArchive;

/**
 * Issue #329 (Odoo 20 parity): from the invoice list a user can
 *   - Bulk Send the selected invoices (queued, with a per-invoice result), and
 *   - download one zip with each selected invoice's PDF plus its e-invoice
 *     XML/JSON where one exists.
 *
 * Every selected id is authorized on its own: the source permission and the
 * legal entity (the #611 render authorizer). A cross-entity id answers like a
 * missing one and never leaks into the result or the zip.
 *
 * Router-level tests (router -> middleware -> controller).
 */
class Issue329InvoiceBulkSendAndZipTest extends TenantTestCase
{
    private string $tenantId;

    private User $accountant;

    private User $cashier;

    private int $entityA;

    private int $entityB;

    private Customer $customerA;

    private Customer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Bulk Send Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('Accountant');

        $this->cashier = User::factory()->create();
        $this->cashier->assignRole('Cashier');

        foreach ([[$this->accountant, 'accountant'], [$this->cashier, 'cashier']] as [$user, $role]) {
            DB::table('user_company_access')->insert([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Bulk Send Group',
            'code' => 'BSG',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => 'BS-A',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => 'BS-B',
            'base_currency' => 'SGD',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Both users work in entity A only.
        foreach ([$this->accountant, $this->cashier] as $user) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $this->entityA,
                'role' => 'editor',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->customerA = Customer::factory()->create([
            'code' => 'BS-CUST-A',
            'email' => 'billing-a@acme.example.test',
            'legal_entity_id' => $this->entityA,
        ]);
        $this->customerB = Customer::factory()->create([
            'code' => 'BS-CUST-B',
            'email' => 'billing-b@other.example.test',
            'legal_entity_id' => $this->entityB,
        ]);
    }

    private function invoice(Customer $customer, string $number, array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'invoice_number' => $number,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'total' => 100,
            // No live balance: the send bundle skips the pay-now link, so no
            // payment gateway is involved in these tests.
            'balance' => 0,
            'currency_code' => null,
            'exchange_rate' => 1,
        ], $attributes));

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]);

        return $invoice;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resultsById(array $results): array
    {
        $byId = [];
        foreach ($results as $result) {
            $byId[(int) $result['id']] = $result;
        }

        return $byId;
    }

    public function test_bulk_send_queues_one_send_per_eligible_invoice_with_a_per_invoice_result(): void
    {
        Queue::fake();

        $sendable = $this->invoice($this->customerA, 'INV-BS-A-0001');
        $cancelled = $this->invoice($this->customerA, 'INV-BS-A-0002', ['status' => 'cancelled', 'lifecycle_status' => 'cancelled']);
        $unapprovedDraft = $this->invoice($this->customerA, 'INV-BS-A-0003', ['status' => 'draft', 'lifecycle_status' => 'draft', 'approval_status' => Invoice::APPROVAL_PENDING]);
        $otherEntity = $this->invoice($this->customerB, 'INV-BS-B-0001');
        $missingId = 99999999;

        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->postJson('/api/v1/invoices/bulk-send', [
                'ids' => [$sendable->id, $cancelled->id, $unapprovedDraft->id, $otherEntity->id, $missingId],
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.queued', 1);
        $response->assertJsonPath('data.skipped', 4);

        $results = $this->resultsById($response->json('data.results'));
        $this->assertCount(5, $results, 'Every selected id gets its own result.');

        $this->assertSame('queued', $results[$sendable->id]['outcome']);
        $this->assertSame('INV-BS-A-0001', $results[$sendable->id]['invoice_number']);

        $this->assertSame('skipped', $results[$cancelled->id]['outcome']);
        $this->assertSame('skipped', $results[$unapprovedDraft->id]['outcome']);

        // Cross-entity and non-existent ids are indistinguishable.
        $this->assertSame('skipped', $results[$otherEntity->id]['outcome']);
        $this->assertSame('skipped', $results[$missingId]['outcome']);
        $this->assertSame($results[$missingId]['reason'], $results[$otherEntity->id]['reason']);
        $this->assertArrayNotHasKey('invoice_number', $results[$otherEntity->id]);
        $this->assertStringNotContainsString('INV-BS-B-0001', $response->getContent());

        // One queued send, for the one eligible invoice only.
        Queue::assertPushed(\App\Jobs\SendInvoiceBundleJob::class, 1);
        Queue::assertPushed(
            \App\Jobs\SendInvoiceBundleJob::class,
            fn ($job) => $job->invoiceId === (int) $sendable->id && $job->userId === (int) $this->accountant->id,
        );
    }

    public function test_queued_bulk_send_delivers_through_the_send_bundle_path(): void
    {
        // Sync queue (phpunit.xml): the queued job runs and sends the same
        // single bundle email as the one-invoice Send button.
        Mail::fake();

        $first = $this->invoice($this->customerA, 'INV-BS-A-0101');
        $second = $this->invoice($this->customerA, 'INV-BS-A-0102');

        $this->actingAs($this->accountant)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->postJson('/api/v1/invoices/bulk-send', ['ids' => [$first->id, $second->id]])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 2);

        Mail::assertSent(CustomerEmail::class, 2);
        Mail::assertSent(CustomerEmail::class, fn (CustomerEmail $mail) => $mail->hasTo('billing-a@acme.example.test'));

        foreach ([$first, $second] as $invoice) {
            $delivery = InvoiceDelivery::where('invoice_id', $invoice->id)->sole();
            $this->assertSame(InvoiceDelivery::STATUS_SENT, $delivery->status);
            $this->assertSame((int) $this->accountant->id, (int) $delivery->sent_by);
            $this->assertSame(Invoice::DELIVERY_SENT, $invoice->fresh()->delivery_status);
        }
    }

    public function test_bulk_send_requires_the_invoice_send_permission(): void
    {
        Queue::fake();

        $invoice = $this->invoice($this->customerA, 'INV-BS-A-0201');

        // Cashier holds invoices.view but not invoices.send.
        $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->postJson('/api/v1/invoices/bulk-send', ['ids' => [$invoice->id]])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_bulk_download_zips_each_accessible_invoice_pdf_and_einvoice_payload(): void
    {
        $withEInvoice = $this->invoice($this->customerA, 'INV-BS-A-0301');
        $pdfOnly = $this->invoice($this->customerA, 'INV-BS-A-0302');
        $otherEntity = $this->invoice($this->customerB, 'INV-BS-B-0301');

        ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $withEInvoice->id,
            'document_number' => 'INV-BS-A-0301',
            'issued_date' => now(),
            'total_amount' => 100,
            'currency_code' => 'MYR',
            'mapped_payload' => ['Invoice' => [['ID' => [['_' => 'INV-BS-A-0301']]]]],
            'compliance_status' => 'valid',
        ]);

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->get('/api/v1/invoices/bulk-download?'.http_build_query([
                'ids' => [$withEInvoice->id, $pdfOnly->id, $otherEntity->id, 99999999],
            ]));

        $response->assertOk();
        $this->assertStringStartsWith('application/zip', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'bulk-zip-test-');
        file_put_contents($path, $response->getContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The response must be a readable zip.');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);

        $this->assertSame([
            'INV-BS-A-0301.einvoice.json',
            'INV-BS-A-0301.pdf',
            'INV-BS-A-0302.pdf',
            'manifest.txt',
        ], $names);

        $this->assertStringStartsWith('%PDF', (string) $zip->getFromName('INV-BS-A-0301.pdf'));
        $this->assertSame(
            ['Invoice' => [['ID' => [['_' => 'INV-BS-A-0301']]]]],
            json_decode((string) $zip->getFromName('INV-BS-A-0301.einvoice.json'), true),
        );

        // The manifest reports the skipped ids without telling a cross-entity
        // invoice apart from a missing one, and never names it.
        $manifest = (string) $zip->getFromName('manifest.txt');
        $this->assertStringContainsString((string) $otherEntity->id, $manifest);
        $this->assertStringContainsString('99999999', $manifest);
        $this->assertStringNotContainsString('INV-BS-B-0301', $manifest);

        $zip->close();
        @unlink($path);
    }

    public function test_bulk_download_with_no_accessible_invoice_is_refused(): void
    {
        $otherEntity = $this->invoice($this->customerB, 'INV-BS-B-0401');

        $this->actingAs($this->cashier)
            ->withHeader('X-Entity-Id', (string) $this->entityA)
            ->getJson('/api/v1/invoices/bulk-download?'.http_build_query(['ids' => [$otherEntity->id]]))
            ->assertStatus(403);
    }
}
