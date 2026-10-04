<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * Issue #553 (SALES-ODOO): creation defaults for new sales documents.
 *
 * "Default salesperson = current user" and "default team per user" are tenant
 * settings; this service is the one place the creation paths read them from,
 * so a quotation, a sales order (from any channel) and a converted document
 * all resolve the same defaults. Explicit caller values always win.
 */
class SalesDefaultsService
{
    public const MODE_CURRENT_USER = 'current_user';

    public const MODE_NONE = 'none';

    public const SETTING_SALESPERSON_MODE = 'sales.defaults.salesperson_mode';

    public const SETTING_TEAM_BY_USER = 'sales.defaults.team_by_user';

    public function salespersonMode(): string
    {
        $mode = Setting::getValue(self::SETTING_SALESPERSON_MODE, self::MODE_NONE);

        return in_array($mode, [self::MODE_CURRENT_USER, self::MODE_NONE], true)
            ? (string) $mode
            : self::MODE_NONE;
    }

    /**
     * The salesperson a new document should get for this user: the user's name
     * when the tenant enabled the current-user default, otherwise null.
     */
    public function defaultSalesperson(?int $userId): ?string
    {
        if ($userId === null || $this->salespersonMode() !== self::MODE_CURRENT_USER) {
            return null;
        }

        $name = User::query()->whereKey($userId)->value('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * The team configured for this user (per-tenant Setting map, keyed by
     * user id), or null when none is set.
     */
    public function defaultTeam(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $map = Setting::getValue(self::SETTING_TEAM_BY_USER, []);

        if (! is_array($map)) {
            return null;
        }

        $team = $map[(string) $userId] ?? $map[$userId] ?? null;

        return is_string($team) && trim($team) !== '' ? trim($team) : null;
    }

    /**
     * Fill the salesperson/team on a document creation payload when the caller
     * did not supply them. Values already present (including explicit
     * conversions carrying the parent document's values) are never replaced.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function applyCreationDefaults(array $data, ?int $userId): array
    {
        if (empty($data['salesperson'])) {
            $data['salesperson'] = $this->defaultSalesperson($userId);
        }

        if (empty($data['sales_team'])) {
            $data['sales_team'] = $this->defaultTeam($userId);
        }

        return $data;
    }
}
