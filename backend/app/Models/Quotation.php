<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($quotation) {
            if (!$quotation->quotation_number) {
                $quotation->quotation_number = static::generateQuotationNumber();
            }
            if (!$quotation->quotation_date) {
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
        'total',
        'notes',
        'terms',
        'customer_reference',
        'internal_reference',
        'attention_to',
        'contact_email',
        'contact_phone',
        'billing_address_snapshot',
        'shipping_address_snapshot',
        'sales_order_id',
        'created_by',
    ];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
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

    public static function generateQuotationNumber(): string
    {
        $year = date('Y');
        $prefix = "QUO-{$year}-";

        try {
            \Illuminate\Support\Facades\DB::statement("CREATE SEQUENCE IF NOT EXISTS quotation_number_seq START 1");

            $maxNum = (int) (\Illuminate\Support\Facades\DB::selectOne("
                SELECT MAX(CAST(SUBSTRING(quotation_number FROM '\d{4}$') AS INTEGER)) as m
                FROM quotations
            ")->m ?? 0);

            if ($maxNum > 0) {
                \Illuminate\Support\Facades\DB::statement(
                    "SELECT setval('quotation_number_seq', GREATEST((SELECT last_value FROM quotation_number_seq), {$maxNum}))"
                );
            }

            $seqVal = (int) \Illuminate\Support\Facades\DB::selectOne("SELECT nextval('quotation_number_seq') AS val")->val;
            return $prefix . str_pad($seqVal, 4, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Quotation number sequence unavailable, using fallback', [
                'error' => $e->getMessage(),
            ]);
        }

        $latest = static::withTrashed()
            ->where('quotation_number', 'like', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();
        $number = $latest ? (int) substr($latest->quotation_number, -4) + 1 : 1;
        return $prefix . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->lines->sum(function ($line) {
            return $line->line_total - $line->tax_amount;
        });
        $this->tax_amount = $this->lines->sum('tax_amount');
        $this->total = $this->subtotal + $this->tax_amount - ($this->discount_amount ?? 0);
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
}
