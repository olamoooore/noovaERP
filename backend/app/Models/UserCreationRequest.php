<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tenant owner's/admin's request for the platform team to create a new
 * workspace member. Reviewed (approved → account created via
 * TenantMemberService, or declined) in the SuperAdmin console.
 */
class UserCreationRequest extends Model
{
    protected $table = 'public.user_creation_requests';

    protected $fillable = [
        'tenant_id',
        'requested_by',
        'name',
        'email',
        'role',
        'note',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
