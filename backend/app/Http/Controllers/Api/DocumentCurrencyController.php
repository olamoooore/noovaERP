<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * Document currency change endpoint (MRD pricelist-multicurrency FR-11 /
 * FR-5.2 / FR-6.4): the guarded, single path to re-currency a draft document.
 *
 *   POST /documents/{type}/{id}/currency
 *   { currency_code, reprice: true|false }   (reprice default true)
 *
 * Changing the currency re-prices all NON-manual lines through PricingService
 * (FR-5.2: "unless the line was manually overridden") and re-stamps the FX
 * snapshot at the document date. Posted documents are immutable (FR-10.4) —
 * corrections go through the documented reversal paths, never this endpoint.
 */
class DocumentCurrencyController extends Controller
{
    private const TYPES = [
        'quotation' => Quotation::class,
        'sales_order' => SalesOrder::class,
        'delivery_order' => DeliveryOrder::class,
        'invoice' => Invoice::class,
        'customer_credit_note' => CustomerCreditNote::class,
    ];

    public function update(Request $request, string $type, int $id)
    {
        $modelClass = self::TYPES[$type] ?? null;

        if (! $modelClass) {
            return response()->json([
                'message' => "Unsupported document type '{$type}'. Allowed: ".implode(', ', array_keys(self::TYPES)),
            ], 422);
        }

        $validated = $request->validate([
            'currency_code' => 'required|string|size:3',
            'reprice' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $document = $modelClass::query()->findOrFail($id);

        if ($this->isPosted($type, $document)) {
            return response()->json([
                'message' => 'Posted documents are immutable (FR-10.4). Reverse or credit the document and reissue it in the target currency.',
            ], 422);
        }

        $pricing = app(PricingService::class);

        $previousCurrency = (string) $document->currency_code;

        // New snapshot at the document's own date (FR-1.3 / FR-5.2).
        $snapshot = \App\Services\DocumentCurrencyResolver::snapshot(
            $validated['currency_code'],
            $document->customer_id ?? null,
            $document->legal_entity_id ?? null,
            $this->documentDate($type, $document)
        );

        $document->update([
            'currency_code' => $snapshot['currency_code'],
            'exchange_rate' => $snapshot['exchange_rate'],
            'base_currency_code' => $snapshot['base_currency_code'],
        ]);

        $repricedLines = 0;
        $keptManualLines = 0;

        if ($request->boolean('reprice', true)) {
            $context = $pricing->documentContext([
                'customer_id' => $document->customer_id ?? null,
                'legal_entity_id' => $document->legal_entity_id ?? null,
                'date' => $this->documentDate($type, $document),
                'currency_code' => $snapshot['currency_code'],
                'exchange_rate' => $snapshot['exchange_rate'],
                'base_currency_code' => $snapshot['base_currency_code'],
            ]);

            foreach ($document->lines as $line) {
                if (! $line->product_id) {
                    // Non-product lines (text/note/section) keep their label;
                    // monetary lines without a product re-scale by rate.
                    if ((float) $line->unit_price > 0) {
                        $line->update([
                            'base_unit_price' => round((float) $line->unit_price * $snapshot['exchange_rate'], 4),
                        ]);
                    }

                    continue;
                }

                $trace = $line->pricing_trace ?? [];
                $isManual = ($trace['source'] ?? null) === 'manual';

                if ($isManual) {
                    // Manual prices are VALUES in the OLD document currency;
                    // re-stamp only the base equivalent (their unit value is
                    // preserved — the user chose it).
                    $line->update([
                        'base_unit_price' => round((float) $line->unit_price * $snapshot['exchange_rate'], 4),
                    ]);
                    $keptManualLines++;

                    continue;
                }

                $product = \App\Models\Product::find($line->product_id);

                if (! $product) {
                    continue;
                }

                $priced = $pricing->priceLineArray($product, [
                    'quantity' => (float) $line->quantity,
                    'variant_id' => $line->variant_id ?? null,
                ], $context, 'auto');

                $line->update([
                    'unit_price' => $priced['unit_price'] ?? $line->unit_price,
                    'base_unit_price' => $priced['base_unit_price'] ?? null,
                    'pricing_trace' => $priced['pricing_trace'] ?? null,
                ]);
                $repricedLines++;
            }
        }

        $this->recalculateTotals($type, $document);

        try {
            app(\App\Services\AuditLogger::class)->log(
                operation: 'document.currency_changed',
                referenceType: $type,
                referenceId: (int) $document->id,
                actorId: \Illuminate\Support\Facades\Auth::id() !== null ? (int) \Illuminate\Support\Facades\Auth::id() : null,
                beforeState: ['currency_code' => $previousCurrency],
                afterState: [
                    'currency_code' => $snapshot['currency_code'],
                    'exchange_rate' => $snapshot['exchange_rate'],
                    'base_currency_code' => $snapshot['base_currency_code'],
                    'repriced_lines' => $repricedLines,
                    'kept_manual_lines' => $keptManualLines,
                    'reason' => $validated['reason'] ?? null,
                ],
            );
        } catch (\Throwable) {
            // Audit failure must not roll back a completed currency change.
        }

        return response()->json([
            'message' => "Document currency changed to {$snapshot['currency_code']}",
            'data' => $document->fresh('lines'),
            'meta' => [
                'rate_used' => $snapshot['exchange_rate'],
                'rate_date' => $snapshot['rate_date'],
                'rate_source' => $snapshot['rate_source'],
                'base_currency_code' => $snapshot['base_currency_code'],
                'repriced_lines' => $repricedLines,
                'kept_manual_lines' => $keptManualLines,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════════

    private function isPosted(string $type, $document): bool
    {
        return match ($type) {
            'quotation' => in_array($document->status, ['confirmed', 'invoiced'], true) || $document->sales_order_id !== null,
            'sales_order' => in_array($document->status, ['confirmed', 'allocated', 'fulfilled', 'invoiced', 'completed'], true)
                || $document->accounting_status !== 'not_posted',
            'delivery_order' => in_array($document->status, ['confirmed', 'dispatched', 'completed'], true),
            'invoice' => $document->journal_entry_id !== null || in_array($document->status, ['posted', 'sent', 'partial', 'paid', 'overdue'], true),
            'customer_credit_note' => $document->journal_entry_id !== null || $document->status === 'posted',
            default => false,
        };
    }

    private function documentDate(string $type, $document): string
    {
        $field = match ($type) {
            'quotation' => 'quotation_date',
            'sales_order' => 'order_date',
            'delivery_order' => 'delivery_date',
            'invoice' => 'invoice_date',
            'customer_credit_note' => 'credit_note_date',
        };

        $value = $document->{$field} ?? null;

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?: now()->toDateString());
    }

    private function recalculateTotals(string $type, $document): void
    {
        match ($type) {
            'quotation' => $document->calculateTotals(),
            'invoice' => $document->calculateTotals(),
            'sales_order' => $document->calculateTotals(),
            'delivery_order' => $document->update(['total' => round((float) $document->lines()->sum('line_total'), 2)]),
            'customer_credit_note' => $document->update([
                'subtotal' => round((float) $document->lines()->sum('line_total'), 2),
                'tax_amount' => round((float) $document->lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_price * ((float) ($l->tax_rate ?? 0) / 100)), 2),
                'total_amount' => round((float) $document->lines()->sum('line_total'), 2),
            ]),
        };
    }
}
