<?php

namespace Modules\FieldService\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Facades\Auth;
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

            $lines   = [];
            $subtotal = 0.0;

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
                    'description' => $desc,
                    'quantity'    => (float) $log->duration_hours,
                    'unit_price'  => (float) $log->hourly_rate,
                    'tax_rate'    => 0,
                    'discount'    => 0,
                    'line_total'  => $amount,
                ];
                $subtotal += $amount;
            }

            // ── Parts lines ──────────────────────────────────────────
            foreach ($wo->parts as $part) {
                $amount = (float) $part->quantity * (float) $part->unit_price;
                if ($amount <= 0) {
                    continue;
                }

                $desc = $part->description ?: ($part->product->name ?? 'Part/Material');

                $lines[] = [
                    'description' => $desc,
                    'quantity'    => (float) $part->quantity,
                    'unit_price'  => (float) $part->unit_price,
                    'tax_rate'    => 0,
                    'discount'    => 0,
                    'line_total'  => $amount,
                ];
                $subtotal += $amount;
            }

            // ── Fallback line if no billable items ───────────────────
            if (empty($lines)) {
                $lines[] = [
                    'description' => "Field Service — {$wo->title}",
                    'quantity'    => 1,
                    'unit_price'  => 0.00,
                    'tax_rate'    => 0,
                    'discount'    => 0,
                    'line_total'  => 0.00,
                ];
            }

            // ── Create Invoice header ────────────────────────────────
            $invoice = Invoice::create([
                'invoice_number'  => $this->generateInvoiceNumber(),
                'customer_id'     => $wo->customer_id,
                'invoice_date'    => now()->toDateString(),
                'due_date'        => now()->addDays(30)->toDateString(),
                'status'          => 'draft',
                'subtotal'        => $subtotal,
                'tax_amount'      => 0,
                'discount_amount' => 0,
                'total'           => $subtotal,
                'paid_amount'     => 0,
                'balance'         => $subtotal,
                'notes'           => "Generated from Work Order {$wo->reference}: {$wo->title}",
                'created_by'      => Auth::id(),
            ]);

            // ── Create Invoice lines ─────────────────────────────────
            foreach ($lines as $line) {
                InvoiceLine::create(array_merge($line, ['invoice_id' => $invoice->id]));
            }

            // ── Link invoice back to WO ──────────────────────────────
            $wo->invoice_id = $invoice->id;
            $wo->save();

            return $invoice;
        });
    }

    private function generateInvoiceNumber(): string
    {
        $year  = now()->format('Y');
        $count = Invoice::whereYear('created_at', $year)->count() + 1;
        return sprintf('INV-%s-%05d', $year, $count);
    }
}
