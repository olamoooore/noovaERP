<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActionLog extends Model
{
    protected $table = 'admin_action_logs';

    // Logs are immutable — only created_at, set manually
    public $timestamps = false;

    protected $fillable = [
        'admin_user_id',
        'action',
        'target_type',
        'target_id',
        'before_snapshot',
        'after_snapshot',
        'reason',
        'ip_address',
        'status',
        'created_at',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot'  => 'array',
        'created_at'      => 'datetime',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
