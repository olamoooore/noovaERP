<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    protected $table = 'plan_prices';

    protected $fillable = [
        'plan_version_id',
        'currency',
        'billing_interval',
        'unit_amount',
    ];

    protected $casts = [
        'unit_amount' => 'integer',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }

    /** Returns the unit_amount in major currency units (e.g. dollars). */
    public function getAmountInMajorUnitsAttribute(): float
    {
        return $this->unit_amount / 100;
    }

    public function toAuditSnapshot(): array
    {
        return $this->only(['id', 'plan_version_id', 'currency', 'billing_interval', 'unit_amount']);
    }
}
