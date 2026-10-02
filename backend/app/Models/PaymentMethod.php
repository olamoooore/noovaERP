<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #486: tenant-configurable payment-method master row.
 *
 * One row = one selectable method. `mapping_key` feeds the posting engine
 * (method → account_mappings key → GL), `default_bank_account_id` optionally
 * pins the deposit journal, and the requires_* flags drive form validation.
 * Country packs (MY/SG) contribute extra rows tagged with country_code.
 */
class PaymentMethod extends Model
{
    protected $fillable = [
        'code',
        'label',
        'mapping_key',
        'default_bank_account_id',
        'requires_reference',
        'requires_cheque_fields',
        'country_code',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'default_bank_account_id' => 'integer',
        'requires_reference' => 'boolean',
        'requires_cheque_fields' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function defaultBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'default_bank_account_id');
    }
}
