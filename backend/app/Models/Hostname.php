<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hostname extends Model
{
    protected $table = 'public.hostnames';

    protected $guarded = ['id'];

    protected $hidden = ['challenge_token_ciphertext', 'challenge_token_hash'];

    protected $casts = [
        'primary_requested' => 'boolean',
        'primary_effective' => 'boolean',
        'challenge_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'last_ownership_check_at' => 'datetime',
        'last_routing_check_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'certificate_expires_at' => 'datetime',
        'disabled_at' => 'datetime',
        'release_requested_at' => 'datetime',
        'quarantined_until' => 'datetime',
        'released_at' => 'datetime',
    ];
}
