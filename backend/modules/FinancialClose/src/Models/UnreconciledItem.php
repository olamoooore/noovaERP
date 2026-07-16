<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnreconciledItem extends Model
{
    use HasUuids;

    protected $table = 'fci_unreconciled_items';

    protected $fillable = [
        'tenant_id',
        'review_id',
        'entity_id',
        'account_code',
        'amount',
        'currency',
        'status',
        'description',
        'aging_days',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'aging_days' => 'integer',
        'metadata' => 'array',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(AccountReview::class, 'review_id');
    }
}
