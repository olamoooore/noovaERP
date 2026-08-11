<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class MedicalCase extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'case_no',
        'case_tag',
        'customer_id',
        'invoice_id',
        'bonus_scheme_id',
        'delivery_address_id',
        'doctor_contact_id',
        'requestor_contact_id',
        'buyer_contact_id',
        'patient_reference',
        'patient_name_encrypted',
        'case_date',
        'procedure_date',
        'location',
        'delivered_to',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'case_date' => 'date',
        'procedure_date' => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (! $model->case_no) {
                $model->case_no = static::generateCaseNumber();
            }
        });
    }

    public static function generateCaseNumber(): string
    {
        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $number = static::generateCaseNumberRaw();
            if (! static::withTrashed()->where('case_no', $number)->exists()) {
                return $number;
            }
            Log::warning('Medical case number collision, retrying', ['attempt' => $attempt]);
        }
        return 'CASE-' . date('Y') . '-' . date('is');
    }

    private static function generateCaseNumberRaw(): string
    {
        try {
            return app(\App\Services\DocumentNumberingService::class)
                ->generate('medical_case', 'CASE-{YYYY}-{SEQ:4}');
        } catch (\Throwable $e) {
            Log::warning('DocumentNumberingService failed for medical case, using fallback');
        }

        $latest = static::withTrashed()
            ->where('case_no', 'like', 'CASE-' . date('Y') . '-%')
            ->orderBy('id', 'desc')
            ->first();

        $number = $latest ? (int) substr($latest->case_no, -4) + 1 : 1;

        return 'CASE-' . date('Y') . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    // Status helpers
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isAllocated(): bool
    {
        return $this->status === 'allocated';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isInvoiced(): bool
    {
        return $this->status === 'invoiced';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function bonusScheme(): BelongsTo
    {
        return $this->belongsTo(BonusScheme::class);
    }

    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'delivery_address_id');
    }

    public function doctorContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'doctor_contact_id');
    }

    public function requestorContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'requestor_contact_id');
    }

    public function buyerContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'buyer_contact_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MedicalCaseItem::class);
    }

    // Scopes
    public function getPatientNameAttribute(): ?string
    {
        return $this->patient_name_encrypted;
    }

    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'cancelled');
    }
}
