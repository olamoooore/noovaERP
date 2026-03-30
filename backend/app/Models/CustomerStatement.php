<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerStatement extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'start_date',
        'end_date',
        'period_type',
        'include_paid',
        'email_sent',
        'email_sent_at',
        'recipient_email',
        'pdf_path',
        'payload',
        'generated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'include_paid' => 'boolean',
        'email_sent' => 'boolean',
        'email_sent_at' => 'datetime',
        'payload' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
