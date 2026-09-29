<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxController extends Controller
{
    public function index()
    {
        return response()->json(
            Tax::query()->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'jurisdiction' => 'nullable|string|size:2',
            'regime' => 'nullable|string|max:30',
            'scope' => 'nullable|in:input,output,both',
            'treatment' => 'nullable|in:standard_rated,zero_rated,exempt,out_of_scope',
            'type' => 'nullable|in:gst,vat,sales,service,withholding,other',
            'rate' => 'required|numeric|min:0|max:100',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_inclusive' => 'boolean',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ]);

        if (! isset($validated['type'])) {
            $validated['type'] = 'vat';
        }

        if (! empty($validated['jurisdiction'])) {
            $validated['jurisdiction'] = strtoupper(trim($validated['jurisdiction']));
        }

        if (! empty($validated['is_default'])) {
            Tax::where('is_default', true)->update(['is_default' => false]);
        }

        $tax = Tax::create($validated);

        return response()->json($tax, 201);
    }

    public function update(Request $request, $tax)
    {
        $tax = Tax::findOrFail($tax);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|nullable|string|max:50',
            'jurisdiction' => 'sometimes|nullable|string|size:2',
            'regime' => 'sometimes|nullable|string|max:30',
            'scope' => 'sometimes|in:input,output,both',
            'treatment' => 'sometimes|in:standard_rated,zero_rated,exempt,out_of_scope',
            'type' => 'sometimes|in:gst,vat,sales,service,withholding,other',
            'rate' => 'sometimes|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'is_inclusive' => 'sometimes|boolean',
            'effective_from' => 'sometimes|nullable|date',
            'effective_to' => 'sometimes|nullable|date|after_or_equal:effective_from',
        ]);

        if (array_key_exists('jurisdiction', $validated) && $validated['jurisdiction'] !== null) {
            $validated['jurisdiction'] = strtoupper(trim($validated['jurisdiction']));
        }

        // MRD R1.2: a rule that is already live must never have its rate or
        // identity rewritten in place — historical determinations, posted GL
        // lines and filed returns reference this row. Identity changes on a
        // live rule close the old row and spawn a new version row instead.
        $identityFields = ['rate', 'jurisdiction', 'regime', 'scope', 'treatment', 'code'];
        $identityChanged = false;

        foreach ($identityFields as $field) {
            if (array_key_exists($field, $validated)
                && (string) $validated[$field] !== (string) $tax->{$field}) {
                $identityChanged = true;
                break;
            }
        }

        $today = now()->toDateString();
        $live = ($tax->effective_from === null || $tax->effective_from->toDateString() < $today)
            && ($tax->effective_to === null || $tax->effective_to->toDateString() >= $today);

        if (! $identityChanged || ! $live) {
            if (! empty($validated['is_default'])) {
                Tax::where('id', '!=', $tax->id)->where('is_default', true)->update(['is_default' => false]);
            }

            $tax->update($validated);

            return response()->json($tax->fresh());
        }

        $newFrom = ! empty($validated['effective_from'])
            ? \Carbon\Carbon::parse($validated['effective_from'])->toDateString()
            : $today;

        if ($tax->effective_from !== null && $tax->effective_from->toDateString() > $newFrom) {
            // Not yet effective in the future either — safe to edit in place.
            $tax->update($validated);

            return response()->json($tax->fresh());
        }

        $closeTo = \Carbon\Carbon::parse($newFrom)->subDay()->toDateString();

        $newTax = DB::transaction(function () use ($tax, $validated, $newFrom, $closeTo) {
            $merged = array_merge($tax->only([
                'name', 'code', 'jurisdiction', 'regime', 'scope', 'treatment',
                'type', 'rate', 'is_active', 'is_default', 'is_inclusive',
            ]), $validated);
            $merged['effective_from'] = $newFrom;
            $merged['effective_to'] = $validated['effective_to'] ?? null;

            $fresh = Tax::create($merged);

            $tax->update([
                'effective_to' => $tax->effective_to !== null && $tax->effective_to->toDateString() < $closeTo
                    ? $tax->effective_to->toDateString()
                    : $closeTo,
                'is_default' => false,
            ]);

            // Re-point the tenant default so resolution keeps working forward.
            $taxSetting = Setting::getValue('tax', []);
            if (($taxSetting['default_tax_id'] ?? null) === $tax->id) {
                $taxSetting['default_tax_id'] = $fresh->id;
                Setting::setValue('tax', $taxSetting);
            }

            return $fresh;
        });

        $payload = $newTax->fresh()->toArray();
        $payload['versioned'] = true;
        $payload['versioned_from'] = $tax->id;

        return response()->json($payload);
    }

    public function destroy($tax)
    {
        $tax = Tax::findOrFail($tax);
        $tax->delete();

        return response()->json(['message' => 'Tax deleted successfully']);
    }
}
