<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Models\OcrDocument;

/**
 * OCR Promotion Service.
 *
 * Promotes reviewed OCR documents into canonical accounting documents (bills / invoices).
 * After promotion the canonical document is posted through standard PostingEngine flows.
 * OCR records retain their identity but are no longer the accounting source of truth.
 *
 * Hard rule (EDR §5.3): OCR ingestion IDs can never be used as bill_id, invoice_id,
 * or payment_id. OCR may only promote into canonical documents.
 */
class OcrPromotionService
{
    /**
     * Promote an OCR document into a canonical vendor bill draft.
     *
     * @return array{bill_id: int, status: string}
     */
    public function promoteToBill(int $ocrDocumentId): array
    {
        $doc = OcrDocument::findOrFail($ocrDocumentId);

        $this->validatePromotionEligibility($doc);

        return DB::transaction(function () use ($doc) {
            // Create canonical bill record — targets the live `bills` table
            $billId = DB::table('bills')->insertGetId([
                'vendor_id'          => $doc->vendor_id,
                'bill_number'        => $doc->document_reference ?? $this->generateBillNumber(),
                'bill_date'          => $doc->document_date,
                'due_date'           => $doc->due_date,
                'currency_code'      => $doc->currency_code,
                'exchange_rate'      => $doc->exchange_rate,
                'subtotal'           => $doc->subtotal ?? 0,
                'tax_amount'         => $doc->tax_amount ?? 0,
                'total_amount'       => $doc->total_amount,
                'status'             => 'draft',
                'source'             => 'ocr_promotion',
                'source_reference'   => "OCR-{$doc->uuid}",
                'notes'              => "Promoted from OCR document {$doc->uuid}",
                'created_by'         => auth()->id(),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            // Create bill line items from OCR line items
            if ($doc->line_items && is_array($doc->line_items)) {
                foreach ($doc->line_items as $index => $item) {
                    // Create bill line items — targets live `bill_lines` table
                    DB::table('bill_lines')->insert([
                        'bill_id'     => $billId,
                        'account_id'  => $item['account_id'] ?? null,
                        'description' => $item['description'] ?? 'Line item',
                        'quantity'    => $item['quantity'] ?? 1,
                        'unit_price'  => $item['amount'] ?? 0,
                        'amount'      => $item['amount'] ?? 0,
                        'tax_amount'  => $item['tax_amount'] ?? 0,
                        'sort_order'  => $index,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }

            // Mark OCR document as promoted (not the accounting source of truth)
            $doc->update([
                'status' => 'PROMOTED',
            ]);

            Log::info("OCR document promoted to bill", [
                'ocr_uuid'  => $doc->uuid,
                'bill_id'   => $billId,
            ]);

            return [
                'bill_id' => $billId,
                'status'  => 'draft',
            ];
        });
    }

    /**
     * Promote an OCR document into a canonical customer invoice draft.
     *
     * @return array{invoice_id: int, status: string}
     */
    public function promoteToInvoice(int $ocrDocumentId): array
    {
        $doc = OcrDocument::findOrFail($ocrDocumentId);

        $this->validatePromotionEligibility($doc);

        return DB::transaction(function () use ($doc) {
            $invoiceId = DB::table('invoices')->insertGetId([
                'customer_id'        => $doc->vendor_id, // may be a customer in invoice context
                'invoice_number'     => $doc->document_reference ?? $this->generateInvoiceNumber(),
                'invoice_date'       => $doc->document_date,
                'due_date'           => $doc->due_date,
                'currency_code'      => $doc->currency_code,
                'exchange_rate'      => $doc->exchange_rate,
                'subtotal'           => $doc->subtotal ?? 0,
                'tax_amount'         => $doc->tax_amount ?? 0,
                'total_amount'       => $doc->total_amount,
                'status'             => 'draft',
                'source'             => 'ocr_promotion',
                'source_reference'   => "OCR-{$doc->uuid}",
                'notes'              => "Promoted from OCR document {$doc->uuid}",
                'created_by'         => auth()->id(),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            if ($doc->line_items && is_array($doc->line_items)) {
                foreach ($doc->line_items as $index => $item) {
                    DB::table('invoice_items')->insert([
                        'invoice_id'  => $invoiceId,
                        'account_id'  => $item['account_id'] ?? null,
                        'description' => $item['description'] ?? 'Line item',
                        'quantity'    => $item['quantity'] ?? 1,
                        'unit_price'  => $item['amount'] ?? 0,
                        'amount'      => $item['amount'] ?? 0,
                        'tax_amount'  => $item['tax_amount'] ?? 0,
                        'sort_order'  => $index,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }

            $doc->update([
                'status' => 'PROMOTED',
            ]);

            Log::info("OCR document promoted to invoice", [
                'ocr_uuid'    => $doc->uuid,
                'invoice_id'  => $invoiceId,
            ]);

            return [
                'invoice_id' => $invoiceId,
                'status'     => 'draft',
            ];
        });
    }

    /**
     * Validate that an OCR document is eligible for promotion.
     */
    private function validatePromotionEligibility(OcrDocument $doc): void
    {
        if ($doc->status !== 'NEEDS_REVIEW' && $doc->status !== 'POSTED') {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} cannot be promoted. Status must be NEEDS_REVIEW or POSTED, got {$doc->status}."
            );
        }

        if (!$doc->total_amount || $doc->total_amount <= 0) {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} missing total_amount."
            );
        }

        if (!$doc->document_date) {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} missing document_date."
            );
        }
    }

    private function generateBillNumber(): string
    {
        $last = DB::table('bills')->max('id') ?? 0;
        return 'BILL-' . str_pad($last + 1, 6, '0', STR_PAD_LEFT);
    }

    private function generateInvoiceNumber(): string
    {
        $last = DB::table('invoices')->max('id') ?? 0;
        return 'INV-' . str_pad($last + 1, 6, '0', STR_PAD_LEFT);
    }
}
