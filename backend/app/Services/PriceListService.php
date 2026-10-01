<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\PriceListApplication;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;

/**
 * Price-list administration service (MRD pricelist-multicurrency-mrd FR-2).
 *
 * Business rules enforced here (single authority — controllers stay thin):
 *   • header currency must be an enabled, sellable currency (FR-1.1);
 *   • items are validated per compute type (FR-3.3/3.4);
 *   • cost-based rules require the cost capability (FR-3.6);
 *   • deactivation keeps historical documents reproducible (FR-2.5);
 *   • delete is blocked while customers still default to the list;
 *   • clone copies validity + items + applications (FR-2.6);
 *   • every mutation is audit-logged with before/after state (FR-10.3).
 */
class PriceListService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data, ?int $actorId = null): PriceList
    {
        return DB::transaction(function () use ($data, $actorId) {
            $this->assertHeader($data);

            $list = PriceList::create([
                'name' => $data['name'],
                'code' => $this->normalizeCode($data['code'] ?? null),
                'currency_code' => strtoupper($data['currency_code']),
                'description' => $data['description'] ?? null,
                'sequence' => (int) ($data['sequence'] ?? 10),
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'rounding_enabled' => (bool) ($data['rounding_enabled'] ?? false),
                'rounding_method' => $data['rounding_method'] ?? 'nearest',
                'rounding_precision' => (float) ($data['rounding_precision'] ?? 0.01),
                'discount_display' => $data['discount_display'] ?? PriceList::DISPLAY_SHOW_DISCOUNTED_PRICE,
                'created_by' => $actorId,
            ]);

            $this->syncItems($list, $data['items'] ?? [], $actorId);
            $this->syncApplications($list, $data['applications'] ?? [['applies_to_type' => 'all']]);

            $this->audit('pricelist.created', $list, null, $list->fresh(['items', 'applications'])->toArray(), $actorId);

            return $list->fresh(['items.product', 'items.category', 'applications']);
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(PriceList $list, array $data, ?int $actorId = null): PriceList
    {
        return DB::transaction(function () use ($list, $data, $actorId) {
            $before = $list->fresh(['items', 'applications'])->toArray();

            $header = collect($data)->only([
                'name', 'code', 'currency_code', 'description', 'sequence',
                'valid_from', 'valid_until', 'is_active', 'rounding_enabled',
                'rounding_method', 'rounding_precision', 'discount_display',
            ])->all();

            if (array_key_exists('code', $header)) {
                $header['code'] = $this->normalizeCode($header['code']);
            }

            if (array_key_exists('currency_code', $data)) {
                $data['currency_code'] = strtoupper((string) $data['currency_code']);
                DocumentCurrencyResolver::assertSellable($data['currency_code'], 'price list');
            }

            if (array_key_exists('valid_from', $data) && array_key_exists('valid_until', $data)
                && $data['valid_from'] && $data['valid_until']
                && \Illuminate\Support\Carbon::parse($data['valid_until'])->lt(\Illuminate\Support\Carbon::parse($data['valid_from']))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'valid_until' => 'The until date must be on or after the from date.',
                ]);
            }

            $list->update($header);

            if (array_key_exists('items', $data)) {
                $this->syncItems($list, $data['items'] ?? [], $actorId);
            }

            if (array_key_exists('applications', $data)) {
                $this->syncApplications($list, $data['applications'] ?? []);
            }

            $this->audit('pricelist.updated', $list, $before, $list->fresh(['items', 'applications'])->toArray(), $actorId);

            return $list->fresh(['items.product', 'items.category', 'applications']);
        });
    }

    public function clone(PriceList $list, ?int $actorId = null): PriceList
    {
        return DB::transaction(function () use ($list, $actorId) {
            $copy = PriceList::create([
                'name' => $list->name.' (copy)',
                'code' => $this->nextCloneCode($list->code),
                'currency_code' => $list->currency_code,
                'description' => $list->description,
                'sequence' => (int) $list->sequence,
                'valid_from' => $list->valid_from,
                'valid_until' => $list->valid_until,
                'is_active' => false, // a clone starts inactive: audit-friendly seasonal copies (FR-2.6)
                'rounding_enabled' => (bool) $list->rounding_enabled,
                'rounding_method' => $list->rounding_method,
                'rounding_precision' => (float) $list->rounding_precision,
                'discount_display' => $list->discount_display,
                'created_by' => $actorId,
            ]);

            foreach ($list->items as $item) {
                $row = $item->toArray();
                unset($row['id'], $row['price_list_id'], $row['created_at'], $row['updated_at']);
                $copy->items()->create($row);
            }

            foreach ($list->applications as $app) {
                $copy->applications()->create([
                    'applies_to_type' => $app->applies_to_type,
                    'applies_to_id' => $app->applies_to_id,
                ]);
            }

            $this->audit('pricelist.cloned', $copy, ['source_id' => $list->id], $copy->toArray(), $actorId);

            return $copy->fresh(['items', 'applications']);
        });
    }

    public function delete(PriceList $list, ?int $actorId = null): void
    {
        $customerCount = $list->customers()->count();
        $otherPricedCount = PriceListItem::where('other_price_list_id', $list->id)->count();

        if ($customerCount > 0 || $otherPricedCount > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'price_list' => "This list is still referenced by {$customerCount} customer(s) and {$otherPricedCount} rule(s). Deactivate it instead — posted documents stay reproducible (FR-2.5).",
            ]);
        }

        $before = $list->fresh(['items', 'applications'])->toArray();
        $list->delete();

        $this->audit('pricelist.deleted', $list, $before, null, $actorId);
    }

    // ════════════════════════════════════════════════════════════════════

    private function assertHeader(array $data, bool $requiring = true): void
    {
        if ($requiring && empty($data['name'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'required']);
        }

        if (! empty($data['currency_code'])) {
            DocumentCurrencyResolver::assertSellable(strtoupper((string) $data['currency_code']), 'price list');
        } elseif ($requiring) {
            throw \Illuminate\Validation\ValidationException::withMessages(['currency_code' => 'A price list requires a currency (FR-2.1).']);
        }

        if (! empty($data['code'])) {
            $code = $this->normalizeCode($data['code']);
            if (PriceList::where('code', $code)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['code' => "Code '{$code}' is already used by another price list."]);
            }
        }
    }

    private function normalizeCode(?string $code): ?string
    {
        $code = is_string($code) ? trim($code) : null;

        return ($code === null || $code === '') ? null : strtoupper($code);
    }

    private function nextCloneCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $candidate = $code.'-COPY';
        $i = 1;

        while (PriceList::where('code', $candidate)->exists()) {
            $candidate = $code.'-COPY-'.(++$i);
        }

        return $candidate;
    }

    /**
     * MRD pricelist-multicurrency FR-2.7 — export the items as portable rows
     * (CSV columns, resolved to human keys: product SKU, category name,
     * other-list code). Ordered exactly as the engine evaluates them.
     *
     * @return array<int,array<string,string|float|null>>
     */
    public function exportItemRows(PriceList $list): array
    {
        $list->loadMissing(['items.product', 'items.category', 'items.otherPriceList']);

        return $list->items->map(fn (PriceListItem $item) => [
            'sequence' => $item->sequence,
            'apply_on' => $item->apply_on,
            'product_sku' => $item->product?->sku ?? '',
            'category_name' => $item->category?->name ?? '',
            'min_quantity' => (float) $item->min_quantity,
            'max_quantity' => $item->max_quantity !== null ? (float) $item->max_quantity : '',
            'date_start' => optional($item->date_start)->toDateString() ?? '',
            'date_end' => optional($item->date_end)->toDateString() ?? '',
            'compute_type' => $item->compute_type,
            'fixed_price' => $item->fixed_price !== null ? (float) $item->fixed_price : '',
            'percent' => $item->percent !== null ? (float) $item->percent : '',
            'base' => $item->base ?? '',
            'other_pricelist_code' => $item->otherPriceList?->code ?? '',
            'markup_percent' => $item->markup_percent !== null ? (float) $item->markup_percent : '',
        ])->all();
    }

    /**
     * MRD pricelist-multicurrency FR-2.7 — import item rows (full replace,
     * matching the PUT /items contract). Rows are resolved and validated
     * BEFORE any mutation (fail-closed); unknown SKUs / categories / other
     * lists abort the import with row-addressed errors.
     *
     * @param  array<int,array<string,string|null>>  $rows
     * @return array{imported:int}
     */
    public function importItemRows(PriceList $list, array $rows, ?int $actorId = null): array
    {
        $items = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $lineNo = $index + 1;
            $applyOn = strtolower(trim((string) ($row['apply_on'] ?? 'product')));
            $compute = strtolower(trim((string) ($row['compute_type'] ?? 'percentage')));

            $productId = null;
            $categoryId = null;

            if ($applyOn === 'product') {
                $sku = trim((string) ($row['product_sku'] ?? ''));
                if ($sku === '') {
                    $errors["items.{$lineNo}"] = 'product_sku is required for product-scope rows.';

                    continue;
                }
                $product = Product::query()->where('sku', $sku)->first();
                if (! $product) {
                    $errors["items.{$lineNo}"] = "Unknown product SKU '{$sku}'.";

                    continue;
                }
                $productId = $product->id;
            } elseif ($applyOn === 'category') {
                $name = trim((string) ($row['category_name'] ?? ''));
                if ($name === '') {
                    $errors["items.{$lineNo}"] = 'category_name is required for category-scope rows.';

                    continue;
                }
                $category = ProductCategory::query()->where('name', $name)->first();
                if (! $category) {
                    $errors["items.{$lineNo}"] = "Unknown product category '{$name}'.";

                    continue;
                }
                $categoryId = $category->id;
            } elseif ($applyOn !== 'all') {
                $errors["items.{$lineNo}"] = "apply_on must be product, category or all (got '{$applyOn}').";

                continue;
            }

            $otherListId = null;
            $otherCode = trim((string) ($row['other_pricelist_code'] ?? ''));
            $base = trim((string) ($row['base'] ?? '')) ?: null;

            if ($base === 'other_pricelist') {
                if ($otherCode === '') {
                    $errors["items.{$lineNo}"] = 'other_pricelist_code is required when base=other_pricelist.';

                    continue;
                }
                $other = PriceList::query()->where('code', $otherCode)->first();
                if (! $other) {
                    $errors["items.{$lineNo}"] = "Unknown price list code '{$otherCode}' for other_pricelist base.";

                    continue;
                }
                if ((int) $other->id === (int) $list->id) {
                    $errors["items.{$lineNo}"] = 'A list cannot base on itself.';

                    continue;
                }
                $otherListId = $other->id;
            }

            $items[] = [
                'sequence' => is_numeric($row['sequence'] ?? null) ? (int) $row['sequence'] : ($lineNo * 10),
                'apply_on' => $applyOn,
                'product_id' => $productId,
                'category_id' => $categoryId,
                'min_quantity' => is_numeric($row['min_quantity'] ?? null) ? (float) $row['min_quantity'] : 1,
                'max_quantity' => is_numeric($row['max_quantity'] ?? null) ? (float) $row['max_quantity'] : null,
                'date_start' => trim((string) ($row['date_start'] ?? '')) ?: null,
                'date_end' => trim((string) ($row['date_end'] ?? '')) ?: null,
                'compute_type' => $compute,
                'fixed_price' => is_numeric($row['fixed_price'] ?? null) ? (float) $row['fixed_price'] : null,
                'percent' => is_numeric($row['percent'] ?? null) ? (float) $row['percent'] : null,
                'base' => $compute === 'formula' ? ($base ?? 'list_price') : null,
                'other_price_list_id' => $otherListId,
                'markup_percent' => is_numeric($row['markup_percent'] ?? null) ? (float) $row['markup_percent'] : null,
            ];
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        if ($items === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'items' => 'No importable rows found. Download the export for the expected columns.',
            ]);
        }

        // syncItems is validate-before-mutate; a semantic failure aborts
        // before the table is touched.
        $this->syncItems($list, $items, $actorId);

        $this->audit('pricelist.items_imported', $list, null, ['imported' => count($items)], $actorId);

        return ['imported' => count($items)];
    }

    /**
     * Full replace semantics for items (FR-11 /{id}/items PUT). Validated per
     * compute type; cost-based rules need the cost capability (FR-3.6).
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    public function syncItems(PriceList $list, array $items, ?int $actorId = null): void
    {
        $maySeeCost = $this->currentActorCan('pricelists.cost') || $this->currentActorCan('pricelists.manage');

        // Validate before mutating anything (fail-closed).
        foreach ($items as $idx => $item) {
            $compute = $item['compute_type'] ?? null;

            if (! in_array($compute, ['fixed', 'percentage', 'formula'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$idx}.compute_type" => 'must be fixed, percentage or formula (FR-3.3).',
                ]);
            }

            if ($compute === 'fixed' && ! isset($item['fixed_price'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$idx}.fixed_price" => 'required for fixed rules (list currency).',
                ]);
            }

            if ($compute === 'percentage' && ! isset($item['percent'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$idx}.percent" => 'required for percentage rules (positive = discount, negative = markup).',
                ]);
            }

            if ($compute === 'formula') {
                $base = $item['base'] ?? null;

                if (! in_array($base, ['list_price', 'cost', 'other_pricelist'], true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "items.{$idx}.base" => 'formula rules require base ∈ list_price | cost | other_pricelist (FR-3.4).',
                    ]);
                }

                if ($base === 'other_pricelist' && empty($item['other_price_list_id'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "items.{$idx}.other_price_list_id" => 'required when the formula bases on another price list.',
                    ]);
                }

                if ($base === 'cost' && ! $maySeeCost) {
                    // FR-3.6 / §10 SoD: cost-based rules are manage+ capability.
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "items.{$idx}.base" => 'cost-based rules require the price-list cost capability (pricelists.cost).',
                    ]);
                }

                if (! isset($item['percent']) && ! isset($item['markup_percent']) && ! isset($item['fixed_discount'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "items.{$idx}" => 'a formula rule needs markup_percent, percent (discount) or fixed_discount.',
                    ]);
                }
            }

            if (($item['other_price_list_id'] ?? null) && (int) $item['other_price_list_id'] === (int) $list->id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$idx}.other_price_list_id" => 'a list cannot base on itself.',
                ]);
            }

            $applyOn = $item['apply_on'] ?? 'product';
            if ($applyOn === 'product' && empty($item['product_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(["items.{$idx}.product_id" => 'required for product-scope rules.']);
            }
            if ($applyOn === 'category' && empty($item['category_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(["items.{$idx}.category_id" => 'required for category-scope rules.']);
            }
        }

        $list->items()->delete();

        foreach ($items as $idx => $item) {
            PriceListItem::create([
                'price_list_id' => $list->id,
                'sequence' => (int) ($item['sequence'] ?? (($idx + 1) * 10)),
                'apply_on' => $item['apply_on'] ?? 'product',
                'product_id' => ($item['apply_on'] ?? 'product') === 'product' ? ($item['product_id'] ?? null) : null,
                'category_id' => ($item['apply_on'] ?? 'product') === 'category' ? ($item['category_id'] ?? null) : null,
                'min_quantity' => (float) ($item['min_quantity'] ?? 1),
                'max_quantity' => isset($item['max_quantity']) ? (float) $item['max_quantity'] : null,
                'date_start' => $item['date_start'] ?? null,
                'date_end' => $item['date_end'] ?? null,
                'compute_type' => $item['compute_type'],
                'fixed_price' => isset($item['fixed_price']) && $item['fixed_price'] !== null ? (float) $item['fixed_price'] : null,
                'percent' => isset($item['percent']) && $item['percent'] !== null ? (float) $item['percent'] : null,
                'base' => ($item['compute_type'] ?? null) === 'formula' ? ($item['base'] ?? 'list_price') : null,
                'other_price_list_id' => ($item['base'] ?? null) === 'other_pricelist' ? ($item['other_price_list_id'] ?? null) : null,
                'markup_percent' => isset($item['markup_percent']) && $item['markup_percent'] !== null ? (float) $item['markup_percent'] : null,
                'fixed_discount' => isset($item['fixed_discount']) && $item['fixed_discount'] !== null ? (float) $item['fixed_discount'] : null,
            ]);
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $applications
     */
    public function syncApplications(PriceList $list, array $applications): void
    {
        $list->applications()->delete();

        foreach ($applications as $idx => $app) {
            $type = $app['applies_to_type'] ?? 'all';

            if (! in_array($type, ['all', 'customer_group', 'customer'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "applications.{$idx}.applies_to_type" => 'must be all | customer_group | customer (FR-2.2).',
                ]);
            }

            $id = $app['applies_to_id'] ?? null;

            if ($type !== 'all' && ! $id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "applications.{$idx}.applies_to_id" => "required for '{$type}' applicability.",
                ]);
            }

            PriceListApplication::create([
                'price_list_id' => $list->id,
                'applies_to_type' => $type,
                'applies_to_id' => $type === 'all' ? null : (int) $id,
            ]);
        }
    }

    private function currentActorCan(string $permission): bool
    {
        $user = \Illuminate\Support\Facades\Auth::user();

        if (! $user) {
            return false; // CLI/seed context without a user: no elevation.
        }

        if ($user->is_super_admin ?? false) {
            return true;
        }

        try {
            return app(TenantRoleService::class)->hasPermission((int) $user->id, (string) tenant('id'), $permission);
        } catch (\Throwable) {
            return false;
        }
    }

    private function audit(string $action, PriceList $list, ?array $before, ?array $after, ?int $actorId): void
    {
        try {
            $this->auditLogger->log(
                operation: $action,
                referenceType: 'price_list',
                referenceId: (int) $list->id,
                actorId: $actorId,
                beforeState: $before,
                afterState: $after,
                metadata: ['currency' => $list->currency_code, 'mr' => 'pricelist-multicurrency'],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('pricelist audit log failed', [
                'action' => $action,
                'price_list_id' => $list->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
