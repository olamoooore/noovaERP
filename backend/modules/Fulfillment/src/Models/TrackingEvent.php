<?php

namespace Modules\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingEvent extends Model
{
    use HasUuids;

    protected $table = 'tracking_events';

    protected $fillable = [
        'shipment_id',
        'status',
        'status_code',
        'description',
        'location',
        'timestamp',
        'signed_by',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}
