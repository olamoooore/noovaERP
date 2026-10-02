<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class AllowedWebsitePageSlug implements ValidationRule
{
    private const RESERVED = ['.well-known', '_ace', 'api', 'assets', 'storage', 'health', 'admin', 'sanctum', 'livewire', 'login', 'site', 'w'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $first = strtolower(explode('/', trim((string) $value, '/'))[0] ?? '');
        if ($first === '' || in_array($first, self::RESERVED, true)) {
            $fail('This page path is reserved by the platform.');
        }
    }
}
