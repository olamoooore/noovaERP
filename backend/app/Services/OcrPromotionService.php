<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Services\CustomerMappingService;
use Modules\OCRBookkeeping\Services\VendorMappingService;

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

        $this->resolveVendorForPromotion($doc);
        $this->validatePromotionEligibility($doc);

        return DB::transaction(function () use ($doc) {
            $billTotal = $doc->total_amount ?? 0;

            $billId = DB::table('bills')->insertGetId([
                'vendor_id' => $doc->vendor_id,
                'bill_number' => $doc->document_reference ?? $this->generateBillNumber(),
                'bill_date' => $doc->document_date,
                'due_date' => $doc->due_date,
                'status' => 'draft',
                'subtotal' => $doc->subtotal ?? 0,
                'tax_amount' => $doc->tax_amount ?? 0,
                'discount_amount' => 0,
                'total' => $billTotal,
                'paid_amount' => 0,
                'balance' => $billTotal,
                'notes' => "Promoted from OCR document {$doc->uuid}",
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($doc->line_items && is_array($doc->line_items)) {
                foreach ($doc->line_items as $item) {
                    $quantity = $item['quantity'] ?? 1;
                    $unitPrice = $item['unit_price'] ?? $item['amount'] ?? 0;
                    $taxAmount = $item['tax_amount'] ?? 0;
                    $lineTotal = $item['line_total'] ?? $item['amount'] ?? (($quantity * $unitPrice) + $taxAmount);

                    DB::table('bill_lines')->insert([
                        'bill_id' => $billId,
                        'product_id' => $item['product_id'] ?? null,
                        'description' => $item['description'] ?? 'Line item',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'tax_rate' => $item['tax_rate'] ?? 0,
                        'tax_amount' => $taxAmount,
                        'line_total' => $lineTotal,
                        'account_id' => $item['account_id'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $doc->update([
                'status' => 'PROMOTED',
            ]);

            Log::info('OCR document promoted to bill', [
                'ocr_uuid' => $doc->uuid,
                'bill_id' => $billId,
            ]);

            return [
                'bill_id' => $billId,
                'status' => 'draft',
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

        $this->resolveCustomerForPromotion($doc);
        $this->validateInvoicePromotionEligibility($doc);

        return DB::transaction(function () use ($doc) {
            $invoiceTotal = $doc->total_amount ?? 0;

            $invoiceId = DB::table('invoices')->insertGetId([
                'customer_id' => $doc->customer_id,
                'invoice_number' => $doc->document_reference ?? $this->generateInvoiceNumber(),
                'invoice_date' => $doc->document_date,
                'due_date' => $doc->due_date ?? $doc->document_date ?? now()->toDateString(),
                'status' => 'draft',
                'subtotal' => $doc->subtotal ?? 0,
                'tax_amount' => $doc->tax_amount ?? 0,
                'discount_amount' => 0,
                'total' => $invoiceTotal,
                'paid_amount' => 0,
                'balance' => $invoiceTotal,
                'notes' => "Promoted from OCR document {$doc->uuid}",
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($doc->line_items && is_array($doc->line_items)) {
                foreach ($doc->line_items as $item) {
                    $quantity = $item['quantity'] ?? 1;
                    $unitPrice = $item['unit_price'] ?? $item['amount'] ?? 0;
                    $taxAmount = $item['tax_amount'] ?? 0;
                    $lineTotal = $item['line_total'] ?? $item['amount'] ?? (($quantity * $unitPrice) + $taxAmount);

                    DB::table('invoice_items')->insert([
                        'invoice_id' => $invoiceId,
                        'product_id' => $item['product_id'] ?? null,
                        'description' => $item['description'] ?? 'Line item',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'tax_rate' => $item['tax_rate'] ?? 0,
                        'tax_amount' => $taxAmount,
                        'discount_amount' => $item['discount_amount'] ?? 0,
                        'line_total' => $lineTotal,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $doc->update([
                'status' => 'PROMOTED',
            ]);

            Log::info('OCR document promoted to invoice', [
                'ocr_uuid' => $doc->uuid,
                'invoice_id' => $invoiceId,
            ]);

            return [
                'invoice_id' => $invoiceId,
                'status' => 'draft',
            ];
        });
    }

    /**
     * Try to resolve vendor_id from vendor_name before bill promotion.
     */
    private function resolveVendorForPromotion(OcrDocument $doc): void
    {
        if ($doc->vendor_id || ! $doc->vendor_name) {
            return;
        }

        $match = app(VendorMappingService::class)->getBestMatch($doc->vendor_name);

        if (! $match) {
            return;
        }

        $vendorId = null;

        if (is_array($match)) {
            $vendorId = $match['vendor_id'] ?? $match['id'] ?? data_get($match, 'vendor.id');
        } elseif (is_object($match)) {
            $vendorId = $match->vendor_id ?? $match->id ?? data_get($match, 'vendor.id');
        }

        if (! $vendorId) {
            return;
        }

        $doc->update([
            'vendor_id' => $vendorId,
        ]);

        $doc->refresh();

        Log::info('OCR promotion vendor auto-matched', [
            'ocr_uuid' => $doc->uuid,
            'vendor_name' => $doc->vendor_name,
            'vendor_id' => $vendorId,
        ]);
    }

    /**
     * Try to resolve customer_id before invoice promotion.
     */
    private function resolveCustomerForPromotion(OcrDocument $doc): void
    {
        if ($doc->customer_id) {
            return;
        }

        $customerName = $doc->customer_name ?? $doc->vendor_name;

        if (! $customerName) {
            return;
        }

        $match = app(CustomerMappingService::class)->getBestMatch($customerName);

        if (! $match) {
            return;
        }

        $customerId = null;

        if (is_array($match)) {
            $customerId = $match['customer_id'] ?? $match['id'] ?? data_get($match, 'customer.id');
        } elseif (is_object($match)) {
            $customerId = $match->customer_id ?? $match->id ?? data_get($match, 'customer.id');
        }

        if (! $customerId) {
            return;
        }

        $doc->update([
            'customer_id' => $customerId,
            'customer_name' => $customerName,
        ]);

        $doc->refresh();

        Log::info('OCR promotion customer auto-matched', [
            'ocr_uuid' => $doc->uuid,
            'customer_name' => $customerName,
            'customer_id' => $customerId,
        ]);
    }

    /**
     * Validate that an OCR document is eligible for bill promotion.
     */
    private function validatePromotionEligibility(OcrDocument $doc): void
    {
        if ($doc->status !== 'NEEDS_REVIEW') {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} cannot be promoted. Status must be NEEDS_REVIEW, got {$doc->status}."
            );
        }

        if (! $doc->vendor_id) {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} missing vendor_id."
            );
        }

        if (! $doc->total_amount || $doc->total_amount <= 0) {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} missing total_amount."
            );
        }

        if (! $doc->document_date) {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} missing document_date."
            );
        }
    }

    /**
     * Validate that an OCR document is eligible for invoice promotion.
     */
    private function validateInvoicePromotionEligibility(OcrDocument $doc): void
    {
        if ($doc->status !== 'NEEDS_REVIEW') {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} cannot be promoted. Status must be NEEDS_REVIEW, got {$doc->status}."
            );
        }

        if (! $doc->customer_id) {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} missing customer_id."
            );
        }

        if (! $doc->total_amount || $doc->total_amount <= 0) {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} missing total_amount."
            );
        }

        if (! $doc->document_date) {
            throw new \RuntimeException(
                "OCR document {$doc->uuid} missing document_date."
            );
        }
    }

    private function generateBillNumber(): string
    {
        $last = DB::table('bills')->max('id') ?? 0;

        return 'BILL-'.str_pad($last + 1, 6, '0', STR_PAD_LEFT);
    }

    private function generateInvoiceNumber(): string
    {
        $last = DB::table('invoices')->max('id') ?? 0;

        return 'INV-'.str_pad($last + 1, 6, '0', STR_PAD_LEFT);
    }
}
