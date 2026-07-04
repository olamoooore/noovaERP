<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosLoyaltyProfile extends Model
{
    use HasFactory;

    protected $table = 'pos_loyalty_profiles';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'membership_id', 'membership_tier',
        'loyalty_points', 'lifetime_points', 'membership_start_date',
        'membership_expiry_date', 'total_spend', 'total_orders',
        'last_purchase_date', 'preferences', 'metadata',
    ];

    protected $casts = [
        'loyalty_points' => 'integer',
        'lifetime_points' => 'integer',
        'total_spend' => 'decimal:2',
        'total_orders' => 'integer',
        'membership_start_date' => 'date',
        'membership_expiry_date' => 'date',
        'last_purchase_date' => 'date',
        'preferences' => 'array',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}
