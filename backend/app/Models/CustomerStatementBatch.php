<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue #607 (RPT-F1) — a queued batch statement run. Progress counters are
 * updated by GenerateCustomerStatementBatchJob after every customer so the
 * Sales statements tab can poll for progress.
 */
class CustomerStatementBatch extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'status',
        'filters',
        'options',
        'total_customers',
        'processed_customers',
        'generated_count',
        'sent_count',
        'failed_count',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'options' => 'array',
        'total_customers' => 'integer',
        'processed_customers' => 'integer',
        'generated_count' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(CustomerStatementDelivery::class, 'customer_statement_batch_id');
    }
}
