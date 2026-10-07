<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesLineTaxResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #302 (X1/X2): read-only tax preview for the sales line editor.
 *
 * Returns exactly what the save path will book for a line — the same
 * SalesLineTaxResolver, at the document date — plus the output tax codes in
 * force on that date for the line's tax-code picker. The browser displays
 * this; it never computes a tax rate itself. Nothing is written.
 */
class SalesTaxPreviewController extends Controller
{
    public function __construct(private readonly SalesLineTaxResolver $resolver) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'variant_id' => 'nullable|integer',
            'line_type' => 'nullable|in:product,note,section',
            'date' => 'nullable|date',
            'tax_rule_id' => 'nullable|integer',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_inclusive' => 'nullable|boolean',
        ]);

        $line = array_filter([
            'product_id' => $validated['product_id'] ?? null,
            'variant_id' => $validated['variant_id'] ?? null,
            'line_type' => $validated['line_type'] ?? null,
            'tax_rule_id' => $validated['tax_rule_id'] ?? null,
        ], fn ($value) => $value !== null);

        if (array_key_exists('tax_rate', $validated) && $validated['tax_rate'] !== null) {
            $line['tax_rate'] = (float) $validated['tax_rate'];
        }
        if (array_key_exists('tax_inclusive', $validated) && $validated['tax_inclusive'] !== null) {
            $line['tax_inclusive'] = $request->boolean('tax_inclusive');
        }

        return response()->json([
            'data' => $this->resolver->preview(
                $line,
                $validated['date'] ?? null,
                isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            ),
        ]);
    }
}
