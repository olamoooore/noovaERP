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
    ) {
    }

    /**
     * Create from request array
     */
    public static function fromRequest(array $data): self
    {
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
        );
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
        ];

        return md5(implode(':', array_filter($parts)));
    }

    /**
     * Validate date range
     */
    public function validateDateRange(int $maxDays = 365): bool
    {
        if (!$this->startDate || !$this->endDate) {
            return true;
        }

        return $this->startDate->diffInDays($this->endDate) <= $maxDays;
    }
}
