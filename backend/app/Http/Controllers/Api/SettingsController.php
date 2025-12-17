<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tax;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        $currency = Setting::getValue('currency', ['code' => 'USD']);
        $tax = Setting::getValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive', // exclusive | inclusive
            'default_tax_id' => null,
        ]);

        $defaultTax = null;
        if (!empty($tax['default_tax_id'])) {
            $defaultTax = Tax::query()->whereKey($tax['default_tax_id'])->first();
        }

        $tax['default_tax_rate'] = $defaultTax?->rate;
        $tax['default_tax_name'] = $defaultTax?->name;

        return response()->json([
            'currency' => $currency,
            'tax' => $tax,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'currency' => 'nullable|array',
            'currency.code' => 'nullable|string|max:10',

            'tax' => 'nullable|array',
            'tax.enabled' => 'nullable|boolean',
            'tax.pricing_mode' => 'nullable|in:exclusive,inclusive',
            'tax.default_tax_id' => 'nullable|integer',
        ]);

        if (array_key_exists('currency', $validated)) {
            $currency = $validated['currency'] ?? null;
            if (is_array($currency)) {
                Setting::setValue('currency', array_merge(['code' => 'USD'], $currency));
            }
        }

        if (array_key_exists('tax', $validated)) {
            $tax = $validated['tax'] ?? null;
            if (is_array($tax)) {
                Setting::setValue('tax', array_merge([
                    'enabled' => true,
                    'pricing_mode' => 'exclusive',
                    'default_tax_id' => null,
                ], $tax));
            }
        }

        return $this->show();
    }
}
