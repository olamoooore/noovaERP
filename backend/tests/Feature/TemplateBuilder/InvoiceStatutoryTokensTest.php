<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Services\DataSource\InvoiceDataSource;
use Modules\TemplateBuilder\Support\DefaultTemplates;
use Tests\TenantTestCase;

/**
 * Issue #614 (TP4) — a statutory invoice must be able to print the tax summary
 * per tax code/rate, the e-invoice reference (UUID, validation date, QR), the
 * amount in words and the seller's TIN / SST / GST registration numbers and
 * bank details, with labels resolved from the country pack.
 *
 * These are router-level tests (router → middleware → controller → data source
 * → renderer) so the tokens fail through the real HTTP render route.
 */
class InvoiceStatutoryTokensTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Statutory Tokens Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_posted_invoice_tax_summary_equals_the_posted_tax_lines(): void
    {
        $customer = Customer::factory()->create(['language' => 'en']);
        $product = Product::factory()->create(['track_inventory' => false, 'price' => 100, 'cost' => 60]);

        $created = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Statutory taxed line',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 9,
                'discount_amount' => 0,
            ]],
        ]);
        $created->assertCreated();

        $invoice = Invoice::findOrFail($created->json('id'));

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/post")
            ->assertOk();

        $posted = DB::table('tax_transaction_details')
            ->where('source_document_type', 'invoice')
            ->where('source_document_id', $invoice->id)
            ->get();

        $this->assertNotEmpty($posted, 'Posting the tax invoice must write tax_transaction_details rows.');

        $expected = $posted
            ->groupBy(fn ($row) => ((string) $row->tax_code).'|'.number_format((float) $row->tax_rate, 6))
            ->map(fn ($rows) => [
                'code' => (string) $rows->first()->tax_code,
                'rate' => (float) $rows->first()->tax_rate,
                'taxable' => round($rows->sum(fn ($row) => (float) $row->taxable_amount), 2),
                'tax' => round($rows->sum(fn ($row) => (float) $row->tax_amount), 2),
            ])
            ->values()
            ->all();

        $payload = (new InvoiceDataSource)->load((string) $invoice->id)->toArray();

        $this->assertSame(
            $expected,
            $payload['tax_summary'] ?? null,
            'tax_summary must equal the posted tax lines grouped by code + rate — never a recomputation from rates.',
        );

        $template = $this->probeTemplate(
            'TaxRow|{{ tax_summary.0.code }}|{{ tax_summary.0.rate }}|{{ tax_summary.0.taxable }}|{{ tax_summary.0.tax }}'
        );

        $response = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$invoice->id}/html?template={$template->id}");

        $response->assertOk();

        $this->assertStringContainsString(
            'TaxRow|'.$expected[0]['code'].'|'.$this->num($expected[0]['rate'])
                .'|'.$this->num($expected[0]['taxable']).'|'.$this->num($expected[0]['tax']),
            $response->getContent(),
        );
    }

    public function test_amount_in_words_uses_document_currency_and_language(): void
    {
        $template = $this->probeTemplate('Words:{{ totals.amount_in_words }}');

        $customerEn = Customer::factory()->create(['language' => 'en']);
        $invoiceEn = $this->invoiceWithTotal($customerEn, 8056.00, 'MYR');

        $responseEn = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$invoiceEn->id}/html?template={$template->id}");
        $responseEn->assertOk();
        $this->assertStringContainsString(
            'Words:Malaysian Ringgit Eight Thousand Fifty-Six Only',
            $responseEn->getContent(),
        );

        $customerMs = Customer::factory()->create(['language' => 'ms']);
        $invoiceMs = $this->invoiceWithTotal($customerMs, 8056.00, 'MYR');

        $responseMs = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$invoiceMs->id}/html?template={$template->id}");
        $responseMs->assertOk();
        $this->assertStringContainsString(
            'Words:Malaysian Ringgit Lapan Ribu Lima Puluh Enam Sahaja',
            $responseMs->getContent(),
        );
    }

    public function test_company_tin_and_sst_are_split_with_malaysian_pack_labels(): void
    {
        $entityId = $this->createEntity('STG-MY', 'MY', [
            'registration_number' => 'REG-MY-100',
            'tax_id' => 'TIN-MY-0001',
            'sst_registration_number' => 'SST-MY-9999',
            'bank_details' => json_encode([
                'bank_name' => 'Maybank',
                'account_name' => 'Statutory MY Sdn Bhd',
                'account_number' => '1234567890',
                'swift_code' => 'MBBEMYKL',
            ]),
        ]);

        $customer = Customer::factory()->create(['legal_entity_id' => $entityId, 'language' => 'en']);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'legal_entity_id' => $entityId,
            'currency_code' => 'MYR',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $template = $this->probeTemplate(implode('|', [
            '{{ company.tin }}',
            '{{ company.tin_label }}',
            '{{ company.sst_number }}',
            '{{ company.sst_number_label }}',
            '{{ company.gst_number }}',
            '{{ company.tax_number }}',
            '{{ company.tax_number_label }}',
            '{{ company.tax_regime }}',
            '{{ company.bank.name }}',
            '{{ company.bank.account_name }}',
            '{{ company.bank.account_number }}',
            '{{ company.bank.swift_code }}',
        ]));

        $response = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$invoice->id}/html?template={$template->id}");
        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('TIN-MY-0001', $content);
        $this->assertStringContainsString('Tax Identification No. (TIN)', $content);
        $this->assertStringContainsString('SST-MY-9999', $content);
        $this->assertStringContainsString('SST Registration No.', $content);
        $this->assertStringContainsString('|SST|', $content);
        $this->assertStringContainsString('Maybank|Statutory MY Sdn Bhd|1234567890|MBBEMYKL', $content);

        $this->assertStringNotContainsString(
            'Tax Identification No. (TIN)|TIN-MY-0001/SST-MY-9999',
            $content,
            'TIN and SST must never be collapsed into one value.',
        );
    }

    public function test_singapore_documents_use_the_singapore_pack_labels(): void
    {
        $entityId = $this->createEntity('STG-SG', 'SG', [
            'registration_number' => '201001000123',
            'tax_id' => 'UEN-SG-0001',
            'sst_registration_number' => 'GST-SG-8888',
        ]);

        $customer = Customer::factory()->create(['legal_entity_id' => $entityId, 'language' => 'en']);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'legal_entity_id' => $entityId,
            'currency_code' => 'SGD',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $template = $this->probeTemplate(implode('|', [
            '{{ company.tin_label }}',
            '{{ company.tin }}',
            '{{ company.gst_number_label }}',
            '{{ company.gst_number }}',
            '{{ company.tax_number_label }}',
            '{{ company.tax_regime }}',
        ]));

        $response = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$invoice->id}/html?template={$template->id}");
        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('Unique Entity Number (UEN)', $content);
        $this->assertStringContainsString('UEN-SG-0001', $content);
        $this->assertStringContainsString('GST Registration No.', $content);
        $this->assertStringContainsString('GST-SG-8888', $content);
        $this->assertStringContainsString('GST Registration No.|GST', $content);
    }

    public function test_einvoice_tokens_come_from_the_stored_compliance_submission(): void
    {
        $customer = Customer::factory()->create(['language' => 'en']);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'MYR',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $uuid = '0f8fad5b-d9cb-469f-a165-70867728950e';
        $link = 'https://myinvois.hasil.gov.my/'.$uuid.'/share/LONGID1';

        $document = ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'document_number' => $invoice->invoice_number,
            'issued_date' => now()->toDateString(),
            'total_amount' => $invoice->total,
            'currency_code' => 'MYR',
            'compliance_status' => 'accepted',
            'validation_link' => $link,
            'qr_payload' => $link,
        ]);

        ComplianceSubmission::create([
            'tenant_id' => $this->tenantId,
            'document_id' => $document->id,
            'country_code' => 'MY',
            'provider_name' => 'myinvoice',
            'submission_number' => 'MY-202610-000001',
            'provider_reference' => 'SUB-REF-1',
            'external_document_uuid' => $uuid,
            'validation_link' => $link,
            'submission_status' => 'accepted',
            'submitted_at' => '2026-10-04 09:00:00',
            'status_details' => [
                'document_summary' => ['dateTimeValidated' => '2026-10-04T10:30:00Z'],
            ],
        ]);

        $template = $this->probeBlockTemplate([
            ['type' => 'text', 'content' => 'EInv|{{ einvoice.uuid }}|{{ einvoice.validated_at }}|'],
            ['type' => 'image', 'url' => '{{ einvoice.qr }}', 'width' => 70, 'align' => 'left'],
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/api/v1/invoices/{$invoice->id}/html?template={$template->id}");
        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('EInv|'.$uuid.'|2026-10-04T10:30:00Z|', $content);
        $this->assertStringContainsString('data:image/', $content, 'The stored e-invoice payload must render as a QR image.');
    }

    public function test_einvoice_qr_and_validation_date_stay_empty_when_only_the_uuid_exists(): void
    {
        $customer = Customer::factory()->create(['language' => 'en']);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'MYR',
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);

        $uuid = '9b2c1f44-1111-4222-8333-abcdefabcdef';

        $document = ComplianceDocument::create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'MY',
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'document_number' => $invoice->invoice_number,
            'issued_date' => now()->toDateString(),
            'total_amount' => $invoice->total,
            'currency_code' => 'MYR',
            'compliance_status' => 'pending',
        ]);

        ComplianceSubmission::create([
            'tenant_id' => $this->tenantId,
            'document_id' => $document->id,
            'country_code' => 'MY',
            'provider_name' => 'myinvoice',
            'submission_number' => 'MY-202610-000002',
            'provider_reference' => 'SUB-REF-2',
            'external_document_uuid' => $uuid,
            'submission_status' => 'ack',
            'submitted_at' => '2026-10-04 09:00:00',
        ]);

        $payload = (new InvoiceDataSource)->load((string) $invoice->id)->toArray();

        $this->assertSame($uuid, $payload['einvoice']['uuid']);
        $this->assertSame('', $payload['einvoice']['qr'], 'Without a stored QR payload/link the QR image must stay empty.');
        $this->assertSame('', $payload['einvoice']['validated_at'], 'An unvalidated submission has no validation date.');
    }

    public function test_default_invoice_template_includes_the_statutory_tokens(): void
    {
        $json = json_encode(DefaultTemplates::invoice());

        foreach ([
            'tax_summary',
            'totals.amount_in_words',
            'company.tin',
            'company.sst_number',
            'company.gst_number',
            'company.bank.',
            'einvoice.uuid',
            'einvoice.validated_at',
            'einvoice.qr',
        ] as $token) {
            $this->assertStringContainsString($token, $json, "The default invoice template must include '{$token}'.");
        }
    }

    public function test_seeded_default_invoice_template_renders_statutory_sample_values(): void
    {
        app(DocumentTemplateServiceContract::class)->seedDefaults();

        $result = app(PdfGenerationServiceContract::class)->renderData(
            'invoice',
            (new InvoiceDataSource)->sample(),
            null,
            'html',
        );

        $html = $result['html'];

        $this->assertStringContainsString('Amount in Words', $html);
        $this->assertStringContainsString('Eight Thousand Fifty-Six', $html);
        $this->assertStringContainsString('SG-GST-6', $html);
        $this->assertStringContainsString('Maybank', $html);
    }

    private function invoiceWithTotal(Customer $customer, float $total, string $currency): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => $currency,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function createEntity(string $code, string $country, array $overrides = []): int
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => $code.' Group',
            'code' => $code.'-GROUP',
            'base_currency' => $country === 'SG' ? 'SGD' : 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('legal_entities')->insertGetId(array_merge([
            'group_id' => $groupId,
            'name' => $code.' Sdn Bhd',
            'code' => $code,
            'country_code' => $country,
            'base_currency' => $country === 'SG' ? 'SGD' : 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function probeTemplate(string $body): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => 'invoice',
            'name' => 'Statutory Probe '.Str::random(6),
            'mode' => DocumentTemplate::MODE_CODE,
            'html' => '<div class="probe">'.$body.'</div>',
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);

        $template->versions()->create([
            'template_id' => $template->id,
            'version' => 1,
            'html' => $template->html,
            'is_published' => true,
        ]);

        return $template;
    }

    /** @param array<int,array<string,mixed>> $blocks */
    private function probeBlockTemplate(array $blocks): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => 'invoice',
            'name' => 'Statutory Block Probe '.Str::random(6),
            'mode' => DocumentTemplate::MODE_BLOCK,
            'layout_config' => ['blocks' => $blocks],
            'status' => DocumentTemplate::STATUS_PUBLISHED,
            'published_version' => 1,
        ]);

        $template->versions()->create([
            'template_id' => $template->id,
            'version' => 1,
            'layout_config' => $template->layout_config,
            'is_published' => true,
        ]);

        return $template;
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
