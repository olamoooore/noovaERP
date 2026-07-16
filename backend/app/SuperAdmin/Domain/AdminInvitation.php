<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminInvitation extends Model
{
    public $timestamps = false;

    protected $table = 'admin_invitations';

    protected $fillable = [
        'email',
        'role',
        'token',
        'invited_by_admin_user_id',
        'expires_at',
        'accepted_at',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_admin_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }
}
