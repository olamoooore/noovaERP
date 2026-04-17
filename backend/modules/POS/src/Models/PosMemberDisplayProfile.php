<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;

class PosMemberDisplayProfile extends Model
{
    public $timestamps = false;

    protected $table = 'pos_member_display_profiles';

    protected $fillable = [
        'member_id',
        'display_name',
        'favorite_order_label',
        'favorite_order_json',
        'recent_orders_json',
        'updated_at',
    ];

    protected $casts = [
        'favorite_order_json' => 'array',
        'recent_orders_json' => 'array',
        'updated_at' => 'datetime',
    ];
}
