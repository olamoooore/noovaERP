<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * ProductAuditLog Model
 * Immutable audit trail for product changes (compliance)
 */
class ProductAuditLog extends Model
{
    use HasFactory;

    // Disable timestamps since we only use created_at
    public $timestamps = false;

    /**
     * Issue #129: "immutable" was only a docblock claim — block Eloquent
     * updates/deletes like PostingAuditLog does (DB-level triggers in
     * migration 2026_09_22_000018 cover raw writes).
     */
    public static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            throw new \RuntimeException(
                'ProductAuditLog records are immutable and cannot be updated. '
                .'Create a new audit entry instead.'
            );
        });

        static::deleting(function ($model) {
            throw new \RuntimeException(
                'ProductAuditLog records are immutable and cannot be deleted.'
            );
        });
    }

    protected $fillable = [
        'product_id',
        'user_id',
        'action',
        'field_name',
        'old_value',
        'new_value',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_STATUS_CHANGED = 'status_changed';

    public const ACTION_PRICE_CHANGED = 'price_changed';

    public const ACTION_STOCK_CHANGED = 'stock_changed';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_DISCONTINUED = 'discontinued';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create an audit log entry
     */
    public static function log(
        int $productId,
        string $action,
        ?string $fieldName = null,
        $oldValue = null,
        $newValue = null
    ): self {
        return self::create([
            'product_id' => $productId,
            'user_id' => TenantUserHelper::requireTenantUserId(),
            'action' => $action,
            'field_name' => $fieldName,
            'old_value' => is_array($oldValue) ? json_encode($oldValue) : $oldValue,
            'new_value' => is_array($newValue) ? json_encode($newValue) : $newValue,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Log multiple field changes
     */
    public static function logChanges(int $productId, array $original, array $changes): void
    {
        foreach ($changes as $field => $newValue) {
            $oldValue = $original[$field] ?? null;

            // Skip if value hasn't changed
            if ($oldValue === $newValue) {
                continue;
            }

            // Determine action based on field
            $action = match (true) {
                $field === 'status' => self::ACTION_STATUS_CHANGED,
                in_array($field, ['price', 'cost', 'wholesale_price', 'msrp']) => self::ACTION_PRICE_CHANGED,
                in_array($field, ['stock_quantity']) => self::ACTION_STOCK_CHANGED,
                default => self::ACTION_UPDATED,
            };

            self::log($productId, $action, $field, $oldValue, $newValue);
        }
    }
}
