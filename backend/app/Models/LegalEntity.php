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
    ];
}
