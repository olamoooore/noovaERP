<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-000 #169 / #176 — signed public access token for forms/surveys.
 */
class TemplatePublicToken extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'template_public_tokens';

    protected $fillable = [
        'id',
        'token_hash',
        'template_id',
        'interaction_type',
        'channel',
        'scope',
        'capability',
        'expires_at',
        'revoked_at',
        'revocation_reason',
        'max_uses',
        'use_count',
        'rate_limit_per_hour',
        'last_used_at',
        'issued_to_email',
        'issued_ip',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'scope' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'max_uses' => 'integer',
        'use_count' => 'integer',
        'rate_limit_per_hour' => 'integer',
    ];

    protected $hidden = ['token_hash'];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $token) {
            if ($token->id === null) {
                $token->id = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function nonces(): HasMany
    {
        return $this->hasMany(TemplatePublicTokenNonce::class, 'token_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isValid(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->max_uses > 0 && $this->use_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function scopeValid($query)
    {
        return $query->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
