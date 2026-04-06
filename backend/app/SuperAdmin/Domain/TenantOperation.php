<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOperation extends Model
{
    public $timestamps = false;

    protected $table = 'tenant_operations';

    protected $fillable = [
        'tenant_id',
        'admin_user_id',
        'operation',
        'payload',
        'result',
        'status',
        'created_at',
    ];

    protected $casts = [
        'payload'    => 'array',
        'result'     => 'array',
        'created_at' => 'datetime',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function toAuditSnapshot(): array
    {
        return $this->only(['id', 'tenant_id', 'operation', 'status']);
    }
}
