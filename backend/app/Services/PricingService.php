<?php

namespace App\Services;

use App\Exceptions\PricingException;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;

/**
 * PricingService — the single price authority (MRD pricelist-multicurrency-mrd
 * FR-4.1). Every document line editor and the API resolve prices through this
 * service; NO module may fork its own pricing logic (SOP §4).
 *
 * Resolution order (FR-4.2):
 *   1. explicit manual override on the line (reason required below floor);
 *   2. the applicable price-list rule (customer list per FR-2.3, rules
 *      top-down first-match-wins per FR-3.5);
 *   3. product selling_price/price (the Issue #132 tier engine), and
 *   4. error only when there is genuinely no base to compute from.
 *
 * Currency bridging (FR-4.3): when the list currency ≠ document currency the
 * computed price is converted at the document date, rounded to the currency's
 * minor units, and the rate used is snapshotted onto the trace.
 *
 * The engine is deterministic and side-effect free (FR-4.5): identical inputs
 * produce identical output, safe for previews and catalogue display.
 */
class PricingService
{
    /** Per-request memo of (customer,date,currency) → resolved list (FR-13 perf). */
    private array $listMemo = [];

    /** Per-request memo of (from,to,date) → FX snapshot (FR-13: ≤ 1 rate lookup per currency). */
    private array $rateMemo = [];

    /** Per-request memo of currency code → minor-unit exponent (avoids a lookup per round). */
    private array $currencyUnitsMemo = [];

    /** Per-request memo of legal-entity|customer → effective base currency (FR-1.2). */
    private array $baseCurrencyMemo = [];

    /** Per-request memo of the tenant feature flags read during pricing. */
    private ?bool $pricelistsEnabledMemo = null;

    public function __construct(
        private readonly ExchangeRateService $exchangeRates,
        private readonly ProductPricingResolver $catalogPrices,
    ) {}

    /**
     * Resolve one line price (FR-4.1 signature, returned as a structured quote).
     *
     * @param  array{
     *     product_id?:int|null, product?:Product|null, quantity?:float, date?:string|null,
     *     customer_id?:int|null, document_currency?:string|null, base_currency?:string|null,
     *     legal_entity_id?:int|null, manual_price?:float|null, override_reason?:string|null,
     *     variant_id?:int|null, price_list_id?:int|null, include_cost?:bool
     * }  $params
     * @return array{
     *     unit_price:float, base_unit_price:float, currency:string, base_currency:string,
     *     exchange_rate:?float, rate_date:?string, source:string, override:bool,
     *     list_price:float, price_source:array
     * }
     */
    public function resolvePrice(array $params): array
    {
        $quantity = max(0.0, (float) ($params['quantity'] ?? 1));
        if ($quantity <= 0) {
            $quantity = 1.0; // a zero/negative qty still resolves a unit price for display
        }

        $date = $this->normalizeDate($params['date'] ?? null);

        $product = $params['product'] ?? ($params['product_id'] ?? null
            ? Product::withTrashed()->find($params['product_id'])
            : null);

        if (! $product) {
            throw new PricingException('Cannot resolve price: the line has no product.');
        }

        if (isset($params['base_currency']) && $params['base_currency'] !== null && $params['base_currency'] !== '') {
            $baseCurrency = strtoupper((string) $params['base_currency']);
        } else {
            $memoKey = 'e:'.($params['legal_entity_id'] ?? '-').'|c:'.($params['customer_id'] ?? '-');
            $this->baseCurrencyMemo[$memoKey] ??= DocumentCurrencyResolver::baseFor(
                $params['legal_entity_id'] ?? null,
                $params['customer_id'] ?? null,
            );
            $baseCurrency = strtoupper((string) $this->baseCurrencyMemo[$memoKey]);
        }

        $documentCurrency = strtoupper((string) ($params['document_currency'] ?? $baseCurrency));

        // ── 1. Manual override (FR-4.2 step 1) ───────────────────────────
        $manualPrice = array_key_exists('manual_price', $params) ? $params['manual_price'] : null;

        if ($manualPrice !== null && $manualPrice !== '' && empty($params['__resolving_baseline'])) {
            // Compute what the engine WOULD have priced so the override can
            // be audited (FR-3.9) and the floor guard can run (FR-6.3).
            $baseline = $this->resolvePrice(array_merge($params, [
                'manual_price' => null,
                '__resolving_baseline' => true,
            ]));

            return $this->assembleOverride(
                $product, (float) $manualPrice, $quantity, $documentCurrency, $baseCurrency,
                (string) $date, $params['override_reason'] ?? null, $params, $baseline
            );
        }

        // ── 2. Applicable price list (FR-2.3 / FR-4.2 step 2) ─────────────
        $priceList = $this->resolvePriceList(
            $params['customer_id'] ?? null,
            $documentCurrency,
            $date,
            $params['price_list_id'] ?? null,
        );

        // The catalogue list price (base currency) is the default document
        // currency base and the "based on" reference for percentage rules.
        $variant = $this->resolveVariant($product, $params['variant_id'] ?? null, $params['variant'] ?? null);
        $catalogBasePrice = $this->catalogPrices->catalogBasePrice($product, $variant, $quantity, $params['customer_id'] ?? null, $date);

        if ($priceList) {
            $rule = $this->firstMatchingRule($priceList, $product, $quantity, $date);

            if ($rule) {
                return $this->assemblePriced(
                    $rule, $priceList, $product, $variant, $quantity,
                    $catalogBasePrice, $documentCurrency, $baseCurrency, $date, $params
                );
            }

            // A matched-but-empty list still fixes the document currency
            // (currency per pricelist, §3.1): price it from the catalogue and
            // bridge into the list currency.
            return $this->assembleFromCatalog(
                $catalogBasePrice, $product, $quantity, $priceList, $documentCurrency, $baseCurrency, $date, $params
            );
        }

        // ── 3. No price list: catalogue price (FR-4.2 step 3) ────────────
        return $this->assembleFromCatalog(
            $catalogBasePrice, $product, $quantity, null, $documentCurrency, $baseCurrency, $date, $params
        );
    }

    /**
     * Batch price an entire document's lines in one call (FR-4.6) with one
     * list resolution and ≤ one FX lookup per currency pair (FR-13 perf).
     *
     * @param  array<int,array<string,mixed>>  $lines  each a resolvePrice() $params
     * @return array<int,array>
     */
    public function resolveBatch(array $lines, array $shared = []): array
    {
        $out = [];

        foreach ($lines as $key => $line) {
            try {
                $out[$key] = $this->resolvePrice(array_merge($shared, $line));
            } catch (PricingException $e) {
                $out[$key] = ['error' => $e->getMessage(), 'source' => 'error'];
            }
        }

        return $out;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Price-list applicability + priority (FR-2.2 / FR-2.3)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Resolve the applicable list. Order (FR-2.3): explicit selection →
     * customer-specific → group → global ("all"); ties by lowest sequence,
     * then newest valid_from. A requested document currency restricts the
     * candidates to lists in that currency when any exist (currency per
     * pricelist, §3.1).
     */
    public function resolvePriceList(?int $customerId, string $documentCurrency, string $date, ?int $explicitId = null): ?PriceList
    {
        $this->pricelistsEnabledMemo ??= DocumentCurrencyResolver::pricelistsEnabled();
        if (! $this->pricelistsEnabledMemo) {
            return null;
        }

        $memoKey = implode('|', [$customerId ?? '-', $documentCurrency, $date, $explicitId ?? '-']);

        if (array_key_exists($memoKey, $this->listMemo)) {
            return $this->listMemo[$memoKey];
        }

        $list = $this->computePriceList($customerId, $documentCurrency, $date, $explicitId);

        return $this->listMemo[$memoKey] = $list;
    }

    private function computePriceList(?int $customerId, string $documentCurrency, string $date, ?int $explicitId): ?PriceList
    {
        // 1. Explicit document-level selection wins (FR-2.3).
        if ($explicitId) {
            $explicit = PriceList::active()->whereKey($explicitId)->first();
            if ($explicit && $explicit->appliesOnDate($date)) {
                return $explicit;
            }
        }

        // Candidates that apply on the date and are usable, ordered by the
        // MRD precedence: customer (0) → group (1) → all (2), then sequence,
        // then newest valid_from.
        $candidates = PriceList::query()
            ->active()
            ->with('applications')
            ->get()
            ->filter(fn (PriceList $l) => $l->appliesOnDate($date))
            ->filter(function (PriceList $l): bool {
                // Currency bridge means we CAN use a foreign list, but a list
                // matching the document currency is strongly preferred; when
                // no list matches the document currency we still allow others
                // and bridge. This filter keeps only same-currency lists IF
                // any apply — handled after ranking — so here pass all.
                return true;
            })
            ->map(function (PriceList $l) use ($customerId): ?array {
                $rank = $this->applicabilityRank($l, $customerId);

                return $rank === null ? null : ['list' => $l, 'rank' => $rank];
            })
            ->filter()
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Prefer lists in the document currency (§3.1: the pricelist currency
        // becomes the order currency) when at least one applies.
        $sameCurrency = $candidates->filter(fn ($c) => $c['list']->currency_code === $documentCurrency);
        $pool = $sameCurrency->isNotEmpty() ? $sameCurrency : $candidates;

        $best = $pool->sort(function ($a, $b) {
            // applicability specificity first (FR-2.3)
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            // then lowest sequence
            if ((int) $a['list']->sequence !== (int) $b['list']->sequence) {
                return (int) $a['list']->sequence <=> (int) $b['list']->sequence;
            }
            // then newest valid_from
            $av = $a['list']->valid_from?->getTimestamp() ?? 0;
            $bv = $b['list']->valid_from?->getTimestamp() ?? 0;
            if ($av !== $bv) {
                return $bv <=> $av;
            }

            return (int) $a['list']->id <=> (int) $b['list']->id;
        })->first();

        return $best['list'] ?? null;
    }

    /**
     * 0 = customer-specific, 1 = customer group, 2 = all. NULL when the list
     * does not apply to this customer at all (a list with only group/customer
     * rows that do not match is not applicable).
     */
    private function applicabilityRank(PriceList $list, ?int $customerId): ?int
    {
        $rank = null;

        foreach ($list->applications as $app) {
            match ($app->applies_to_type) {
                \App\Models\PriceListApplication::APPLIES_ALL => $rank = $this->minRank($rank, 2),
                \App\Models\PriceListApplication::APPLIES_CUSTOMER => $customerId !== null
                    && (int) $app->applies_to_id === $customerId ? $rank = $this->minRank($rank, 0) : null,
                \App\Models\PriceListApplication::APPLIES_GROUP => $this->customerInGroup($customerId, $app->applies_to_id)
                    ? $rank = $this->minRank($rank, 1) : null,
                default => null,
            };
        }

        return $rank;
    }

    private function minRank(?int $current, int $candidate): int
    {
        return $current === null ? $candidate : min($current, $candidate);
    }

    private function customerInGroup(?int $customerId, ?int $groupId): bool
    {
        if ($customerId === null || $groupId === null) {
            return false;
        }

        return \App\Models\Customer::whereKey($customerId)
            ->whereHas('categories', fn ($q) => $q->where('customer_categories.id', $groupId))
            ->exists();
    }

    /**
     * Top-down, first match wins (FR-3.5). Matching honours scope, qty band
     * and validity; the product's category ancestor chain is resolved once.
     */
    public function firstMatchingRule(PriceList $list, Product $product, float $quantity, string $date): ?PriceListItem
    {
        $chain = $this->categoryChain($product);

        foreach ($list->items as $item) {
            if ($item->matches($product, $quantity, $date, $chain)) {
                return $item;
            }
        }

        return null;
    }

    private function categoryChain(Product $product): array
    {
        $chain = [];
        $id = $product->category_id;
        $guard = 0;

        while ($id && $guard++ < 20) {
            $chain[] = (int) $id;
            $id = \App\Models\ProductCategory::whereKey($id)->value('parent_id');
        }

        return $chain;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Rule computation (FR-3)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Assemble a priced line from a matched rule.
     *
     * Currency discipline (§9.4 / FR-4.3 / FR-3.7):
     *   - FIXED rules carry their price in the LIST currency directly;
     *   - PERCENTAGE / FORMULA rules compute from bases in the BASE currency
     *     (catalogue prices) or in the referenced list's currency
     *     (other_pricelist); the computed value is brought into the LIST
     *     currency, list rounding is applied to the UNIT price there (FR-3.7,
     *     before qty × and before conversion), and only then is it bridged to
     *     the document currency and rounded to its minor units.
     *
     * @param  array<string,mixed>  $params
     * @param  array{unit_price:float,list_price_raw:float,cost:float,source:string}  $catalog
     */
    private function assemblePriced(
        PriceListItem $rule,
        PriceList $list,
        Product $product,
        ?ProductVariant $variant,
        float $quantity,
        array $catalog,
        string $documentCurrency,
        string $baseCurrency,
        string $date,
        array $params
    ): array {
        $includeCost = (bool) ($params['include_cost'] ?? false);

        $unitPrice = (float) $catalog['list_price_raw'];
        $unitPriceCurrency = $baseCurrency;
        $baseValue = round((float) $catalog['list_price_raw'], 4);
        $baseKind = 'list_price';

        switch ($rule->compute_type) {
            case PriceListItem::COMPUTE_FIXED:
                $unitPrice = (float) ($rule->fixed_price ?? 0);
                $unitPriceCurrency = $list->currency_code;
                $baseValue = null;
                $baseKind = 'fixed';
                break;

            case PriceListItem::COMPUTE_PERCENTAGE:
                // Positive percent = discount, negative = markup (§8.1).
                $unitPrice = max(0, $unitPrice * (1 - (float) ($rule->percent ?? 0) / 100));
                break;

            case PriceListItem::COMPUTE_FORMULA:
                [$baseValue, $baseKind, $unitPriceCurrency] = $this->resolveRuleBase(
                    $rule, $product, $variant, $catalog, $quantity, $date, $params, $baseCurrency
                );

                if ($baseValue === null) {
                    $baseValue = round((float) $catalog['list_price_raw'], 4);
                    $baseKind = 'list_price_fallback';
                    $unitPriceCurrency = $baseCurrency;
                }

                $markup = (float) ($rule->markup_percent ?? 0);
                $discount = (float) ($rule->percent ?? 0);

                $unitPrice = max(0, $baseValue * (1 + $markup / 100) * (1 - $discount / 100));

                if ($rule->fixed_discount !== null) {
                    // Legacy blanket "amount off list" reproduction (FR-12.1):
                    // max(0, base − value), in the base's currency.
                    $unitPrice = max(0, $unitPrice - (float) $rule->fixed_discount);
                }
                break;
        }

        if ($rule->isCostBased() && ! $includeCost) {
            // FR-3.6: cost-based rules may compute the price for a sales user,
            // but the cost INPUT must not be surfaced in the response.
            $baseValue = null;
        }

        // Bring the computed unit price into the LIST currency if it was
        // derived from a base expressed in another currency.
        if ($unitPriceCurrency !== $list->currency_code) {
            $unitPrice = $this->convert($unitPrice, $unitPriceCurrency, $list->currency_code, $date)['amount'];
        }

        // ── List rounding on the unit price, before qty × conversion (§9.4) ──
        $unitPrice = $list->applyRounding($unitPrice);

        // ── Bridge to the DOCUMENT currency, then minor-unit rounding ─────
        $bridge = $this->convert($unitPrice, $list->currency_code, $documentCurrency, $date);
        $unitPrice = $this->roundToMinor($bridge['amount'], $documentCurrency);

        // A coarser list rounding is the stricter policy and re-applies
        // post-conversion in the document currency (FR-4.3).
        $precision = (float) $list->rounding_precision;
        if ($list->rounding_enabled && $precision > $this->minorUnitStep($documentCurrency)) {
            $unitPrice = $this->roundToMinor($list->applyRounding($unitPrice), $documentCurrency);
        }

        $trace = [
            'source' => 'pricelist',
            'pricelist_id' => $list->id,
            'pricelist_code' => $list->code,
            'pricelist_name' => $list->name,
            'pricelist_currency' => $list->currency_code,
            'item_id' => $rule->id,
            'compute_type' => $rule->compute_type,
            'base' => $baseKind,
            'base_value' => $baseValue,
            'percent' => $rule->percent !== null ? (float) $rule->percent : null,
            'markup_percent' => $rule->markup_percent !== null ? (float) $rule->markup_percent : null,
            'fixed_price' => $rule->fixed_price !== null ? (float) $rule->fixed_price : null,
            'fixed_discount' => $rule->fixed_discount !== null ? (float) $rule->fixed_discount : null,
            'rate_used' => $bridge['rate'],
            'rate_date' => $bridge['rate_date'],
            'rate_source' => $bridge['rate_source'],
            'rate_id' => $bridge['rate_id'],
            'rounded_to' => $list->rounding_enabled ? (float) $list->rounding_precision : null,
        ];

        // ── Base-currency unit price (FR-4.4) via the document rate ──────
        $toBase = $this->convert($unitPrice, $documentCurrency, $baseCurrency, $date);

        return [
            'unit_price' => round($unitPrice, 4),
            'base_unit_price' => round($this->roundToMinor($toBase['amount'], $baseCurrency), 4),
            'currency' => $documentCurrency,
            'base_currency' => $baseCurrency,
            'exchange_rate' => round($toBase['rate'], 6),
            'rate_date' => $toBase['rate_date'],
            'rate_id' => $toBase['rate_id'],
            'rate_source' => $toBase['rate_source'],
            'source' => 'pricelist',
            'override' => false,
            'list_price' => round((float) $catalog['list_price_raw'], 4),
            'price_source' => $trace,
        ];
    }

    /**
     * @param  array{unit_price:float,list_price_raw:float,cost:float,source:string}  $catalog
     * @return array{0:float|null,1:string,2:string} [value, kind, value-currency]
     */
    private function resolveRuleBase(
        PriceListItem $rule,
        Product $product,
        ?ProductVariant $variant,
        array $catalog,
        float $quantity,
        string $date,
        array $params,
        string $baseCurrency
    ): array {
        return match ($rule->base) {
            PriceListItem::BASE_COST => [round((float) ($variant?->cost ?? $product->cost), 4), 'cost', $baseCurrency],
            PriceListItem::BASE_OTHER_PRICELIST => $this->otherPricelistBase($rule, $product, $quantity, $date, $params, $baseCurrency),
            default => [round((float) $catalog['list_price_raw'], 4), 'list_price', $baseCurrency],
        };
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array{0:float|null,1:string,2:string}
     */
    private function otherPricelistBase(
        PriceListItem $rule,
        Product $product,
        float $quantity,
        string $date,
        array $params,
        string $baseCurrency
    ): array {
        if (! $rule->other_price_list_id) {
            return [null, 'list_price_fallback', $baseCurrency];
        }

        // Cycle guard: never re-enter a list already visited in this chain.
        $visited = array_values(array_filter($params['__pricelist_chain'] ?? []));

        if (in_array((int) $rule->other_price_list_id, $visited, true)) {
            throw new PricingException('Cyclic "other pricelist" reference detected while resolving rule #'.$rule->id.'.');
        }

        $other = PriceList::active()->whereKey($rule->other_price_list_id)->first();

        if (! $other || ! $other->appliesOnDate($date)) {
            return [null, 'list_price_fallback', $baseCurrency];
        }

        $quote = $this->resolvePrice(array_merge($params, [
            'product' => $product,
            'quantity' => $quantity,
            'date' => $date,
            'document_currency' => $other->currency_code,
            'base_currency' => $baseCurrency,
            'price_list_id' => $other->id,
            'manual_price' => null,
            '__current_list' => $rule->price_list_id,
            '__pricelist_chain' => array_merge($visited, [(int) $rule->price_list_id]),
            'customer_id' => $params['customer_id'] ?? null,
        ]));

        return [
            round((float) $quote['unit_price'], 4),
            'other_pricelist',
            (string) $quote['currency'],
        ];
    }

    /**
     * No rule matched but a list applies: catalogue price bridged to the list
     * (and hence document) currency, list rounding still honoured (FR-3.7).
     *
     * @param  array<string,mixed>  $catalogBasePrice
     * @param  array<string,mixed>  $params
     */
    private function assembleFromCatalog(
        array $catalogBasePrice,
        Product $product,
        float $quantity,
        ?PriceList $list,
        string $documentCurrency,
        string $baseCurrency,
        string $date,
        array $params
    ): array {
        $price = (float) $catalogBasePrice['unit_price'];

        $bridge = $this->convert($price, $baseCurrency, $documentCurrency, $date);
        $amount = $this->roundToMinor($bridge['amount'], $documentCurrency);

        if ($list) {
            // List rounding applies; a coarser list rounding re-rounds to the
            // list step (FR-4.3 stricter-of-the-two rule).
            $rounded = $list->applyRounding($amount);
            if (! $list->rounding_enabled || (float) $list->rounding_precision <= $this->minorUnitStep($documentCurrency)) {
                $amount = $this->roundToMinor($rounded, $documentCurrency);
            } else {
                $amount = $rounded;
            }
        }

        $toBase = $this->convert($amount, $documentCurrency, $baseCurrency, $date);

        return [
            'unit_price' => round($this->roundToMinor($amount, $documentCurrency), 4),
            'base_unit_price' => round($this->roundToMinor($toBase['amount'], $baseCurrency), 4),
            'currency' => $documentCurrency,
            'base_currency' => $baseCurrency,
            'exchange_rate' => round($toBase['rate'], 6),
            'rate_date' => $toBase['rate_date'],
            'rate_id' => $toBase['rate_id'],
            'rate_source' => $toBase['rate_source'],
            'source' => $list ? 'catalog_in_list' : 'catalog',
            'override' => false,
            'list_price' => round($price, 4),
            'price_source' => [
                'source' => $list ? 'catalog_in_list' : 'catalog',
                'pricelist_id' => $list?->id,
                'pricelist_code' => $list?->code,
                'item_id' => null,
                'compute_type' => 'none',
                'base' => $catalogBasePrice['source'],
                'base_value' => round($price, 4),
                'rate_used' => round($toBase['rate'], 6),
                'rate_date' => $toBase['rate_date'],
                'rate_source' => $toBase['rate_source'],
                'rate_id' => $toBase['rate_id'],
            ],
        ];
    }

    /**
     * Manual override handling (FR-4.2 step 1 / FR-6.3). The manual value is
     * taken verbatim in the document currency, but the engine also records
     * the price it would have resolved and enforces the tenant's override
     * floor: a manual price more than `pricing.override_floor_percent`
     * percent below the resolved price (or below product cost) requires an
     * `override_reason` — otherwise the save is refused with a distinct
     * error. The comparison happens in BASE-currency space so foreign
     * documents are guarded identically.
     *
     * @param  array<string,mixed>  $params
     * @param  array<string,mixed>  $baseline  engine-resolved quote without the manual value
     */
    private function assembleOverride(
        Product $product,
        float $manualPrice,
        float $quantity,
        string $documentCurrency,
        string $baseCurrency,
        string $date,
        ?string $reason,
        array $params,
        array $baseline
    ): array {
        $toBase = $this->convert($manualPrice, $documentCurrency, $baseCurrency, $date);
        $manualBase = $this->roundToMinor($toBase['amount'], $baseCurrency);

        $floorPct = (float) \App\Models\Setting::getValue('pricing.override_floor_percent', 10);
        $belowCostGuard = (bool) \App\Models\Setting::getValue('pricing.override_below_cost_requires_reason', true);

        $resolvedBase = (float) $baseline['base_unit_price'];
        $costBase = round((float) ($product->cost ?? 0), 4);
        // Variant cost, when the line is variant-priced.
        $variant = $this->resolveVariant($product, $params['variant_id'] ?? null, $params['variant'] ?? null);
        if ($variant && $variant->cost !== null) {
            $costBase = round((float) $variant->cost, 4);
        }

        $belowFloor = $floorPct > 0 && $resolvedBase > 0
            && $manualBase < $resolvedBase * (1 - $floorPct / 100);
        $belowCost = $belowCostGuard && $costBase > 0 && $manualBase < $costBase;

        if (($belowFloor || $belowCost) && blank($reason)) {
            throw new PricingException(
                'Manual price is below the allowed floor '.
                '('.($belowCost ? 'below cost' : 'more than '.rtrim(rtrim(number_format($floorPct, 2), '0'), '.').'% below the resolved price').
                '). An override_reason is required for audit.'
            );
        }

        $trace = [
            'source' => 'manual',
            'pricelist_id' => $baseline['price_source']['pricelist_id'] ?? null,
            'resolved_item_id' => $baseline['price_source']['item_id'] ?? null,
            'resolved_source' => $baseline['source'],
            'resolved_unit_price' => $baseline['unit_price'],
            'resolved_base_unit_price' => $baseline['base_unit_price'],
            'currency' => $documentCurrency,
            'item_id' => null,
            'compute_type' => 'fixed',
            'base' => 'manual',
            'override_reason' => $reason,
            'override_below_floor' => $belowFloor || $belowCost,
            'override_floor_kind' => $belowCost ? 'below_cost' : ($belowFloor ? 'below_percent_floor' : null),
            'rate_used' => round($toBase['rate'], 6),
            'rate_date' => $toBase['rate_date'],
            'rate_source' => $toBase['rate_source'],
            'rate_id' => $toBase['rate_id'],
        ];

        return [
            'unit_price' => round($manualPrice, 4),
            'base_unit_price' => round($manualBase, 4),
            'currency' => $documentCurrency,
            'base_currency' => $baseCurrency,
            'exchange_rate' => round($toBase['rate'], 6),
            'rate_date' => $toBase['rate_date'],
            'rate_id' => $toBase['rate_id'],
            'rate_source' => $toBase['rate_source'],
            'source' => 'manual',
            'override' => true,
            'list_price' => round((float) $baseline['list_price'], 4),
            'price_source' => $trace,
        ];
    }

    /**
     * Smallest currency step for the given code (10^-minor), used to compare
     * list-rounding coarseness against minor-unit rounding (FR-4.3).
     */
    public function minorUnitStep(?string $currency): float
    {
        return (float) pow(10, -$this->currencyUnits($currency));
    }

    // ════════════════════════════════════════════════════════════════════
    //  Currency bridging (FR-1.6 / FR-4.3) — memoized, deterministic
    // ════════════════════════════════════════════════════════════════════

    /**
     * Convert an amount between currencies at a date. Same-currency is an
     * identity (rate exactly 1). Cross pairs resolve direct → inverse → via
     * base through ExchangeRateService, and the rate/effective-date/source/
     * id used are returned so the caller can snapshot them (FR-3.9).
     *
     * Minor-unit rounding is applied by the CALLER at each stage (list-currency
     * rounding before conversion, currency minor-unit rounding after — §9.4);
     * here we only return the raw converted amount so the two-stage rule can be
     * composed without double-rounding.
     *
     * @return array{amount:float,rate:float,rate_date:string,rate_source:string,rate_id:int|null}
     */
    public function convert(float $amount, string $from, string $to, string $date): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return ['amount' => $amount, 'rate' => 1.0, 'rate_date' => $date, 'rate_source' => 'same_currency', 'rate_id' => null];
        }

        $memoKey = "{$from}|{$to}|{$date}";

        if (isset($this->rateMemo[$memoKey])) {
            $r = $this->rateMemo[$memoKey];

            return ['amount' => $amount * $r['rate'], 'rate' => $r['rate'], 'rate_date' => $r['rate_date'], 'rate_source' => $r['rate_source'], 'rate_id' => $r['rate_id']];
        }

        $resolved = $this->exchangeRates->getLatestRate($from, $to, $date);

        if (! $resolved) {
            throw new PricingException(
                "No exchange rate found for {$from} to {$to} at or before {$date}. ".
                'Enable the currency and record a rate before pricing this document.'
            );
        }

        $this->rateMemo[$memoKey] = [
            'rate' => (float) $resolved['rate'],
            'rate_date' => $resolved['effective_date'],
            'rate_source' => $resolved['source'],
            'rate_id' => $resolved['rate_id'] ?? null,
        ];

        $r = $this->rateMemo[$memoKey];

        return ['amount' => $amount * $r['rate'], 'rate' => $r['rate'], 'rate_date' => $r['rate_date'], 'rate_source' => $r['rate_source'], 'rate_id' => $r['rate_id']];
    }

    /**
     * Round a converted amount to the currency's minor units (FR-4.3). JPY has
     * 0 decimals; the default 2-decimal ISO assumption holds otherwise (§5.2).
     */
    public function roundToMinor(float $amount, ?string $currency): float
    {
        return round($amount, $this->currencyUnits($currency));
    }

    private function currencyUnits(?string $currency): int
    {
        if (! $currency) {
            return 2;
        }

        $code = strtoupper($currency);

        if (! array_key_exists($code, $this->currencyUnitsMemo)) {
            $row = \App\Models\Currency::query()->where('code', $code)->first();
            $this->currencyUnitsMemo[$code] = $row ? $row->minorUnits() : 2;
        }

        return $this->currencyUnitsMemo[$code];
    }

    private function normalizeDate(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return Carbon::instance(Carbon::parse($date))->toDateString();
        }

        if (is_string($date) && $date !== '') {
            return Carbon::parse($date)->toDateString();
        }

        return now()->toDateString();
    }

    // ════════════════════════════════════════════════════════════════════
    //  Base-currency convenience for non-document surfaces
    // ════════════════════════════════════════════════════════════════════

    /**
     * Price a single product line in the BASE currency for surfaces that do
     * not carry a document currency (POS terminal, storefront cart, e-commerce
     * checkout, price preview API, CSV imports). Honours the customer's
     * price-list rules when one applies (currency-bridged to base), else the
     * Issue #132 tier engine — the historical behaviour when no list exists.
     *
     * Never throws on FX gaps: a foreign-currency list with no rate falls
     * back to the catalogue price so carts/previews cannot hard-fail.
     */
    public function baseCurrencyPrice(Product $product, float $quantity, ?int $customerId = null, ?int $variantId = null, ?string $date = null, ?ProductVariant $variant = null): float
    {
        $date = $this->normalizeDate($date);
        $memoKey = 'c:'.($customerId ?? '-');
        $this->baseCurrencyMemo[$memoKey] ??= DocumentCurrencyResolver::baseFor(null, $customerId);
        $base = $this->baseCurrencyMemo[$memoKey];

        try {
            $quote = $this->resolvePrice([
                'product' => $product,
                'quantity' => max(1.0, $quantity),
                'date' => $date,
                'customer_id' => $customerId,
                'document_currency' => $base,
                'base_currency' => $base,
                'variant_id' => $variantId,
                'variant' => $variant,
            ]);

            return round((float) $quote['unit_price'], 2);
        } catch (\Throwable) {
            return round((float) $product->getPriceForQuantity(
                (int) ceil(max(1.0, $quantity)),
                $customerId,
                \Illuminate\Support\Carbon::parse($date),
                $variantId,
            ), 2);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  Document integration helpers (FR-4.1 / FR-5.3)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Build the pricing context for one document header. When the header
     * already carries a currency + snapshot the snapshot wins (FR-1.7 /
     * FR-5.5: conversions never re-derive rates); otherwise a fresh snapshot
     * is taken (FR-5.1).
     *
     * @param  array{customer_id?:int|null, legal_entity_id?:int|null, date?:string|null,
     *              currency_code?:string|null, exchange_rate?:string|float|null,
     *              base_currency_code?:string|null, price_list_id?:int|null,
     *              include_cost?:bool}  $input
     * @return array{date:string, customer_id:int|null, legal_entity_id:int|null,
     *               document_currency:string, base_currency:string, exchange_rate:float,
     *               price_list_id:int|null, rate:array, include_cost:bool}
     */
    public function documentContext(array $input): array
    {
        $date = $this->normalizeDate($input['date'] ?? null);
        $customerId = isset($input['customer_id']) ? (int) $input['customer_id'] : null;
        $entityId = isset($input['legal_entity_id']) ? (int) $input['legal_entity_id'] : null;

        $existingCurrency = isset($input['currency_code']) ? strtoupper(trim((string) $input['currency_code'])) : '';
        $existingRate = isset($input['exchange_rate']) && $input['exchange_rate'] !== null && $input['exchange_rate'] !== ''
            ? (float) $input['exchange_rate']
            : null;

        if ($existingCurrency !== '' && $existingRate !== null) {
            $base = strtoupper((string) ($input['base_currency_code'] ?? '') ?: DocumentCurrencyResolver::baseFor($entityId, $customerId));
            $rate = [
                'currency_code' => $existingCurrency,
                'exchange_rate' => $existingRate,
                'base_currency_code' => $base,
                'rate_date' => $date,
                'rate_source' => 'snapshot',
                'rate_id' => null,
                'rate_stale' => false,
            ];
        } else {
            $rate = DocumentCurrencyResolver::snapshot(
                $existingCurrency !== '' ? $existingCurrency : ($input['currency_code'] ?? null),
                $customerId,
                $entityId,
                $date
            );
        }

        return [
            'date' => $date,
            'customer_id' => $customerId,
            'legal_entity_id' => $entityId,
            'document_currency' => $rate['currency_code'],
            'base_currency' => $rate['base_currency_code'],
            'exchange_rate' => (float) $rate['exchange_rate'],
            'price_list_id' => isset($input['price_list_id']) ? (int) $input['price_list_id'] : null,
            'rate' => $rate,
            'include_cost' => (bool) ($input['include_cost'] ?? false),
        ];
    }

    /**
     * Price one payload line for a document create/update path and return the
     * columns the line create array needs (FR-5.3).
     *
     * $priceMode:
     *   auto    — server-authoritative re-resolution; a client value is ignored
     *   manual  — client value is an override (floor guard runs, FR-6.3)
     *   carried — conversion propagation (FR-5.5): keep the value verbatim and
     *             the supplied trace/base when present
     *   legacy  — historical callers without intent: a supplied value is kept
     *             (backward compatible), a missing one resolves
     *
     * @param  array<string,mixed>  $lineData
     * @param  array<string,mixed>  $context  from documentContext()
     * @return array<string,mixed> merge-into-create-array
     */
    public function priceLineArray(?Product $product, array $lineData, array $context, string $priceMode = 'legacy'): array
    {
        if (! $product) {
            // Non-product lines (text/note/section) or unknown products keep
            // whatever the caller supplied; still stamp a base equivalent when
            // an amount exists so dual display stays honest.
            $out = [];
            if (($priceMode === 'auto' || $priceMode === 'legacy') && array_key_exists('unit_price', $lineData)) {
                $price = (float) ($lineData['unit_price'] ?? 0);
                $out['unit_price'] = $price;
                $out['base_unit_price'] = $context['exchange_rate'] > 0 && $context['document_currency'] !== $context['base_currency']
                    ? round($price * $context['exchange_rate'], 4)
                    : $price;
            }

            return $out;
        }

        $qty = (float) ($lineData['quantity'] ?? 1);
        $params = [
            'product' => $product,
            'quantity' => $qty > 0 ? $qty : 1,
            'date' => $context['date'],
            'customer_id' => $context['customer_id'],
            'legal_entity_id' => $context['legal_entity_id'],
            'document_currency' => $context['document_currency'],
            'base_currency' => $context['base_currency'],
            'price_list_id' => $context['price_list_id'],
            'variant_id' => isset($lineData['variant_id']) ? $lineData['variant_id'] : null,
            'include_cost' => $context['include_cost'],
        ];

        switch ($priceMode) {
            case 'carried':
                $price = (float) ($lineData['unit_price'] ?? 0);
                $base = array_key_exists('base_unit_price', $lineData) && $lineData['base_unit_price'] !== null
                    ? (float) $lineData['base_unit_price']
                    : ($context['document_currency'] === $context['base_currency']
                        ? $price
                        : round($price * $context['exchange_rate'], 4));

                return [
                    'unit_price' => $price,
                    'base_unit_price' => $base,
                    'pricing_trace' => $lineData['pricing_trace'] ?? [
                        'source' => 'carried',
                        'currency' => $context['document_currency'],
                        'rate_used' => $context['exchange_rate'],
                    ],
                ];

            case 'auto':
                $quote = $this->resolvePrice($params);
                break;

            case 'manual':
                $quote = $this->resolvePrice(array_merge($params, [
                    'manual_price' => (float) ($lineData['unit_price'] ?? 0),
                    'override_reason' => $lineData['override_reason'] ?? null,
                ]));
                break;

            default: // legacy
                if (array_key_exists('unit_price', $lineData) && $lineData['unit_price'] !== null && (float) $lineData['unit_price'] > 0) {
                    return [
                        'unit_price' => (float) $lineData['unit_price'],
                        'base_unit_price' => $context['document_currency'] === $context['base_currency']
                            ? (float) $lineData['unit_price']
                            : round((float) $lineData['unit_price'] * $context['exchange_rate'], 4),
                    ];
                }
                $quote = $this->resolvePrice($params);
        }

        $trace = $quote['price_source'];
        $trace['document_currency'] = $quote['currency'];
        $trace['base_currency'] = $quote['base_currency'];
        $trace['exchange_rate'] = $quote['exchange_rate'];

        return [
            'unit_price' => (float) $quote['unit_price'],
            'base_unit_price' => (float) $quote['base_unit_price'],
            'pricing_trace' => $trace,
            'list_price' => (float) $quote['list_price'],
        ];
    }

    private function resolveVariant(Product $product, ?int $variantId, ?ProductVariant $preloaded = null): ?ProductVariant
    {
        if (! $variantId) {
            return null;
        }

        // Callers that already batched variant identity (storefront cart,
        // POS) pass the model so the engine adds no extra query per line.
        if ($preloaded !== null && (int) $preloaded->id === (int) $variantId) {
            return $preloaded;
        }

        return ProductVariant::where('product_id', $product->id)->whereKey($variantId)->first();
    }
}
