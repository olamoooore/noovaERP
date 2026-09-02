<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixed Asset - Long-term tangible property
 * Tracks assets, depreciation, and disposal
 */
class FixedAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_number',
        'name',
        'description',
        'category',
        'purchase_date',
        'purchase_cost',
        'vendor_id',
        'invoice_reference',
        'depreciation_method',
        'depreciation_frequency',
        'useful_life_years',
        'useful_life_units',
        'salvage_value',
        'depreciation_start_date',
        'asset_account_id',
        'depreciation_account_id',
        'accumulated_depreciation_account_id',
        'location',
        'department_id',
        'responsible_person',
        'serial_number',
        'status',
        'disposal_date',
        'disposal_amount',
        'disposal_method',
        'accumulated_depreciation',
        'book_value',
        'last_depreciation_date',
        'is_active',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'depreciation_start_date' => 'date',
        'disposal_date' => 'date',
        'last_depreciation_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'disposal_amount' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'book_value' => 'decimal:2',
        'useful_life_years' => 'integer',
        'useful_life_units' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the asset account
     */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'asset_account_id');
    }

    /**
     * Get the depreciation expense account
     */
    public function depreciationAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'depreciation_account_id');
    }

    /**
     * Get the accumulated depreciation account
     */
    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'accumulated_depreciation_account_id');
    }

    /**
     * Get the vendor
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the department
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get all depreciation schedules
     */
    public function depreciationSchedules(): HasMany
    {
        return $this->hasMany(DepreciationSchedule::class, 'asset_id');
    }

    /**
     * Calculate current book value
     */
    public function calculateBookValue(): float
    {
        return (float) $this->purchase_cost - (float) $this->accumulated_depreciation;
    }

    /**
     * Check if asset is fully depreciated
     */
    public function isFullyDepreciated(): bool
    {
        return $this->calculateBookValue() <= (float) $this->salvage_value;
    }

    /**
     * Scope to get only active assets
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('status', 'active');
    }

    /**
     * Scope to get assets by category
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to get assets by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
