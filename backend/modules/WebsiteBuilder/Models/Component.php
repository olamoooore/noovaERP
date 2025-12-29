<?php

namespace Modules\WebsiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class Component extends Model
{
    protected $table = 'wb_components';

    protected $fillable = [
        'name',
        'type',
        'schema',
        'is_system',
    ];

    protected $casts = [
        'schema' => 'array',
        'is_system' => 'boolean',
    ];
}
