<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPolicy extends Model
{
    protected $table = 'accounting_policies';

    protected $fillable = [
        'depreciation_method',
        'capitalization_threshold',
        'inventory_valuation_method',
        'revenue_recognition_policy',
        'expense_recognition_policy',
        'bad_debt_policy',
        'notes',
    ];

    protected $casts = [
        'capitalization_threshold' => 'decimal:2',
    ];

    /**
     * Get the current tenant's accounting policy (singleton per tenant).
     */
    public static function current(): self
    {
        $policy = static::first();

        if (! $policy) {
            $policy = static::create([
                'depreciation_method' => 'straight_line',
                'capitalization_threshold' => 1000.00,
                'inventory_valuation_method' => 'fifo',
                'revenue_recognition_policy' => 'accrual',
                'expense_recognition_policy' => 'matching',
                'bad_debt_policy' => 'allowance_method',
            ]);
        }

        return $policy;
    }
}
