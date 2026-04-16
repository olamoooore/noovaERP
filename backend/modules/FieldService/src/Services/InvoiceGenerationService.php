<?php

namespace Modules\FieldService\Services;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Modules\FieldService\Models\WorkOrder;

class InvoiceGenerationService
{
    /**
     * Generate a draft invoice from a completed work order.
     * Returns the created Invoice model.
     */
    public function generateFromWorkOrder(WorkOrder $wo): Invoice
    {
        if ($wo->invoice_id) {
            return Invoice::findOrFail($wo->invoice_id);
        }

        return DB::transaction(function () use ($wo) {
            $wo->load(['parts.product', 'timeLogs', 'customer']);

            $lines = [];

            // ── Labor lines ──────────────────────────────────────────
            foreach ($wo->timeLogs->where('is_billable', true) as $log) {
                $amount = (float) $log->duration_hours * (float) $log->hourly_rate;
                if ($amount <= 0) {
                    continue;
                }

                $desc = 'Labor';
                if ($log->description) {
                    $desc .= ' — ' . $log->description;
                }

                $lines[] = [
                    'description'     => $desc,
                    'quantity'        => (float) $log->duration_hours,
                    'unit_price'      => (float) $log->hourly_rate,
                    'tax_rate'        => 0,
                    'discount_amount' => 0,
                ];
            }

            // ── Parts lines ──────────────────────────────────────────
            foreach ($wo->parts as $part) {
                $amount = (float) $part->quantity * (float) $part->unit_price;
                if ($amount <= 0) {
                    continue;
                }

                $desc = $part->description ?: ($part->product->name ?? 'Part/Material');

                $lines[] = [
                    'description'     => $desc,
                    'product_id'      => $part->product_id ?? null,
                    'quantity'        => (float) $part->quantity,
                    'unit_price'      => (float) $part->unit_price,
                    'tax_rate'        => 0,
                    'discount_amount' => 0,
                ];
            }

            // ── Fallback line if no billable items ───────────────────
            if (empty($lines)) {
                $lines[] = [
                    'description'     => "Field Service — {$wo->title}",
                    'quantity'        => 1,
                    'unit_price'      => 0.00,
                    'tax_rate'        => 0,
                    'discount_amount' => 0,
                ];
            }

            // ── Create Invoice via canonical InvoiceService ──────────
            /** @var InvoiceService $invoiceService */
            $invoiceService = app(InvoiceService::class);
            $invoice = $invoiceService->createInvoice([
                'customer_id'  => $wo->customer_id,
                'invoice_date' => now()->toDateString(),
                'due_date'     => now()->addDays(30)->toDateString(),
                'notes'        => "Generated from Work Order {$wo->reference}: {$wo->title}",
                'lines'        => $lines,
            ]);

            // ── Link invoice back to WO ──────────────────────────────
            $wo->invoice_id = $invoice->id;
            $wo->save();

            return $invoice;
        });
    }
}
