<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollItem extends Model
{
    use SoftDeletes;

    protected $table = 'hrm_payroll_items';

    protected $fillable = [
        'payroll_id',
        'type',
        'category',
        'amount',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }
}
