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
            'default_tax_rate' => null,
            'default_tax_name' => null
        ]);
        
        $mail = Setting::getValue('mail_config', [
            'driver' => 'smtp',
            'host' => 'smtp.gmail.com',
            'port' => '587',
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_address' => 'noreply@example.com',
            'from_name' => 'NoovaERP',
        ]);

        // Mask password for security
        $mail['password'] = !empty($mail['password']) ? '********' : '';

        $defaultTax = null;
        if (!empty($tax['default_tax_id'])) {
            $defaultTax = Tax::query()->whereKey($tax['default_tax_id'])->first();
        }

        $tax['default_tax_rate'] = $defaultTax?->rate ?? 0;
        $tax['default_tax_name'] = $defaultTax?->name ?? 'None';

        return response()->json([
            'currency' => $currency,
            'tax' => $tax,
            'mail' => $mail,
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

            'mail' => 'nullable|array',
            'mail.driver' => 'nullable|string',
            'mail.host' => 'nullable|string',
            'mail.port' => 'nullable|integer',
            'mail.encryption' => 'nullable|string',
            'mail.username' => 'nullable|string',
            'mail.password' => 'nullable|string',
            'mail.from_address' => 'nullable|email',
            'mail.from_name' => 'nullable|string',
        ]);

        if (array_key_exists('currency', $validated)) {
            Setting::setValue('currency', array_merge(['code' => 'USD'], $validated['currency'] ?? []));
        }

        if (array_key_exists('tax', $validated)) {
            Setting::setValue('tax', array_merge([
                'enabled' => true,
                'pricing_mode' => 'exclusive',
                'default_tax_id' => null,
            ], $validated['tax'] ?? []));
        }

        if (array_key_exists('mail', $validated)) {
            $defaultMail = [
                'driver' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => '587',
                'encryption' => 'tls',
                'username' => '',
                'password' => '',
                'from_address' => 'noreply@example.com',
                'from_name' => 'NoovaERP',
            ];
            
            $existingConfig = Setting::getValue('mail_config', $defaultMail);
            $inputConfig = $validated['mail'] ?? [];

            // Password handling: if empty or masked, keep the old password
            if (empty($inputConfig['password']) || $inputConfig['password'] === '********') {
                $inputConfig['password'] = $existingConfig['password'] ?? '';
            }

            // Ensure structure is maintained by merging with default first, then existing, then input
            // Actually, merging existing with input is safer to preserve other fields
            $finalConfig = array_merge($existingConfig, $inputConfig);
            
            // Ensure all keys from default exist if they were missing in existing/input
            $finalConfig = array_merge($defaultMail, $finalConfig);

            Setting::setValue('mail_config', $finalConfig);
        }

        return $this->show();
    }
}
