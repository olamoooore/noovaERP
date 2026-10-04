<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Issue #333 ([SALES-ODOO]) — tenant cash rounding.
 *
 * Odoo-style cash rounding: a tenant may round the final payable total of an
 * invoice to a coin/precision step (e.g. MYR 0.05) and book the signed
 * difference to a dedicated rounding account. This service is the ONE
 * implementation of the arithmetic — `Invoice::calculateTotals()` persists its
 * delta and `InvoiceService::postInvoice()` posts that persisted delta, so the
 * document total and the ledger line can never disagree.
 *
 * Settings live under the tenanted `sales.cash_rounding` key:
 *   [
 *     'enabled'    => bool,                  // default false (backward compatible)
 *     'method'     => nearest|up|down,       // default nearest
 *     'precision'  => 0.05,                  // rounding step
 *     'account_id' => chart_of_accounts.id,  // tenant-chosen; never auto-seeded
 *   ]
 *
 * The rounding account is deliberately a tenant SETTING rather than an
 * AccountMappingService fallback: the platform has no seeded rounding account,
 * and inventing/seeding one (code, name, type) would be invented statutory
 * data. When rounding is enabled but no account is configured, posting fails
 * loudly instead of silently skipping the adjustment.
 */
class CashRoundingService
{
    public const SETTING_KEY = 'sales.cash_rounding';

    public const METHOD_NEAREST = 'nearest';

    public const METHOD_UP = 'up';

    public const METHOD_DOWN = 'down';

    public const METHODS = [self::METHOD_NEAREST, self::METHOD_UP, self::METHOD_DOWN];

    public const DEFAULTS = [
        'enabled' => false,
        'method' => self::METHOD_NEAREST,
        'precision' => 0.05,
        'account_id' => null,
    ];

    /**
     * @return array{enabled: bool, method: string, precision: float, account_id: int|null}
     */
    public function settings(): array
    {
        $stored = Setting::getValue(self::SETTING_KEY, []);

        $settings = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);

        return [
            'enabled' => (bool) $settings['enabled'],
            'method' => in_array($settings['method'] ?? null, self::METHODS, true)
                ? (string) $settings['method']
                : self::METHOD_NEAREST,
            'precision' => (float) $settings['precision'],
            'account_id' => $settings['account_id'] !== null && (int) $settings['account_id'] > 0
                ? (int) $settings['account_id']
                : null,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->settings()['enabled'];
    }

    public function round(float $amount, ?array $settings = null): float
    {
        $settings ??= $this->settings();

        if (! ($settings['enabled'] ?? false)) {
            return round($amount, 2);
        }

        $precision = (float) ($settings['precision'] ?? 0);

        if ($precision <= 0) {
            return round($amount, 2);
        }

        $steps = $amount / $precision;

        $rounded = match ($settings['method'] ?? self::METHOD_NEAREST) {
            // The epsilon keeps float noise at the step boundary from moving
            // the result: 24690.999999999 stays 24690, 24690.000000001 stays
            // 24691 — with a "0.05" step the rule must be exact.
            self::METHOD_UP => ceil($steps - 1e-9) * $precision,
            self::METHOD_DOWN => floor($steps + 1e-9) * $precision,
            default => round($steps) * $precision,
        };

        return round($rounded, 2);
    }

    /**
     * Signed adjustment that turns the unrounded gross into the rounded total.
     */
    public function delta(float $amount, ?array $settings = null): float
    {
        return round($this->round($amount, $settings) - round($amount, 2), 2);
    }

    public function accountId(): ?int
    {
        return $this->settings()['account_id'];
    }

    /**
     * The rounding account is mandatory whenever a rounding delta exists.
     *
     * @throws \RuntimeException when the tenant enabled rounding without choosing an account
     */
    public function accountIdOrFail(): int
    {
        return $this->accountId() ?? throw new \RuntimeException(
            'Cash rounding is enabled but no rounding account is configured. '.
            'Choose one in Sales Settings > Cash Rounding (sales.cash_rounding.account_id).'
        );
    }
}
