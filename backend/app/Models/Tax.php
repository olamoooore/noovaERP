<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tax extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'type',
        'rate',
        'is_active',
        'is_default',
        'is_inclusive',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_active' => 'bool',
        'is_default' => 'bool',
        'is_inclusive' => 'bool',
    ];
}
