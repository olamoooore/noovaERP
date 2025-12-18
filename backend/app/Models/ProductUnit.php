<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    protected $fillable = ['name', 'short_name', 'allow_decimal', 'is_active'];

    protected $casts = [
        'allow_decimal' => 'boolean',
        'is_active' => 'boolean',
    ];
}
