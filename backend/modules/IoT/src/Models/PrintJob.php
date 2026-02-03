<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJob extends Model
{
    use HasUuids;

    protected $table = 'print_jobs';

    protected $fillable = [
        'device_id',
        'agent_id',
        'job_type',
        'status',
        'content',
        'metadata',
        'error_message',
        'sent_at',
        'completed_at',
    ];

    protected $casts = [
        'content' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Job types
     */
    const TYPE_PRODUCT_LABEL = 'product_label';
    const TYPE_BARCODE_LABEL = 'barcode_label';
    const TYPE_RECEIPT = 'receipt';
    const TYPE_RAW_TSPL = 'raw_tspl';
    const TYPE_RAW_ESCPOS = 'raw_escpos';

    /**
     * Job statuses
     */
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_PRINTING = 'printing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Get the device configuration that owns this print job.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(DeviceConfiguration::class, 'device_id');
    }

    /**
     * Mark job as sent to agent.
     */
    public function markAsSent(): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark job as completed.
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark job as failed.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }
}
