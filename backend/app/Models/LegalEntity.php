<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LegalEntity extends Model
{
    use HasFactory;

    protected $table = 'legal_entities';

    protected $fillable = [
        'group_id',
        'name',
        'code',
        'country_code',
        'base_currency',
        'fiscal_year_start',
        'status',
        // Issue #612: per-entity letterhead used as the printed issuer.
        'registration_number',
        'tax_id',
        'sst_registration_number',
        'address',
        'logo_url',
        'email',
        'phone',
        'website',
        'bank_details',
    ];

    protected $casts = [
        'bank_details' => 'array',
    ];
}
