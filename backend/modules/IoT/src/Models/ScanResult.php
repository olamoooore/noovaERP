<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanResult extends Model
{
    use HasUuids;

    protected $table = 'scan_results';

    protected $fillable = [
        'device_id',
        'agent_id',
        'barcode',
        'format',
        'scanned_at',
        'metadata',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the device configuration that owns this scan result.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(DeviceConfiguration::class, 'device_id');
    }
}
