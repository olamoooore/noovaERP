<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubledgerTieout extends Model
{
    use HasUuids;

    protected $table = 'fci_subledger_tieouts';

    protected $fillable = [
        'tenant_id',
        'review_id',
        'entity_id',
        'account_code',
        'subledger_type',
        'gl_balance',
        'subledger_balance',
        'variance',
        'status',
        'metadata',
    ];

    protected $casts = [
        'gl_balance' => 'decimal:2',
        'subledger_balance' => 'decimal:2',
        'variance' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(AccountReview::class, 'review_id');
    }
}
