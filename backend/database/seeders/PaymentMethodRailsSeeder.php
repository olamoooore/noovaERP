<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * #486: country-localisation rails contributed by a pack (MY/SG/generic).
 * Rails reuse EXISTING mapping keys only (bank / gateway_receivable) — no new
 * GL mappings and no statutory values are invented here.
 */
class PaymentMethodRailsSeeder extends Seeder
{
    public string $countryCode = '';

    public const RAILS = [
        'MY' => [
            ['code' => 'duitnow', 'label' => 'DuitNow', 'mapping_key' => 'bank', 'sort_order' => 110],
            ['code' => 'fpx', 'label' => 'FPX', 'mapping_key' => 'bank', 'sort_order' => 120],
            ['code' => 'grabpay', 'label' => 'GrabPay', 'mapping_key' => 'gateway_receivable', 'sort_order' => 130],
        ],
        'SG' => [
            ['code' => 'paynow', 'label' => 'PayNow', 'mapping_key' => 'bank', 'sort_order' => 110],
            ['code' => 'grabpay', 'label' => 'GrabPay', 'mapping_key' => 'gateway_receivable', 'sort_order' => 130],
        ],
    ];

    public function run(): void
    {
        $country = strtoupper($this->countryCode);
        foreach (self::RAILS[$country] ?? [] as $rail) {
            PaymentMethod::updateOrCreate(
                ['code' => $rail['code']],
                $rail + [
                    'requires_reference' => false,
                    'requires_cheque_fields' => false,
                    'country_code' => $country,
                    'is_active' => true,
                ],
            );
        }
    }
}
