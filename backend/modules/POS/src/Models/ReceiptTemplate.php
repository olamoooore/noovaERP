<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptTemplate extends Model
{
    protected $table = 'receipt_templates';

    protected $fillable = [
        'name',
        'description',
        'config',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];
}
