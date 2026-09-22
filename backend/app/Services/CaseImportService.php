<?php

namespace App\Services;

use App\Models\BonusScheme;
use App\Models\BonusSchemeProduct;
use App\Models\CustomerBonusScheme;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Quotation;
use App\Models\QuotationLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles automatic creation of Medical Cases and Bonus Schemes during
 * invoice / quotation / delivery order imports.
 *
 * The "Case Tracking" CSV format from Acme contains:
 *   - Case No       → case_no (e.g. "1st", "2nd")
 *   - Case Tag      → case_tag (e.g. "FOC", "GEH", "HTx 60s", "Clinic", "CCK")
 *   - Case Date     → case_date
 *   - Patient Name  → patient_reference (non-identifying)
 *   - Delivered To  → delivered_to
 *   - Other Note    → notes
 *
 * When a case tag is present and does not match an existing bonus scheme,
 * one is created automatically and assigned to the customer.
 */
class CaseImportService
{
    /**
     * After invoice import, create a medical case if case fields are present.
     *
     * @param  Invoice  $invoice  The freshly created invoice
     * @param  array    $data  Case data: case_no, case_tag, case_date,
     *                          patient_name, delivered_to, other_note
     * @return MedicalCase|null
     */
    public function createCaseFromInvoiceImport(Invoice $invoice, array $data): ?MedicalCase
    {
        if (empty($data['case_no']) && empty($data['case_tag']) && empty($data['patient_name'])) {
            return null;
        }

        $caseNo = $this->buildCaseNo($data['case_no'] ?? null, $invoice);

        $bonusSchemeId = null;
        if (! empty($data['case_tag'])) {
            $bonusSchemeId = $this->resolveOrCreateBonusScheme(
                $data['case_tag'],
                $invoice->customer_id,
                $data['case_date'] ?? null
            );
        }

        return DB::transaction(function () use ($invoice, $data, $caseNo, $bonusSchemeId) {
            $case = MedicalCase::create([
                'case_no' => $caseNo,
                'case_tag' => $data['case_tag'] ?? null,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'patient_reference' => $data['patient_name'] ?? null,
                'case_date' => $data['case_date'] ?? null,
                'delivered_to' => $data['delivered_to'] ?? null,
                'notes' => $data['other_note'] ?? null,
                'status' => $this->determineCaseStatus($invoice),
                'created_by' => $invoice->created_by ?? null,
            ]);

            $this->linkInvoiceLinesToCase($invoice, $case, $bonusSchemeId);

            return $case;
        });
    }

    /**
     * After quotation import, create a medical case if case fields are present.
     */
    public function createCaseFromQuotationImport(Quotation $quotation, array $data): ?MedicalCase
    {
        if (empty($data['case_no']) && empty($data['case_tag']) && empty($data['patient_name'])) {
            return null;
        }

        $caseNo = $this->buildCaseNo($data['case_no'] ?? null, null, $quotation);

        $bonusSchemeId = null;
        if (! empty($data['case_tag'])) {
            $bonusSchemeId = $this->resolveOrCreateBonusScheme(
                $data['case_tag'],
                $quotation->customer_id,
                $data['case_date'] ?? null
            );
        }

        return DB::transaction(function () use ($quotation, $data, $caseNo, $bonusSchemeId) {
            $case = MedicalCase::create([
                'case_no' => $caseNo,
                'case_tag' => $data['case_tag'] ?? null,
                'customer_id' => $quotation->customer_id,
                'patient_reference' => $data['patient_name'] ?? null,
                'case_date' => $data['case_date'] ?? null,
                'delivered_to' => $data['delivered_to'] ?? null,
                'notes' => $data['other_note'] ?? null,
                'status' => 'draft',
                'created_by' => $quotation->created_by ?? null,
            ]);

            $this->linkQuotationLinesToCase($quotation, $case, $bonusSchemeId);

            return $case;
        });
    }

    /**
     * Find or create a bonus scheme by name (case tag).
     * Also assigns it to the customer if not already assigned.
     */
    public function resolveOrCreateBonusScheme(string $tag, int $customerId, ?string $caseDate = null): ?int
    {
        $tag = trim($tag);
        if ($tag === '') {
            return null;
        }

        // Ensure tenant search_path is set
        $this->ensureTenantSearchPath();

        $schemeName = "Auto: {$tag}";
        try {
            $existing = BonusScheme::where('name', $schemeName)->first();
        } catch (\Throwable $e) {
            Log::warning("Error querying bonus scheme: " . $e->getMessage());
            $existing = null;
        }

        $scheme = $existing;
        $userId = auth()->id() ?? 1; // Fallback to 1 if no auth (shouldn't happen)

        if (!$scheme) {
            try {
                $scheme = new BonusScheme();
                $scheme->name = $schemeName;
                $scheme->description = "Auto-created from import: case tag '{$tag}'";
                $scheme->start_date = $caseDate ?? now()->toDateString();
                $scheme->status = 'active';
                $scheme->created_by = $userId;
                $scheme->save();
                Log::info("Auto-created bonus scheme from import", ['tag' => $tag, 'scheme_id' => $scheme->id]);
            } catch (\Throwable $e) {
                Log::warning("Failed to auto-create bonus scheme for tag '{$tag}': " . $e->getMessage() . " | trace: " . $e->getTraceAsString());
                return null;
            }
        }

        try {
            $existingAssignment = CustomerBonusScheme::where('customer_id', $customerId)
                ->where('bonus_scheme_id', $scheme->id)
                ->first();

            if (!$existingAssignment) {
                CustomerBonusScheme::create([
                    'customer_id' => $customerId,
                    'bonus_scheme_id' => $scheme->id,
                    'start_date' => $caseDate ?? now()->toDateString(),
                    'status' => 'active',
                    'remarks' => 'Auto-assigned from import',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to auto-assign bonus scheme to customer: " . $e->getMessage());
        }

        return $scheme->id;
    }

    private function ensureTenantSearchPath(): void
    {
        try {
            $schema = tenant()?->getSchemaName();
            if ($schema) {
                \Illuminate\Support\Facades\DB::statement("SET search_path TO \"{$schema}\", public");
            }
        } catch (\Throwable $e) {
            // ignore if tenancy not active
        }
    }

    /**
     * Determine if a case tag represents a FOC (Free of Charge) item.
     */
    public function isFocTag(?string $tag): bool
    {
        if (!$tag) {
            return false;
        }
        return stripos($tag, 'FOC') !== false;
    }

    /**
     * Build a unique case number from the imported data.
     */
    private function buildCaseNo(?string $caseNo, ?Invoice $invoice = null, ?Quotation $quotation = null): string
    {
        if ($caseNo && trim($caseNo) !== '') {
            $prefix = '';
            $docId = '';
            if ($invoice) {
                $prefix = 'INV-';
                $docId = (string) $invoice->id;
            } elseif ($quotation) {
                $prefix = 'QT-';
                $docId = (string) $quotation->id;
            }
            $cleanCaseNo = trim($caseNo);
            $baseNumber = $prefix . $docId . '-' . $cleanCaseNo;

            // Ensure uniqueness — append counter if needed
            $finalNumber = $baseNumber;
            $counter = 1;
            while (MedicalCase::withTrashed()->where('case_no', $finalNumber)->exists()) {
                $finalNumber = $baseNumber . '-' . $counter;
                $counter++;
                if ($counter > 100) {
                    // Fallback: use generated number
                    return MedicalCase::generateCaseNumber();
                }
            }
            return $finalNumber;
        }

        return MedicalCase::generateCaseNumber();
    }

    /**
     * Determine the case status based on the invoice status.
     */
    private function determineCaseStatus(Invoice $invoice): string
    {
        if ($invoice->lifecycle_status === 'posted' || $invoice->status === 'posted') {
            return 'invoiced';
        }
        return 'draft';
    }

    /**
     * Link invoice line items to the medical case.
     */
    private function linkInvoiceLinesToCase(Invoice $invoice, MedicalCase $case, ?int $bonusSchemeId): void
    {
        $isFoc = $this->isFocTag($case->case_tag);
        $salesOrder = $invoice->sales_order_id ? \Modules\Sales\Models\SalesOrder::find($invoice->sales_order_id) : null;
        $salesOrderLines = $salesOrder ? $salesOrder->lines : collect();

        foreach ($invoice->lines as $line) {
            $productId = $line->product_id;
            // If no product_id on line, try to find by description match on SO
            if (!$productId && $salesOrder) {
                $soLine = $salesOrderLines->firstWhere('description', $line->description);
                $productId = $soLine?->product_id;
            }
            if (!$productId) {
                $productId = $salesOrderLines->first()?->product_id;
            }

            // Try to find the matching SO line by product_id
            $soLine = $salesOrderLines->firstWhere('product_id', $productId);
            if (!$soLine && $salesOrderLines->count() > 0) {
                $soLine = $salesOrderLines->first();
            }
            if (!$soLine) {
                Log::warning('Cannot create medical case item: no sales order line available', [
                    'invoice_id' => $invoice->id,
                    'line_id' => $line->id,
                ]);
                continue;
            }

            MedicalCaseItem::create([
                'medical_case_id' => $case->id,
                'sales_order_item_id' => $soLine->id,
                'invoice_item_id' => $line->id,
                'product_id' => $productId,
                'allocated_qty' => $line->quantity,
                'invoiced_qty' => $line->quantity,
                'unit_price' => $isFoc ? 0 : $line->unit_price,
                'is_foc' => $isFoc,
                'notes' => $line->product_notes ?? null,
            ]);
        }
    }

    /**
     * Link quotation line items to the medical case.
     */
    private function linkQuotationLinesToCase(Quotation $quotation, MedicalCase $case, ?int $bonusSchemeId): void
    {
        $isFoc = $this->isFocTag($case->case_tag);

        $so = $quotation->sales_order_id ? \Modules\Sales\Models\SalesOrder::find($quotation->sales_order_id) : null;
        $soLines = $so ? $so->lines : collect();
        $fallbackSoLineId = $soLines->first()?->id ?? \Modules\Sales\Models\SalesOrderLine::first()?->id;

        if (!$fallbackSoLineId) {
            Log::warning('Cannot create medical case item: no sales order line available', [
                'quotation_id' => $quotation->id,
            ]);
            return;
        }

        foreach ($quotation->lines as $line) {
            $productId = $line->product_id;
            $soLine = $soLines->firstWhere('product_id', $productId);

            MedicalCaseItem::create([
                'medical_case_id' => $case->id,
                'sales_order_item_id' => $soLine?->id ?? $fallbackSoLineId,
                'product_id' => $productId,
                'allocated_qty' => $line->quantity,
                'unit_price' => $isFoc ? 0 : $line->unit_price,
                'is_foc' => $isFoc,
                'notes' => $line->product_notes ?? null,
            ]);
        }
    }
}
