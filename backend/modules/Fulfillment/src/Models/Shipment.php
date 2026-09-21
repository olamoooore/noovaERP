<?php

namespace Modules\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'shipments';

    protected $fillable = [
        'tenant_id',
        'shipment_number',
        'provider_id',
        'order_id',
        'order_type',
        'status',
        'service_type',
        'tracking_number',
        'tracking_url',
        'label_url',
        'label_format',
        'ship_date',
        'estimated_delivery',
        'actual_delivery',
        'shipping_cost',
        'currency_code',
        'weight',
        'weight_unit',
        'dimensions',
        'shipper',
        'recipient',
        'signature_required',
        'saturday_delivery',
        'insurance_enabled',
        'insurance_value',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'ship_date' => 'date',
        'estimated_delivery' => 'date',
        'actual_delivery' => 'datetime',
        'dimensions' => 'array',
        'shipper' => 'array',
        'recipient' => 'array',
        'metadata' => 'array',
        'signature_required' => 'boolean',
        'saturday_delivery' => 'boolean',
        'insurance_enabled' => 'boolean',
        'insurance_value' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'weight' => 'decimal:2',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CREATED = 'created';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_EXCEPTION = 'exception';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($shipment) {
            if (empty($shipment->shipment_number)) {
                $shipment->shipment_number = static::generateShipmentNumber();
            }
        });
    }

    public static function generateShipmentNumber(): string
    {
        $latest = static::withTrashed()
            ->where('shipment_number', 'like', 'SHP-'.date('Y').'-%')
            ->orderBy('id', 'desc')
            ->first();

        $number = $latest ? (int) substr($latest->shipment_number, -5) + 1 : 1;

        return 'SHP-'.date('Y').'-'.str_pad($number, 5, '0', STR_PAD_LEFT);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(FulfillmentProvider::class, 'provider_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class, 'shipment_id');
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class, 'shipment_id')->orderBy('timestamp', 'desc');
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_DELIVERED]);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CREATED]);
    }
}
