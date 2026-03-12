<?php

namespace Modules\FieldService\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderType extends Model
{
    protected $table = 'fs_work_order_types';

    protected $fillable = [
        'name',
        'color',
        'default_duration_hours',
        'auto_invoice',
        'is_active',
    ];

    protected $casts = [
        'default_duration_hours' => 'decimal:2',
        'auto_invoice'           => 'boolean',
        'is_active'              => 'boolean',
    ];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'type_id');
    }
}
