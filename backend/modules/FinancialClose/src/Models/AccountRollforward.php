<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountRollforward extends Model
{
    use HasUuids;

    protected $table = 'fci_account_rollforwards';

    protected $fillable = [
        'tenant_id',
        'review_id',
        'entity_id',
        'account_code',
        'period_id',
        'opening_balance',
        'movements',
        'closing_balance',
        'movement_breakdown',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'movements' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'movement_breakdown' => 'array',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(AccountReview::class, 'review_id');
    }
}
