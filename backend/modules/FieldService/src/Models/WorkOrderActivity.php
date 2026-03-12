<?php

namespace Modules\FieldService\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderActivity extends Model
{
    protected $table = 'fs_work_order_activities';

    public $timestamps = false;

    protected $fillable = [
        'work_order_id',
        'user_id',
        'type',
        'body',
        'metadata',
        'attachments',
        'created_at',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'attachments' => 'array',
        'created_at'  => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
