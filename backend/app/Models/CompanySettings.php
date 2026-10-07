<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Company Settings - Configuration for accounting system
 */
class CompanySettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'company_logo_url',
        'fiscal_year_start_month',
        'fiscal_year_start_day',
        'base_currency',
        'date_format',
        'number_format',
        'timezone',
        'default_receivable_account_id',
        'default_payable_account_id',
        'default_bank_account_id',
        'default_revenue_account_id',
        'shipping_revenue_account_id',
        'default_expense_account_id',
        'default_tax_account_id',
        'tax_id',
        'address',
        'phone',
        'email',
        'website',
        'gst_registration_number',
        'tax_registration_number',
        'company_registration_number',
    ];

    protected $casts = [
        'fiscal_year_start_month' => 'integer',
        'fiscal_year_start_day' => 'integer',
    ];

    /**
     * Get the base currency
     */
    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency', 'code');
    }

    /**
     * Get the default receivable account
     */
    public function defaultReceivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_receivable_account_id');
    }

    /**
     * Get the default payable account
     */
    public function defaultPayableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_payable_account_id');
    }

    public function defaultBankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_bank_account_id');
    }

    /**
     * Get the default revenue account
     */
    public function defaultRevenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_revenue_account_id');
    }

    /**
     * Get the shipping revenue account
     */
    public function shippingRevenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'shipping_revenue_account_id');
    }

    /**
     * Get the default expense account
     */
    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_expense_account_id');
    }

    /**
     * Get the default tax account
     */
    public function defaultTaxAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_tax_account_id');
    }

    /**
     * Issue #1028 (follow-up to #768): the fiscal year start [month, day]
     * derived from the legal entity, the single source of truth. The given
     * entity is used when it exists, otherwise the primary (first active)
     * entity, as the company-settings fiscal-year endpoint does. Only a
     * legacy tenant with no legal entity falls back to company_settings.
     *
     * @return array{0: int, 1: int}
     */
    public static function fiscalYearStartFor(?int $legalEntityId = null): array
    {
        $entity = null;
        if ($legalEntityId !== null) {
            $entity = DB::table('legal_entities')->where('id', $legalEntityId)->first();
        }
        $entity ??= DB::table('legal_entities')->where('status', 'active')->orderBy('id')->first();

        if ($entity) {
            return FiscalPeriod::parseFiscalYearStart($entity->fiscal_year_start);
        }

        $settings = DB::table('company_settings')->first();
        $month = (int) ($settings->fiscal_year_start_month ?? 1);
        $day = (int) ($settings->fiscal_year_start_day ?? 1);

        return [max(1, min(12, $month)), max(1, min(31, $day))];
    }

    /**
     * Get the fiscal year start date (derived from the legal entity, #1028).
     */
    public function getFiscalYearStartDate(?int $year = null, ?int $legalEntityId = null): \Carbon\Carbon
    {
        $year = $year ?? now()->year;
        [$month, $day] = static::fiscalYearStartFor($legalEntityId);

        return \Carbon\Carbon::create($year, $month, $day);
    }

    /**
     * Get the fiscal year end date (derived from the legal entity, #1028).
     */
    public function getFiscalYearEndDate(?int $year = null, ?int $legalEntityId = null): \Carbon\Carbon
    {
        return $this->getFiscalYearStartDate($year, $legalEntityId)->addYear()->subDay();
    }

    /**
     * Format an amount according to company settings
     */
    public function formatAmount(float $amount): string
    {
        // Parse number format (e.g., "1,234.56" or "1.234,56")
        $format = $this->number_format ?? '1,234.56';

        if (strpos($format, ',') < strpos($format, '.')) {
            // US format: 1,234.56
            return number_format($amount, 2, '.', ',');
        } else {
            // European format: 1.234,56
            return number_format($amount, 2, ',', '.');
        }
    }

    /**
     * Format a date according to company settings
     */
    public function formatDate($date): string
    {
        $format = $this->date_format ?? 'Y-m-d';

        if ($date instanceof \Carbon\Carbon) {
            return $date->format($format);
        }

        return \Carbon\Carbon::parse($date)->format($format);
    }

    /**
     * Get the singleton instance for the current tenant
     */
    public static function current(): ?self
    {
        return static::first();
    }
}
