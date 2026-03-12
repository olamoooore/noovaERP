<?php

namespace Modules\FieldService\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorksheetResponse extends Model
{
    protected $table = 'fs_worksheet_responses';

    protected $fillable = [
        'work_order_id',
        'template_id',
        'responses',
        'technician_signature',
        'customer_signature',
        'completed_by',
        'completed_at',
        'pdf_path',
    ];

    protected $casts = [
        'responses'    => 'array',
        'completed_at' => 'datetime',
    ];

    // ──────────────── RELATIONSHIPS ────────────────

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorksheetTemplate::class, 'template_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    // ──────────────── HELPERS ──────────────────────

    public function hasTechnicianSignature(): bool
    {
        return !empty($this->technician_signature);
    }

    public function hasCustomerSignature(): bool
    {
        return !empty($this->customer_signature);
    }

    public function isFullySigned(): bool
    {
        return $this->hasTechnicianSignature() && $this->hasCustomerSignature();
    }
}
