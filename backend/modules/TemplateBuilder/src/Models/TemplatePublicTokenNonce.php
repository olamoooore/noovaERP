<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Replay-protection nonce for a public token (ADR-004 §15).
 */
class TemplatePublicTokenNonce extends Model
{
    public $timestamps = false;

    protected $table = 'template_public_token_nonces';

    protected $fillable = ['token_id', 'nonce', 'used_at'];

    protected $casts = [
        'used_at' => 'datetime',
    ];
}
