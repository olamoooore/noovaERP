<?php

namespace Modules\FinancialReporting\DTOs;

class ChartData
{
    public function __construct(
        public string $type, // line, bar, pie, area, gauge
        public array $labels,
        public array $datasets,
        public array $options = [],
    ) {
    }

    /**
     * Create line chart data
     */
    public static function line(array $labels, array $datasets, array $options = []): self
    {
        return new self('line', $labels, $datasets, $options);
    }

    /**
     * Create bar chart data
     */
    public static function bar(array $labels, array $datasets, array $options = []): self
    {
        return new self('bar', $labels, $datasets, $options);
    }

    /**
     * Create pie chart data
     */
    public static function pie(array $labels, array $data, array $options = []): self
    {
        return new self('pie', $labels, [
            [
                'label' => 'Distribution',
                'data' => $data,
            ]
        ], $options);
    }

    /**
     * Create area chart data
     */
    public static function area(array $labels, array $datasets, array $options = []): self
    {
        return new self('area', $labels, $datasets, $options);
    }

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'labels' => $this->labels,
            'datasets' => $this->datasets,
            'options' => $this->options,
        ];
    }
}
