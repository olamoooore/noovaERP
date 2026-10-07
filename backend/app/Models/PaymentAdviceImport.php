<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * #1001: one uploaded payment advice with its parsed lines, invoice matches,
 * variance and the payments created on confirmation.
 */
class PaymentAdviceImport extends Model
{
    protected $fillable = [
        'original_name',
        'file_path',
        'mime_type',
        'bank_code',
        'extractor',
        'status',
        'customer_id',
        'payer_name',
        'value_date',
        'currency_code',
        'total_amount',
        'extracted_total',
        'variance',
        'bank_reference',
        'lines',
        'suggestions',
        'warnings',
        'payment_ids',
        'created_by',
        'confirmed_at',
        'confirmed_by',
    ];

    protected $casts = [
        'value_date' => 'date',
        'lines' => 'array',
        'suggestions' => 'array',
        'warnings' => 'array',
        'payment_ids' => 'array',
        'total_amount' => 'decimal:2',
        'extracted_total' => 'decimal:2',
        'variance' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payment_advice_import_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
