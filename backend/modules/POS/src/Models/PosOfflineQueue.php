<?php

namespace Modules\POS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PosOfflineQueue extends Model
{
    protected $table = 'pos_offline_queue';

    protected $fillable = [
        'queue_number',
        'operation_type',
        'status',
        'terminal_id',
        'session_id',
        'payload',
        'metadata',
        'idempotency_key',
        'sync_attempts',
        'last_sync_attempt',
        'sync_error',
        'conflict_data',
        'synced_entity_type',
        'synced_entity_id',
        'created_by',
        'queued_at',
        'synced_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'conflict_data' => 'array',
        'queued_at' => 'datetime',
        'synced_at' => 'datetime',
        'last_sync_attempt' => 'datetime',
    ];

    // ==========================================
    // STATE MACHINE
    // ==========================================

    /**
     * Allowed state transitions
     */
    protected static array $allowedTransitions = [
        'pending' => ['syncing', 'failed', 'conflict'],
        'syncing' => ['synced', 'failed', 'conflict'],
        'synced' => [],
        'failed' => ['pending', 'syncing'],
        'conflict' => ['pending', 'syncing'],
    ];

    /**
     * Validate state transition
     *
     * @throws \InvalidArgumentException
     */
    public function validateTransition(string $newStatus): void
    {
        $currentStatus = $this->status;

        if (! isset(self::$allowedTransitions[$currentStatus])) {
            throw new \InvalidArgumentException("Invalid current status: {$currentStatus}");
        }

        if (! in_array($newStatus, self::$allowedTransitions[$currentStatus])) {
            throw new \InvalidArgumentException(
                "Invalid state transition from '{$currentStatus}' to '{$newStatus}'. ".
                'Allowed transitions: '.implode(', ', self::$allowedTransitions[$currentStatus])
            );
        }
    }

    /**
     * Transition to new status with validation
     *
     * @throws \InvalidArgumentException
     */
    public function transitionTo(string $newStatus): void
    {
        $this->validateTransition($newStatus);
        $this->status = $newStatus;

        // Update timestamps based on status
        if ($newStatus === 'syncing') {
            $this->last_sync_attempt = now();
            $this->sync_attempts = ($this->sync_attempts ?? 0) + 1;
        } elseif ($newStatus === 'synced') {
            $this->synced_at = now();
        }
    }

    /**
     * Check if transition is allowed
     */
    public function canTransitionTo(string $newStatus): bool
    {
        try {
            $this->validateTransition($newStatus);

            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the synced entity (PosOrder, PosPayment, etc.)
     */
    public function syncedEntity(): MorphTo
    {
        return $this->morphTo('synced_entity');
    }

    /**
     * Generate unique queue number
     */
    public static function generateQueueNumber(): string
    {
        $date = now()->format('Ymd');
        $last = static::where('queue_number', 'like', "OFQ{$date}%")
            ->orderBy('queue_number', 'desc')
            ->first();

        if ($last && preg_match('/(\d+)$/', $last->queue_number, $matches)) {
            $number = intval($matches[1]) + 1;
        } else {
            $number = 1;
        }

        return 'OFQ'.$date.str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Check if item is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if item is synced
     */
    public function isSynced(): bool
    {
        return $this->status === 'synced';
    }

    /**
     * Check if item has conflict
     */
    public function hasConflict(): bool
    {
        return $this->status === 'conflict';
    }

    /**
     * Check if item failed
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($queue) {
            if (empty($queue->queue_number)) {
                $queue->queue_number = static::generateQueueNumber();
            }
            if (empty($queue->queued_at)) {
                $queue->queued_at = now();
            }
        });
    }
}
