<?php

namespace App\Enums;

/**
 * Standard ERP roles for tenant-scoped access control.
 *
 * Values stored in public.user_company_access.role (lowercase snake_case).
 * Display titles use Str::title() for Spatie Role names.
 */
enum UserRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MANAGER = 'manager';
    case SALES = 'sales';
    case ACCOUNTANT = 'accountant';
    case CASHIER = 'cashier';
    case VIEWER = 'viewer';

    /**
     * Human-friendly label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::ADMIN => 'Admin',
            self::MANAGER => 'Manager',
            self::SALES => 'Sales',
            self::ACCOUNTANT => 'Accountant',
            self::CASHIER => 'Cashier',
            self::VIEWER => 'Viewer',
        };
    }

    /**
     * Spatie Role name (Title Case).
     */
    public function spatieName(): string
    {
        return $this->label();
    }

    /**
     * All valid role values as an array.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }

    /**
     * Roles that implicitly grant full access (bypass fine-grained checks).
     *
     * @return string[]
     */
    public static function superRoles(): array
    {
        return [self::OWNER->value, self::ADMIN->value];
    }
}
