<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * #486: generic payment-method master (present in every tenant regardless of
 * country pack). mapping_key values mirror the historical hardcoded map in
 * PaymentPostingService so posting behaviour is unchanged for these codes.
 */
class PaymentMethodsSeeder extends Seeder
{
    public const METHODS = [
        ['code' => 'cash', 'label' => 'Cash', 'mapping_key' => 'cash', 'sort_order' => 10],
        ['code' => 'bank_transfer', 'label' => 'Bank Transfer', 'mapping_key' => 'bank', 'sort_order' => 20],
        ['code' => 'card', 'label' => 'Credit/Debit Card', 'mapping_key' => 'card_clearing', 'sort_order' => 30],
        ['code' => 'check', 'label' => 'Cheque', 'mapping_key' => 'cheque_clearing', 'requires_reference' => true, 'requires_cheque_fields' => true, 'sort_order' => 40],
        ['code' => 'gateway', 'label' => 'Payment Gateway', 'mapping_key' => 'gateway_receivable', 'sort_order' => 50],
        ['code' => 'other', 'label' => 'Other', 'mapping_key' => 'bank', 'sort_order' => 60],
    ];

    public function run(): void
    {
        foreach (self::METHODS as $method) {
            PaymentMethod::updateOrCreate(
                ['code' => $method['code']],
                $method + [
                    'requires_reference' => $method['requires_reference'] ?? false,
                    'requires_cheque_fields' => $method['requires_cheque_fields'] ?? false,
                    'country_code' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
