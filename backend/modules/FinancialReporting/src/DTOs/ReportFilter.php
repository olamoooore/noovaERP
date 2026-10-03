<?php

namespace Modules\FinancialReporting\DTOs;

use Carbon\Carbon;

class ReportFilter
{
    public function __construct(
        public ?Carbon $startDate = null,
        public ?Carbon $endDate = null,
        public ?Carbon $asOfDate = null,
        public ?Carbon $compareStartDate = null,
        public ?Carbon $compareEndDate = null,
        public ?int $accountId = null,
        public ?string $accountType = null,
        public ?int $departmentId = null,
        public ?int $projectId = null,
        public ?int $locationId = null,
        public ?int $customerId = null,
        public ?int $vendorId = null,
        public ?int $productId = null,
        public ?int $categoryId = null,
        public ?string $interval = 'day', // day, week, month, quarter, year
        public int $page = 1,
        public int $perPage = 50,
        public ?int $legalEntityId = null,
        public ?int $entityBookId = null,
        /** Tag slug — restrict the report to documents carrying this tag. */
        public ?string $tag = null,
        /** MRD FR-8.1: restrict sales analytics to a document currency. */
        public ?string $currencyCode = null,
        /** Issue #593: the customer's assigned salesperson (customers.salesperson_id). */
        public ?int $salespersonId = null,
        /** Issue #593: sales channel — pos | admin | ecommerce. */
        public ?string $channel = null,
        /**
         * Issue #595: comparison mode — previous_period | same_period_last_year
         * | custom. Null means no comparison; explicit compareStartDate/
         * compareEndDate imply `custom`.
         */
        public ?string $comparisonMode = null,
    ) {}

    /**
     * Create from request array
     */
    public static function fromRequest(array $data): self
    {
        // Issue #595: `comparison` selects a derived window; supplying the two
        // compare dates without a mode is still an explicit custom range.
        $comparisonMode = isset($data['comparison']) && $data['comparison'] !== ''
            ? strtolower((string) $data['comparison'])
            : null;
        if ($comparisonMode === null
            && (isset($data['compare_start_date']) || isset($data['compare_end_date']))) {
            $comparisonMode = 'custom';
        }

        return new self(
            startDate: isset($data['start_date']) ? Carbon::parse($data['start_date']) : null,
            endDate: isset($data['end_date']) ? Carbon::parse($data['end_date']) : null,
            asOfDate: isset($data['as_of_date']) ? Carbon::parse($data['as_of_date']) : null,
            compareStartDate: isset($data['compare_start_date']) ? Carbon::parse($data['compare_start_date']) : null,
            compareEndDate: isset($data['compare_end_date']) ? Carbon::parse($data['compare_end_date']) : null,
            accountId: $data['account_id'] ?? null,
            accountType: $data['account_type'] ?? null,
            departmentId: $data['department_id'] ?? null,
            projectId: $data['project_id'] ?? null,
            locationId: $data['location_id'] ?? null,
            customerId: $data['customer_id'] ?? null,
            vendorId: $data['vendor_id'] ?? null,
            productId: $data['product_id'] ?? null,
            categoryId: $data['category_id'] ?? null,
            interval: $data['interval'] ?? 'day',
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 50),
            legalEntityId: isset($data['legal_entity_id']) ? (int) $data['legal_entity_id'] : null,
            entityBookId: isset($data['entity_book_id']) ? (int) $data['entity_book_id'] : null,
            tag: isset($data['tag']) && $data['tag'] !== '' ? (string) $data['tag'] : null,
            currencyCode: isset($data['currency_code']) && $data['currency_code'] !== ''
                ? strtoupper(substr((string) $data['currency_code'], 0, 3))
                : null,
            salespersonId: isset($data['salesperson_id']) && $data['salesperson_id'] !== ''
                ? (int) $data['salesperson_id']
                : null,
            channel: isset($data['channel']) && $data['channel'] !== ''
                ? strtolower((string) $data['channel'])
                : null,
            comparisonMode: $comparisonMode,
        );
    }

    /**
     * Issue #595: the same filter narrowed to another window, with every
     * comparison setting cleared so a derived report cannot recursively ask
     * for a comparison of its own.
     */
    public function forRange(Carbon $startDate, Carbon $endDate): self
    {
        $copy = clone $this;
        $copy->startDate = $startDate;
        $copy->endDate = $endDate;
        $copy->compareStartDate = null;
        $copy->compareEndDate = null;
        $copy->comparisonMode = null;

        return $copy;
    }

    /**
     * Get cache key for this filter
     */
    public function getCacheKey(string $reportType): string
    {
        $parts = [
            $reportType,
            $this->startDate?->format('Y-m-d'),
            $this->endDate?->format('Y-m-d'),
            $this->asOfDate?->format('Y-m-d'),
            $this->accountId,
            $this->accountType,
            $this->departmentId,
            $this->projectId,
            $this->locationId,
            $this->customerId,
            $this->vendorId,
            $this->productId,
            $this->categoryId,
            $this->interval,
            $this->legalEntityId,
            $this->entityBookId,
            $this->tag,
            $this->salespersonId,
            $this->channel,
        ];

        return md5(implode(':', array_filter($parts)));
    }

    /**
     * Validate date range
     */
    public function validateDateRange(int $maxDays = 365): bool
    {
        if (! $this->startDate || ! $this->endDate) {
            return true;
        }

        return $this->startDate->diffInDays($this->endDate) <= $maxDays;
    }
}
