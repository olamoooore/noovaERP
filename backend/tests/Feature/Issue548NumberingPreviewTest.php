<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Platform\Numbering\NumberingSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #548 (SET-UX4): the numbering editor shows the NEXT number from the
 * server and lets the admin choose the reset period (Never / Yearly /
 * Monthly) and move the next sequence value forward.
 *
 * Decision on #538 (2026-10-02): "The sequence reset period is an explicit
 * setting per document type (Never / Yearly / Monthly), not inferred from the
 * number format's tokens. Implement it in the single platform numbering
 * service (#899)."
 *
 * Every preview comes from the same NumberingService that issues the real
 * numbers, through a read-only peek that never consumes the counter.
 */
class Issue548NumberingPreviewTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Numbering Preview Tenant');
        $this->customer = Customer::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function getFormats()
    {
        return $this->actingAs($this->admin)->getJson('/api/v1/settings/document-formats');
    }

    private function putFormats(array $payload)
    {
        return $this->actingAs($this->admin)->putJson('/api/v1/settings/document-formats', $payload);
    }

    private function insertIssuedInvoice(string $number): void
    {
        DB::table('invoices')->insert([
            'invoice_number' => $number,
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'balance' => 0,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function invoiceCounter(): ?int
    {
        $row = NumberingSequence::where('document_type', 'invoice')->first();

        return $row === null ? null : (int) $row->current_number;
    }

    public function test_preview_is_the_next_real_number_and_previewing_does_not_consume_it(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');

        // Imported / legacy numbers the counter has not seen yet: the real
        // generator jumps past them, so the preview must too.
        $this->insertIssuedInvoice('INV-2026-0007');
        $this->insertIssuedInvoice('INV-2026-0008');

        $counterBefore = $this->invoiceCounter();

        $first = $this->getFormats()->assertOk()->json('invoice.preview');
        $second = $this->getFormats()->assertOk()->json('invoice.preview');

        $this->assertSame($first, $second, 'previewing twice gives the same number');
        $this->assertSame($counterBefore, $this->invoiceCounter(), 'previewing must not advance the sequence');

        $issued = Invoice::generateInvoiceNumber();

        $this->assertSame('INV-2026-0009', $issued);
        $this->assertSame($issued, $first, 'the preview equals the next real number issued');

        // The live-preview endpoint answers from the same peek.
        $live = $this->actingAs($this->admin)
            ->getJson('/api/v1/settings/document-formats/preview?type=invoice')
            ->assertOk()
            ->json('preview');
        $this->assertSame('INV-2026-0010', $live);

        // After issuing, the preview moves to the following number.
        $this->assertSame('INV-2026-0010', $this->getFormats()->json('invoice.preview'));
    }

    public function test_live_preview_renders_an_unsaved_format_and_reports_validation_errors(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');

        $this->actingAs($this->admin)
            ->getJson('/api/v1/settings/document-formats/preview?'.http_build_query([
                'type' => 'invoice',
                'format' => 'SINV/{YY}/{MM}/{SEQ:5}',
            ]))
            ->assertOk()
            ->assertJsonPath('preview', 'SINV/26/03/00001')
            ->assertJsonPath('valid', true);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/settings/document-formats/preview?'.http_build_query([
                'type' => 'invoice',
                'format' => 'INV-{YYYY}',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('valid', false)
            ->assertJsonStructure(['errors']);

        // Nothing was saved or consumed.
        $this->assertSame('INV-{YYYY}-{SEQ:4}', $this->getFormats()->json('invoice.current'));
        $this->assertNull($this->invoiceCounter());
    }

    public function test_reset_period_is_an_explicit_setting_not_inferred_from_the_format(): void
    {
        // Default (unset) behaviour is unchanged: inferred from the format.
        $this->getFormats()->assertOk()->assertJsonPath('invoice.reset_period', 'yearly');

        // A year-token format that the admin says must NEVER reset.
        $this->putFormats(['reset_periods' => ['invoice' => 'never']])->assertOk()
            ->assertJsonPath('formats.invoice.reset_period', 'never');

        Carbon::setTestNow('2026-12-20 10:00:00');
        $this->assertSame('INV-2026-0001', Invoice::generateInvoiceNumber());

        Carbon::setTestNow('2027-01-04 10:00:00');
        $preview = $this->getFormats()->json('invoice.preview');
        $this->assertSame('INV-2027-0002', $preview, 'never reset: the sequence continues into the new year');
        $this->assertSame($preview, Invoice::generateInvoiceNumber());

        // A {MM} format whose counter the admin wants to run for the whole
        // year: inferred would be monthly, the explicit setting wins.
        $this->putFormats([
            'invoice' => 'INV/{YYYY}/{MM}/{SEQ:3}',
            'reset_periods' => ['invoice' => 'yearly'],
        ])->assertOk()->assertJsonPath('formats.invoice.reset_period', 'yearly');

        Carbon::setTestNow('2027-02-01 09:00:00');
        $preview = $this->getFormats()->json('invoice.preview');
        $this->assertSame('INV/2027/02/003', $preview, 'yearly reset: February continues the year\'s sequence');
        $this->assertSame($preview, Invoice::generateInvoiceNumber());

        // Monthly reset, as chosen explicitly.
        $this->putFormats(['reset_periods' => ['invoice' => 'monthly']])->assertOk();
        Carbon::setTestNow('2027-03-01 09:00:00');
        $preview = $this->getFormats()->json('invoice.preview');
        $this->assertSame('INV/2027/03/001', $preview, 'monthly reset restarts the sequence in March');
        $this->assertSame($preview, Invoice::generateInvoiceNumber());

        // A reset the format cannot carry would reissue existing numbers.
        $this->putFormats([
            'invoice' => 'INV-{YYYY}-{SEQ:4}',
            'reset_periods' => ['invoice' => 'monthly'],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['reset_periods.invoice']]);
        $this->assertSame('INV/{YYYY}/{MM}/{SEQ:3}', $this->getFormats()->json('invoice.current'), 'nothing saved');

        $this->putFormats(['reset_periods' => ['invoice' => 'weekly']])->assertStatus(422);
    }

    public function test_next_sequence_value_can_only_move_forward(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');

        $this->putFormats(['next_sequences' => ['invoice' => 50]])->assertOk()
            ->assertJsonPath('formats.invoice.next_sequence', 50)
            ->assertJsonPath('formats.invoice.preview', 'INV-2026-0050');

        $this->assertSame('INV-2026-0050', Invoice::generateInvoiceNumber());

        // Backwards (would reissue numbers) is refused and changes nothing.
        $this->putFormats(['next_sequences' => ['invoice' => 10]])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['next_sequences.invoice']]);

        $this->assertSame('INV-2026-0051', $this->getFormats()->json('invoice.preview'));
        $this->assertSame('INV-2026-0051', Invoice::generateInvoiceNumber());
    }

    public function test_next_sequence_cannot_move_behind_already_issued_numbers(): void
    {
        Carbon::setTestNow('2026-03-05 10:00:00');
        $this->insertIssuedInvoice('INV-2026-0040');

        $this->getFormats()->assertJsonPath('invoice.next_sequence', 41);

        $this->putFormats(['next_sequences' => ['invoice' => 20]])->assertStatus(422);
        $this->putFormats(['next_sequences' => ['invoice' => 41]])->assertOk();
    }
}
