<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Models\Invoice;
use App\Models\Bill;
use App\Models\JournalEntry;
use App\Models\InvoiceLine;
use App\Models\BillLine;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class ReplacementDraftService
{
    public function __construct(
        private DocumentVersionLinkService $linkService,
        private DocumentLifecycleService $lifecycleService,
        private PostingEngineInterface $postingEngine,
    ) {}

    public function createInvoiceReplacementDraft(Invoice $original, CorrectionRequest $request): Invoice
    {
        $correctionGroupId = $original->correction_group_id ?? Str::uuid()->toString();

        $original->update([
            'correction_group_id' => $correctionGroupId,
            'is_active_version' => false,
            'lifecycle_status' => 'superseded',
            'superseded_at' => now(),
        ]);

        $nextVersion = ($original->version_number ?? 1) + 1;

        $replacement = Invoice::create([
            'invoice_number' => $original->invoice_number . '-R' . str_pad($nextVersion, 2, '0', STR_PAD_LEFT),
            'customer_id' => $original->customer_id,
            'invoice_date' => $original->invoice_date,
            'due_date' => $original->due_date,
            'customer_reference' => $original->customer_reference,
            'internal_reference' => $original->internal_reference,
            'attention_to' => $original->attention_to,
            'contact_email' => $original->contact_email,
            'contact_phone' => $original->contact_phone,
            'billing_address_snapshot' => $original->billing_address_snapshot,
            'shipping_address_snapshot' => $original->shipping_address_snapshot,
            'company_registration_number' => $original->company_registration_number,
            'tax_registration_number' => $original->tax_registration_number,
            'status' => 'draft',
            'subtotal' => $original->subtotal,
            'tax_amount' => $original->tax_amount,
            'discount_amount' => $original->discount_amount,
            'total' => $original->total,
            'paid_amount' => 0,
            'balance' => $original->total,
            'notes' => $original->notes,
            'terms' => $original->terms,
            'sent_at' => null,
            'created_by' => $request->userId ?? Auth::id(),
            'origin_type' => 'replacement',
            'origin_reference_type' => 'invoice',
            'origin_reference_id' => $original->id,
            'lifecycle_status' => 'draft',
            'settlement_status' => 'unpaid',
            'correction_group_id' => $correctionGroupId,
            'original_document_id' => $original->id,
            'correction_type' => 'replacement',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'version_number' => $nextVersion,
            'is_active_version' => true,
        ]);

        foreach ($original->lines as $originalLine) {
            InvoiceLine::create([
                'invoice_id' => $replacement->id,
                'product_id' => $originalLine->product_id,
                'description' => $originalLine->description,
                'quantity' => $originalLine->quantity,
                'unit_price' => $originalLine->unit_price,
                'tax_rate' => $originalLine->tax_rate,
                'tax_amount' => $originalLine->tax_amount,
                'discount_amount' => $originalLine->discount_amount,
                'line_total' => $originalLine->line_total,
            ]);
        }

        $original->update([
            'replacement_document_id' => $replacement->id,
        ]);

        $this->linkService->linkReplacement($original, $replacement, $request);

        return $replacement;
    }

    public function createBillReplacementDraft(Bill $original, CorrectionRequest $request): Bill
    {
        $correctionGroupId = $original->correction_group_id ?? Str::uuid()->toString();

        $original->update([
            'correction_group_id' => $correctionGroupId,
            'is_active_version' => false,
            'lifecycle_status' => 'superseded',
            'superseded_at' => now(),
        ]);

        $nextVersion = ($original->version_number ?? 1) + 1;

        $replacement = Bill::create([
            'bill_number' => $original->bill_number . '-R' . str_pad($nextVersion, 2, '0', STR_PAD_LEFT),
            'vendor_id' => $original->vendor_id,
            'purchase_order_id' => $original->purchase_order_id,
            'goods_receipt_id' => $original->goods_receipt_id,
            'bill_date' => $original->bill_date,
            'due_date' => $original->due_date,
            'vendor_reference' => $original->vendor_reference,
            'internal_reference' => $original->internal_reference,
            'attention_to' => $original->attention_to,
            'contact_email' => $original->contact_email,
            'contact_phone' => $original->contact_phone,
            'vendor_address_snapshot' => $original->vendor_address_snapshot,
            'remittance_address_snapshot' => $original->remittance_address_snapshot,
            'company_registration_number' => $original->company_registration_number,
            'tax_registration_number' => $original->tax_registration_number,
            'status' => 'draft',
            'bill_type' => $original->bill_type,
            'subtotal' => $original->subtotal,
            'tax_amount' => $original->tax_amount,
            'discount_amount' => $original->discount_amount,
            'total' => $original->total,
            'paid_amount' => 0,
            'balance' => $original->total,
            'notes' => $original->notes,
            'terms' => $original->terms,
            'received_at' => null,
            'created_by' => $request->userId ?? Auth::id(),
            'origin_type' => 'replacement',
            'origin_reference_type' => 'bill',
            'origin_reference_id' => $original->id,
            'lifecycle_status' => 'draft',
            'settlement_status' => 'unpaid',
            'correction_group_id' => $correctionGroupId,
            'original_document_id' => $original->id,
            'correction_type' => 'replacement',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'version_number' => $nextVersion,
            'is_active_version' => true,
        ]);

        foreach ($original->lines as $originalLine) {
            BillLine::create([
                'bill_id' => $replacement->id,
                'product_id' => $originalLine->product_id,
                'description' => $originalLine->description,
                'quantity' => $originalLine->quantity,
                'unit_price' => $originalLine->unit_price,
                'tax_rate' => $originalLine->tax_rate,
                'tax_amount' => $originalLine->tax_amount,
                'discount_amount' => $originalLine->discount_amount,
                'line_total' => $originalLine->line_total,
            ]);
        }

        $original->update([
            'replacement_document_id' => $replacement->id,
        ]);

        $this->linkService->linkReplacement($original, $replacement, $request);

        return $replacement;
    }

    public function createJournalReplacementDraft(JournalEntry $original, CorrectionRequest $request): JournalEntry
    {
        $result = $this->postingEngine->createReplacementDraft($original, $request);

        if (!$result->success || !$result->journalEntry) {
            throw new \RuntimeException('Failed to create journal replacement draft: ' . ($result->error ?? 'Unknown error'));
        }

        $this->linkService->linkReplacement($original, $result->journalEntry, $request);

        return $result->journalEntry;
    }
}