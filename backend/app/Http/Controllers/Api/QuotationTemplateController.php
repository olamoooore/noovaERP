<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuotationTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issue #338 ([SALES-ODOO]): quotation templates — default lines (optionally
 * marked optional), notes, terms and validity days.
 *
 * Templates hold no prices: a quotation created from one (POST /quotations
 * with quotation_template_id) prices and taxes its lines through the same
 * PricingService / SalesLineTaxResolver path as every other quotation.
 */
class QuotationTemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = QuotationTemplate::query()->with('lines.product:id,name,sku')->orderBy('name')->orderBy('id');

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(int $id)
    {
        $template = QuotationTemplate::with('lines.product:id,name,sku')->find($id);

        if (! $template) {
            return response()->json(['message' => 'Quotation template not found'], 404);
        }

        return response()->json(['data' => $template]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, true);

        $template = DB::transaction(function () use ($validated) {
            $template = QuotationTemplate::create([
                'name' => $validated['name'],
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'validity_days' => $validated['validity_days'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'created_by' => Auth::id(),
            ]);

            $this->replaceLines($template, $validated['lines']);

            return $template;
        });

        return response()->json([
            'message' => 'Quotation template created successfully',
            'data' => $template->fresh('lines.product:id,name,sku'),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $template = QuotationTemplate::find($id);

        if (! $template) {
            return response()->json(['message' => 'Quotation template not found'], 404);
        }

        $validated = $this->validatePayload($request, false);

        DB::transaction(function () use ($template, $validated) {
            $template->update(array_intersect_key($validated, array_flip([
                'name', 'notes', 'terms', 'validity_days', 'is_active',
            ])));

            if (array_key_exists('lines', $validated)) {
                $this->replaceLines($template, $validated['lines']);
            }
        });

        return response()->json([
            'message' => 'Quotation template updated successfully',
            'data' => $template->fresh('lines.product:id,name,sku'),
        ]);
    }

    public function destroy(int $id)
    {
        $template = QuotationTemplate::find($id);

        if (! $template) {
            return response()->json(['message' => 'Quotation template not found'], 404);
        }

        // Quotations created from it keep their own copied lines; the FK on
        // quotations.quotation_template_id is nulled on delete.
        $template->delete();

        return response()->json(['message' => 'Quotation template deleted successfully']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $validated = $request->validate([
            'name' => "{$required}|string|max:255",
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'validity_days' => 'nullable|integer|min:0|max:3650',
            'is_active' => 'nullable|boolean',
            'lines' => "{$required}|array|min:1",
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.description' => 'required|string',
            'lines.*.product_notes' => 'nullable|string',
            // Same quantity domain rule as the quotation line (#295).
            'lines.*.quantity' => 'nullable|numeric|gt:0|decimal:0,4',
            'lines.*.uom_id' => 'nullable|integer|exists:product_uoms,id',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.is_optional' => 'nullable|boolean',
        ]);

        $errors = [];
        foreach ($validated['lines'] ?? [] as $index => $line) {
            if (($line['line_type'] ?? 'product') !== 'product') {
                continue;
            }
            if (empty($line['product_id'])) {
                $errors["lines.{$index}.product_id"] = 'Product lines require a product.';
            }
            if (! isset($line['quantity'])) {
                $errors["lines.{$index}.quantity"] = 'Product lines require a quantity.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(QuotationTemplate $template, array $lines): void
    {
        $template->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $isProduct = ($line['line_type'] ?? 'product') === 'product';

            $template->lines()->create([
                'line_type' => $line['line_type'] ?? 'product',
                'sequence' => $index,
                'product_id' => $isProduct ? ($line['product_id'] ?? null) : null,
                'description' => $line['description'],
                'product_notes' => $line['product_notes'] ?? null,
                'quantity' => $isProduct ? ($line['quantity'] ?? null) : null,
                'uom_id' => $isProduct ? ($line['uom_id'] ?? null) : null,
                'discount_amount' => $isProduct ? ($line['discount_amount'] ?? 0) : 0,
                'is_optional' => (bool) ($line['is_optional'] ?? false),
            ]);
        }
    }
}
