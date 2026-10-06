<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Issue #466 (DO14) — central token → tenant registry for public document
 * links. Stored in the PUBLIC schema (see the create migration): the resolver
 * runs before tenancy is initialized and must be indexed on the token hash.
 */
class DocumentTokenLookup extends Model
{
    protected $table = 'public.document_token_lookup';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
