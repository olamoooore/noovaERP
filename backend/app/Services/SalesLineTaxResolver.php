<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Tax;
use Illuminate\Validation\ValidationException;

/**
 * #302 (X1/X2) — the line tax resolver for sales documents (#389 design).
 *
 * Quotation, sales-order and invoice lines used to book whatever tax % the
 * browser sent, and the browser took it from Product::effective_tax_rate, a
 * second ladder that ignored tax_mode='none', effective dates and rule scope.
 * Every HTTP save path for a sales line now asks this resolver instead; it
 * delegates to the one tax engine (TaxResolutionService -> TaxEngineService)
 * with the document date, the output direction and the product's line tax
 * type, and returns the identity the line persists.
 *
 * Precedence for one line:
 *  1. An explicit override — a tax rule id chosen on the line — wins. It must
 *     be an active output (or both) rule in force on the document date;
 *     anything else is refused with a validation error, never booked.
 *  2. An explicit 0% stays explicit (#304 X4: a zero-rated supply is the
 *     document's choice). It adopts the engine identity only when the engine
 *     also resolves 0% (e.g. an exempt product or a zero-rated rule).
 *  3. A product-specific engine answer wins over any sent %: tax disabled,
 *     tax_mode='none', custom product tax, a product-linked rule (or its
 *     successor), a variant override, a blocked expired rule.
 *  4. A sent % is read as a choice among the rules that can apply: if an
 *     active output rule in force on the document date (tenant jurisdiction)
 *     carries exactly that rate, the line takes that rule and its identity.
 *     A % no applicable rule carries is never booked.
 *  5. Legacy compatibility: when nothing classifies the line (no applicable
 *     output rule, no tenant default, no product tax setting), a sent % is
 *     the only tax fact there is and is kept as a manual rate (source
 *     'manual_rate', no rule identity).
 *  6. Otherwise the engine result (tenant default, localized rule, legacy
 *     product rate, or no tax) is booked.
 *
 * Note/section lines carry no tax and are returned unchanged.
 */
class SalesLineTaxResolver
{
    public function __construct(
        private readonly TaxEngineService $engine,
        private readonly TaxResolutionService $resolution,
        private readonly TaxCountryPackService $packs,
        private readonly TaxDeterminationService $determination,
    ) {}

    /**
     * @param  array<string, mixed>  $line  request line (product_id, variant_id, line_type, tax_rate, tax_rule_id, tax_inclusive)
     * @param  string|null  $date  the document date (tax point); today when null
     * @param  string  $errorKey  validation key for an invalid explicit rule, e.g. "lines.0.tax_rule_id"
     * @param  array{registered: bool, jurisdiction: string, export: bool}|null  $context  document context (see TaxDeterminationService::salesLineContext); null = no entity/customer context
     * @return array{tax_rate: float, tax_inclusive: bool, tax_rule_id: int|null, tax_code: string|null, tax_treatment: string|null, tax_source: string}
     *
     * @throws ValidationException when the explicit rule cannot apply to a sale on that date
     */
    public function resolve(array $line, ?string $date, string $errorKey = 'tax_rule_id', ?array $context = null): array
    {
        $date = $this->normaliseDate($date);
        $context ??= $this->determination->salesLineContext(null, null, $date);
        $jurisdiction = $context['jurisdiction'];
        $lineType = $line['line_type'] ?? 'product';
        $explicitInclusive = array_key_exists('tax_inclusive', $line) && $line['tax_inclusive'] !== null
            ? (bool) $line['tax_inclusive']
            : null;

        if (in_array($lineType, ['note', 'section'], true)) {
            return [
                'tax_rate' => (float) ($line['tax_rate'] ?? 0),
                'tax_inclusive' => (bool) ($explicitInclusive ?? false),
                'tax_rule_id' => null,
                'tax_code' => null,
                'tax_treatment' => null,
                'tax_source' => 'not_taxable_line',
            ];
        }

        // 0. A legal entity without an active tax registration charges no tax
        // (#740 AC2) — the determination at posting reports NO_REGISTRATION.
        if (! $context['registered']) {
            return [
                'tax_rate' => 0.0,
                'tax_inclusive' => (bool) ($explicitInclusive ?? false),
                'tax_rule_id' => null,
                'tax_code' => null,
                'tax_treatment' => null,
                'tax_source' => 'no_registration',
            ];
        }

        // 1. Explicit override: the tax code picked on the line.
        if (! empty($line['tax_rule_id'])) {
            return $this->fromExplicitRule((int) $line['tax_rule_id'], $date, $explicitInclusive, $errorKey);
        }

        $product = ! empty($line['product_id']) ? Product::find($line['product_id']) : null;
        $variant = ($product && ! empty($line['variant_id']))
            ? ProductVariant::query()->whereKey($line['variant_id'])->where('product_id', $product->id)->first()
            : null;

        $sentRate = array_key_exists('tax_rate', $line) && $line['tax_rate'] !== null && $line['tax_rate'] !== ''
            ? (float) $line['tax_rate']
            : null;

        // Export (#740 AC3): the buyer's jurisdiction differs from the
        // seller's registration, so the engine does not apply local tax — a
        // rate the document states stays (matched to a seller rule when one
        // carries it), otherwise the supply is zero-rated. Mirrors
        // TaxDeterminationService::determineForInvoice().
        if ($context['export']) {
            if ($sentRate !== null && $sentRate > 0.0) {
                $matching = $this->applicableRuleWithRate($sentRate, $date, $jurisdiction, []);

                return $matching !== null
                    ? $this->fromRule($matching, $explicitInclusive, 'export_booked_rule')
                    : [
                        'tax_rate' => $sentRate,
                        'tax_inclusive' => (bool) ($explicitInclusive ?? false),
                        'tax_rule_id' => null,
                        'tax_code' => null,
                        'tax_treatment' => 'standard_rated',
                        'tax_source' => 'export_booked_rate',
                    ];
            }

            return [
                'tax_rate' => 0.0,
                'tax_inclusive' => (bool) ($explicitInclusive ?? false),
                'tax_rule_id' => null,
                'tax_code' => null,
                'tax_treatment' => $this->engine->defaultTreatment() ?? 'zero_rated',
                'tax_source' => 'export',
            ];
        }

        $engine = $this->engineResult($product, $variant, $date, $jurisdiction);

        // 2. An explicit 0% stays explicit (#304).
        if ($sentRate !== null && $sentRate <= 0.0) {
            $engineIsZero = ! $engine['blocked'] && (float) $engine['rate'] <= 0.0;

            return [
                'tax_rate' => 0.0,
                'tax_inclusive' => $explicitInclusive ?? (bool) $engine['is_inclusive'],
                'tax_rule_id' => $engineIsZero ? ($engine['tax_id'] ?? null) : null,
                'tax_code' => $engineIsZero && ! $engine['unconfigured'] ? ($engine['tax_code'] ?? null) : null,
                'tax_treatment' => $engineIsZero && ! $engine['unconfigured']
                    ? ($engine['treatment'] ?? null)
                    : ($this->engine->defaultTreatment() ?? 'zero_rated'),
                'tax_source' => 'explicit_zero',
            ];
        }

        // 3. A deliberate engine answer about this product always wins: tax
        // disabled, tax_mode='none', custom product tax, a product-linked rule
        // (or its successor), a variant override, or a blocked expired rule.
        if ($this->isProductSpecific($engine)) {
            return $this->fromEngine($engine, $explicitInclusive);
        }

        // 4. A typed rate is read as a choice among the rules that may apply
        // to this sale on this date: when an active output rule in force on
        // the document date carries exactly that rate, the line takes that
        // rule (identity included). A rate no applicable rule carries is not
        // booked — the engine result is.
        if ($sentRate !== null) {
            $matching = $this->applicableRuleWithRate($sentRate, $date, $jurisdiction, $engine);

            if ($matching !== null) {
                // The per-product inclusive override applies to any rule,
                // exactly as in the engine (TaxEngineService::resolve).
                $productInclusive = ($product !== null && $product->tax_inclusive_override !== null && $product->tax_mode !== 'custom')
                    ? (bool) $product->tax_inclusive_override
                    : null;

                return $this->fromRule($matching, $explicitInclusive ?? $productInclusive, 'rule_by_rate');
            }
        }

        // 5. Legacy compatibility: the engine found nothing to classify the
        // line (no output rule, no tenant default, no product tax setting).
        // A typed rate is then the only tax fact there is and is kept as a
        // manual rate without rule identity. A product linked to a rule
        // (e.g. a purchase-only one) or a configured tenant default is NOT
        // unconfigured, so the engine's no-tax answer stands there.
        if ($engine['unconfigured'] && $sentRate !== null && $this->lineIsUnconfigured($product)) {
            return [
                'tax_rate' => $sentRate,
                'tax_inclusive' => $explicitInclusive ?? (bool) $engine['is_inclusive'],
                'tax_rule_id' => null,
                'tax_code' => null,
                'tax_treatment' => 'standard_rated',
                'tax_source' => 'manual_rate',
            ];
        }

        // 6. Otherwise the engine decides (tenant default, localized rule,
        // legacy product rate, or no tax).
        return $this->fromEngine($engine, $explicitInclusive);
    }

    /**
     * Resolve every line of a request payload and write the resolved tax
     * identity back onto each line, ready for the document's line creator.
     * Note/section lines pass through untouched.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<int|string, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function applyToLines(array $lines, ?string $date, ?int $customerId = null, ?int $legalEntityId = null, string $prefix = 'lines'): array
    {
        $context = $this->context($date, $customerId, $legalEntityId);

        foreach ($lines as $index => $line) {
            if (in_array($line['line_type'] ?? 'product', ['note', 'section'], true)) {
                continue;
            }

            $resolved = $this->resolve($line, $date, "{$prefix}.{$index}.tax_rule_id", $context);

            $lines[$index] = array_merge($line, [
                'tax_rate' => $resolved['tax_rate'],
                'tax_inclusive' => $resolved['tax_inclusive'],
                'tax_rule_id' => $resolved['tax_rule_id'],
                'tax_code' => $resolved['tax_code'],
                'tax_treatment' => $resolved['tax_treatment'],
            ]);
        }

        return $lines;
    }

    /**
     * Preview for the line editor: the same resolution the save path applies,
     * plus the tax codes the picker may offer on that date.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function preview(array $line, ?string $date, ?int $customerId = null): array
    {
        $date = $this->normaliseDate($date);
        $resolved = $this->resolve($line, $date, 'tax_rule_id', $this->context($date, $customerId, null));

        $resolved['date'] = $date;
        $resolved['options'] = $this->engine->selectableRules($date, 'output')
            ->map(fn (Tax $tax) => [
                'id' => (int) $tax->id,
                'code' => $tax->code,
                'name' => $tax->name,
                'rate' => (float) $tax->rate,
                'treatment' => $this->engine->ruleIdentity($tax, 'output')['treatment'],
                'is_inclusive' => (bool) $tax->is_inclusive,
                'is_default' => (bool) $tax->is_default,
            ])
            ->values()
            ->all();

        return $resolved;
    }

    /**
     * @return array{tax_rate: float, tax_inclusive: bool, tax_rule_id: int|null, tax_code: string|null, tax_treatment: string|null, tax_source: string}
     */
    private function fromExplicitRule(int $ruleId, string $date, ?bool $explicitInclusive, string $errorKey): array
    {
        $rule = Tax::query()->whereKey($ruleId)->first();

        if ($rule === null || ! $this->engine->ruleApplies($rule, $date, 'output')) {
            throw ValidationException::withMessages([
                $errorKey => "Tax rule {$ruleId} cannot be applied to a sale dated {$date}: it must be an active output tax rule in force on that date.",
            ]);
        }

        return $this->fromRule($rule, $explicitInclusive, 'explicit_rule');
    }

    /**
     * @return array{tax_rate: float, tax_inclusive: bool, tax_rule_id: int|null, tax_code: string|null, tax_treatment: string|null, tax_source: string}
     */
    private function fromRule(Tax $rule, ?bool $explicitInclusive, string $source): array
    {
        $identity = $this->engine->ruleIdentity($rule, 'output');

        return [
            'tax_rate' => (float) $identity['rate'],
            'tax_inclusive' => $explicitInclusive ?? (bool) $identity['is_inclusive'],
            'tax_rule_id' => (int) $rule->id,
            'tax_code' => $identity['tax_code'],
            'tax_treatment' => $identity['treatment'],
            'tax_source' => $source,
        ];
    }

    /**
     * @return array{rate: float, is_inclusive: bool, tax_code: string|null, tax_id: int|null, treatment: string|null, source: string|null, unconfigured: bool, blocked: bool}
     */
    private function engineResult(?Product $product, ?ProductVariant $variant, string $date, string $jurisdiction): array
    {
        if ($product !== null) {
            $taxType = $this->packs->lineTaxType($jurisdiction, $product->type ?? null);

            return $this->resolution->resolveTaxForProduct($product, $variant, $jurisdiction, 'output', $date, $taxType);
        }

        $resolved = $this->engine->resolve(null, $jurisdiction, 'output', null, $date);

        return [
            'rate' => (float) $resolved['rate'],
            'is_inclusive' => (bool) $resolved['is_inclusive'],
            'tax_code' => $resolved['tax_code'] ?? null,
            'tax_id' => $resolved['tax_id'] ?? null,
            'treatment' => $resolved['treatment'] ?? null,
            'source' => $resolved['source'] ?? null,
            'unconfigured' => (bool) ($resolved['unconfigured'] ?? false),
            'blocked' => (bool) ($resolved['blocked'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $engine
     */
    private function isProductSpecific(array $engine): bool
    {
        $source = (string) ($engine['source'] ?? '');

        return $engine['blocked']
            || in_array($source, ['product_custom', 'product_successor', 'variant'], true)
            || ($source === 'product' && ! empty($engine['tax_id']))
            || ($source === 'none' && ! $engine['unconfigured']);
    }

    /**
     * @param  array<string, mixed>  $engine
     */
    private function applicableRuleWithRate(float $rate, string $date, string $jurisdiction, array $engine): ?Tax
    {
        $jurisdiction = strtoupper($jurisdiction);

        $candidates = $this->engine->selectableRules($date, 'output')
            ->filter(fn (Tax $tax) => abs((float) $tax->rate - $rate) < 0.005)
            ->filter(fn (Tax $tax) => $tax->jurisdiction === null || $tax->jurisdiction === '' || strtoupper((string) $tax->jurisdiction) === $jurisdiction);

        if ($candidates->isEmpty()) {
            return null;
        }

        // The engine's own rule wins a tie; then a charging rule over a
        // zero/exempt declaration (G-24 ordering), default first.
        if (! empty($engine['tax_id']) && ($own = $candidates->firstWhere('id', (int) $engine['tax_id'])) !== null) {
            return $own;
        }

        return $candidates
            ->sortBy(fn (Tax $tax) => [
                $tax->is_default ? 0 : 1,
                in_array($tax->treatment, [null, '', 'standard_rated'], true) ? 0 : 1,
                -1 * (int) $tax->id,
            ])
            ->first();
    }

    private function lineIsUnconfigured(?Product $product): bool
    {
        if ($product !== null && ($product->tax_id !== null || in_array($product->tax_mode, ['none', 'custom'], true))) {
            return false;
        }

        return empty(Setting::getValue('tax', [])['default_tax_id']);
    }

    /**
     * @param  array<string, mixed>  $engine
     * @return array{tax_rate: float, tax_inclusive: bool, tax_rule_id: int|null, tax_code: string|null, tax_treatment: string|null, tax_source: string}
     */
    private function fromEngine(array $engine, ?bool $explicitInclusive): array
    {
        // A blocked resolution (expired rule without a successor) books no
        // tax here; the posting-time determination keeps the document
        // blocked until the rule is fixed (#746 T13).
        return [
            'tax_rate' => $engine['blocked'] ? 0.0 : (float) $engine['rate'],
            'tax_inclusive' => $explicitInclusive ?? (bool) $engine['is_inclusive'],
            'tax_rule_id' => $engine['blocked'] ? null : ($engine['tax_id'] ?? null),
            'tax_code' => ($engine['blocked'] || $engine['unconfigured']) ? null : ($engine['tax_code'] ?? null),
            'tax_treatment' => $engine['blocked'] ? 'unresolved' : ($engine['treatment'] ?? null),
            'tax_source' => (string) ($engine['source'] ?? 'engine'),
        ];
    }

    /**
     * The document context lines are resolved in (seller registration,
     * jurisdiction, export) — see TaxDeterminationService::salesLineContext().
     *
     * @return array{registered: bool, jurisdiction: string, export: bool}
     */
    public function context(?string $date, ?int $customerId, ?int $legalEntityId): array
    {
        return $this->determination->salesLineContext($legalEntityId, $customerId, $this->normaliseDate($date));
    }

    private function normaliseDate(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return now()->toDateString();
        }

        return \Illuminate\Support\Carbon::parse($date)->toDateString();
    }
}
