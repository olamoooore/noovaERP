<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($quotation) {
            if (! $quotation->quotation_number) {
                $quotation->quotation_number = static::generateQuotationNumber();
            }
            if (! $quotation->quotation_date) {
                $quotation->quotation_date = now()->toDateString();
            }
        });
    }

    protected $fillable = [
        'quotation_number',
        'customer_id',
        'quotation_date',
        'valid_until',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'discount_type',
        'discount_value',
        'total',
        'notes',
        'terms',
        'customer_reference',
        'internal_reference',
        'credit_terms',
        'attention_to',
        'billing_contact_id',
        'shipping_contact_id',
        'contact_email',
        'contact_phone',
        'salesperson',
        'delivery_lead_time',
        'shipping_method',
        'shipping_terms',
        'validity',
        'case_number',
        'date_of_case',
        'case_location',
        'patient_name',
        'patient_name_encrypted',
        'cost_center_id',
        'delivery_date',
        'pr_no',
        'contract_no',
        'plant_code',
        'company_code',
        'billing_address_snapshot',
        'shipping_address_snapshot',
        'sales_order_id',
        'created_by',
        // Issue #178: rejection/replacement revision chain.
        'correction_group_id',
        'version_number',
        'is_active_version',
        'original_document_id',
        'replacement_document_id',
        'superseded_at',
        'correction_type',
        'correction_reason_code',
        'correction_reason_text',
    ];

    protected $casts = [
        'patient_name_encrypted' => \App\Casts\EncryptedOrLegacyString::class,
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_value' => 'decimal:4',
        'total' => 'decimal:2',
        'date_of_case' => 'date',
        'delivery_date' => 'date',
        'is_active_version' => 'boolean',
        'superseded_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'sales_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'quotation_id');
    }

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(\Modules\Sales\Models\DeliveryOrder::class, 'quotation_id');
    }

    public function billingContact(): BelongsTo
    {
        return $this->belongsTo(\Modules\Contact\src\Models\CoreContact::class, 'billing_contact_id');
    }

    public function shippingContact(): BelongsTo
    {
        return $this->belongsTo(\Modules\Contact\src\Models\CoreContact::class, 'shipping_contact_id');
    }

    public static function generateQuotationNumber(): string
    {
        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $number = static::generateQuotationNumberRaw();
            if (! static::withTrashed()->where('quotation_number', $number)->exists()) {
                return $number;
            }
            \Illuminate\Support\Facades\Log::warning('Quotation number collision, retrying', [
                'number' => $number,
                'attempt' => $attempt + 1,
            ]);
        }

        // Final fallback: use timestamp-based suffix
        return 'QUO-'.date('Y').'-'.date('is');
    }

    private static function generateQuotationNumberRaw(): string
    {
        try {
            return app(\App\Services\DocumentNumberingService::class)
                ->generate('quotation', 'QUO-{YYYY}-{SEQ:4}');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('DocumentNumberingService failed, using fallback', [
                'error' => $e->getMessage(),
            ]);
        }

        $year = date('Y');
        $prefix = "QUO-{$year}-";
        $latest = static::withTrashed()
            ->where('quotation_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->first();
        $number = $latest ? (int) substr($latest->quotation_number, -4) + 1 : 1;

        return $prefix.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(): void
    {
        $subtotal = 0;
        $taxAmount = 0;
        $grossSubtotal = 0;

        // Recompute each line's tax_amount + line_total from base fields so the
        // header totals are always derived from accurate line data.
        foreach ($this->lines as $line) {
            $line->calculateLineTotals();
            if ($line->isDirty(['tax_amount', 'line_total'])) {
                $line->save();
            }
            $subtotal += (float) $line->line_total - (float) $line->tax_amount;
            $taxAmount += (float) $line->tax_amount;
            if (! in_array($line->line_type, ['note', 'section'], true)) {
                $grossSubtotal += (float) $line->quantity * (float) $line->unit_price;
            }
        }

        $this->subtotal = round($subtotal, 2);
        $discount = (float) ($this->discount_amount ?? 0);
        if ($this->discount_value !== null) {
            $discount = $this->discount_type === 'percentage'
                ? round($grossSubtotal * ((float) $this->discount_value / 100), 2)
                : round((float) $this->discount_value, 2);
        }
        $discount = min($discount, (float) $this->subtotal);
        $this->discount_amount = round($discount, 2);

        // Header discounts reduce the taxable amount. Distribute the document
        // discount proportionally across lines so mixed tax rates retain their
        // relative contribution without recalculating each persisted line.
        if ($discount > 0 && $this->subtotal > 0) {
            $taxableAmount = max($this->subtotal - $discount, 0);
            $taxRatio = $taxableAmount / $this->subtotal;
            $this->tax_amount = round($taxAmount * $taxRatio, 2);
        } else {
            $this->tax_amount = round($taxAmount, 2);
        }

        $this->total = round(max($this->subtotal - $discount, 0) + $this->tax_amount, 2);
        $this->save();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    // Issue #222: patient name is PHI — encrypted at rest, exposed as the
    // virtual patient_name attribute so existing callers keep working.
    protected $appends = ['patient_name'];

    protected $hidden = ['patient_name_encrypted'];

    public function getPatientNameAttribute(): ?string
    {
        return $this->patient_name_encrypted;
    }

    public function setPatientNameAttribute(mixed $value): void
    {
        $this->attributes['patient_name_encrypted'] = ($value === null || $value === '')
            ? null
            : \Illuminate\Support\Facades\Crypt::encryptString((string) $value);
    }

}
