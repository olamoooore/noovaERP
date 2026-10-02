<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsitePublication extends Model
{
    protected $table = 'public.website_publications';
    protected $primaryKey = 'website_uuid';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
    ];
}
