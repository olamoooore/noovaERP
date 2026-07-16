<?php

namespace Modules\FinancialReporting\DTOs;

class ReportData
{
    public function __construct(
        public string $title,
        public array $data,
        public array $summary = [],
        public array $metadata = [],
        public ?array $comparison = null,
    ) {}

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'data' => $this->data,
            'summary' => $this->summary,
            'metadata' => $this->metadata,
            'comparison' => $this->comparison,
        ];
    }

    /**
     * Add metadata
     */
    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Add summary item
     */
    public function addSummary(string $key, mixed $value): self
    {
        $this->summary[$key] = $value;

        return $this;
    }
}
