<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerCreditNote;
use App\Models\User;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #542 (ST5): document-number formats were saved with only
 * string|max:100. A format without {SEQ:n} made every document share one
 * number (the collision loop from ST1); unknown tokens stayed as literal text.
 * The endpoint now rejects invalid formats with 422, all six sales documents
 * are editable, and the response previews the next number.
 */
class DocumentNumberFormatGuardTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Numbering Validation Tenant');
    }

    private function putFormats(array $payload)
    {
        return $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/document-formats', $payload);
    }

    public function test_a_format_without_a_sequence_token_is_rejected(): void
    {
        $response = $this->putFormats(['invoice' => 'INV-{YYYY}']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('invoice', $response->json('errors'));

        // Nothing was saved — the invoice format stays the default.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/settings/document-formats')
            ->assertOk()
            ->assertJsonPath('invoice.current', 'INV-{YYYY}-{SEQ:4}');
    }

    public function test_unknown_tokens_and_out_of_range_sequence_widths_are_rejected(): void
    {
        $this->putFormats(['invoice' => 'INV-{SEQUENCE}-{SEQ:4}'])->assertStatus(422);
        $this->putFormats(['invoice' => 'INV-{YYYY}-{SEQ:2}'])->assertStatus(422);
        $this->putFormats(['invoice' => 'INV-{YYYY}-{SEQ:9}'])->assertStatus(422);
    }

    public function test_a_valid_format_is_saved_and_previewed(): void
    {
        $response = $this->putFormats(['invoice' => 'SINV/{YYYY}/{SEQ:5}']);

        $response->assertOk();
        $response->assertJsonPath('formats.invoice.current', 'SINV/{YYYY}/{SEQ:5}');

        $preview = $response->json('formats.invoice.preview');
        $this->assertMatchesRegularExpression('/^SINV\/\d{4}\/\d{5}$/', (string) $preview, 'the next number is previewed');
        $this->assertStringEndsWith('00001', (string) $preview, 'the preview is the NEXT number, without consuming it');
    }

    public function test_all_six_documents_are_listed_and_the_sales_order_and_credit_note_formats_apply(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/settings/document-formats')
            ->assertOk()
            ->assertJsonStructure([
                'invoice' => ['current', 'default', 'preview'],
                'quotation' => ['current', 'default', 'preview'],
                'sales_order' => ['current', 'default', 'preview'],
                'delivery_order' => ['current', 'default', 'preview'],
                'credit_note' => ['current', 'default', 'preview'],
                'payment' => ['current', 'default', 'preview'],
            ]);

        $this->putFormats([
            'sales_order' => 'SON-{YY}-{SEQ:4}',
            'credit_note' => 'CRN-{YYYY}-{SEQ:5}',
        ])->assertOk();

        $this->assertSame('SON-'.now()->format('y').'-0001', SalesOrder::generateOrderNumber());
        $this->assertSame('CRN-'.now()->format('Y').'-00001', CustomerCreditNote::generateCreditNoteNumber());
    }
}
