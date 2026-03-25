<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Custom PersonalAccessToken that always uses the public schema.
 * Ensures token lookups succeed even when tenant search_path is active.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'public.personal_access_tokens';
}
