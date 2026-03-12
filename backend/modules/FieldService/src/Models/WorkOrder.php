<?php

namespace Modules\FieldService\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Customer;
use App\Models\Invoice;

class WorkOrder extends Model
{
    use SoftDeletes;

    protected $table = 'field_service_work_orders';

    protected $fillable = [
        'reference',
        'title',
        'description',
        'type_id',
        'status',
        'priority',
        'customer_id',
        'site_name',
        'site_address',
        'site_lat',
        'site_lng',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'team_id',
        'lead_technician_id',
        'invoice_id',
        'sales_order_id',
        'internal_notes',
        'customer_rating',
        'customer_feedback',
        'requires_signature',
        'technician_signed_at',
        'customer_signed_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'scheduled_start'      => 'datetime',
        'scheduled_end'        => 'datetime',
        'actual_start'         => 'datetime',
        'actual_end'           => 'datetime',
        'technician_signed_at' => 'datetime',
        'customer_signed_at'   => 'datetime',
        'completed_at'         => 'datetime',
        'requires_signature'   => 'boolean',
        'site_lat'             => 'decimal:7',
        'site_lng'             => 'decimal:7',
    ];

    // ──────────────── RELATIONSHIPS ────────────────

    public function type(): BelongsTo
    {
        return $this->belongsTo(WorkOrderType::class, 'type_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function leadTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_technician_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'fs_work_order_technicians', 'work_order_id', 'user_id')
            ->withPivot('role', 'assigned_at')
            ->withTimestamps();
    }

    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class);
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(TimeLog::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(WorkOrderActivity::class)->orderBy('created_at', 'asc');
    }

    // ──────────────── COMPUTED ────────────────

    public function getTotalPartsAmountAttribute(): float
    {
        return (float) $this->parts->sum(fn($p) => $p->quantity * $p->unit_price);
    }

    public function getTotalLaborAmountAttribute(): float
    {
        return (float) $this->timeLogs
            ->where('is_billable', true)
            ->sum(fn($t) => $t->duration_hours * $t->hourly_rate);
    }

    public function getTotalAmountAttribute(): float
    {
        return $this->total_parts_amount + $this->total_labor_amount;
    }

    public function getDurationMinutesAttribute(): ?int
    {
        if ($this->scheduled_start && $this->scheduled_end) {
            return (int) $this->scheduled_start->diffInMinutes($this->scheduled_end);
        }
        return null;
    }

    // ──────────────── STATIC HELPERS ────────────────

    public static function generateReference(): string
    {
        $year  = now()->format('Y');
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;
        return sprintf('FS-%s-%05d', $year, $count);
    }

    // ──────────────── ALLOWED TRANSITIONS ────────────────

    public static array $transitions = [
        'draft'       => ['scheduled', 'cancelled'],
        'scheduled'   => ['in_progress', 'cancelled', 'draft'],
        'in_progress' => ['completed', 'on_hold', 'cancelled'],
        'on_hold'     => ['in_progress', 'cancelled'],
        'completed'   => ['invoiced'],
        'invoiced'    => [],
        'cancelled'   => [],
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, static::$transitions[$this->status] ?? []);
    }
}
