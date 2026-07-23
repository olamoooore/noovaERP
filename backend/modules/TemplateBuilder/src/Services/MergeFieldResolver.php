<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

/**
 * MergeFieldResolver — resolves {{ token }} merge fields against a typed data
 * payload using dot-path lookup ONLY. No expression evaluation, no code exec.
 *
 * Supported syntax:  {{ path.to.field | formatter:arg | formatter2 }}
 * Formatters (whitelist): money, date, upper, lower, default, round, percent
 */
final class MergeFieldResolver
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var array<int,string> */
    private array $warnings = [];

    /** @var array<string, callable> */
    private array $formatters;

    public function __construct()
    {
        $this->formatters = [
            'money' => fn ($v, $arg, $data) => $this->formatMoney($v, $data),
            'date' => fn ($v, $arg) => $this->formatDate($v, $arg),
            'upper' => fn ($v) => mb_strtoupper((string) $v),
            'lower' => fn ($v) => mb_strtolower((string) $v),
            'default' => fn ($v, $arg) => ($v === null || $v === '') ? $arg : $v,
            'round' => fn ($v, $arg) => is_numeric($v) ? number_format((float) round((float) $v, (int) ($arg ?: 2)), (int) ($arg ?: 2), '.', '') : $v,
            'percent' => fn ($v) => is_numeric($v) ? number_format((float) $v, 2, '.', '').'%' : $v,
        ];
    }

    /** @param array<string,mixed> $data */
    public function with(array $data): self
    {
        $this->data = $data;
        $this->warnings = [];

        return $this;
    }

    /** @return array<int,string> */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * Apply a single named formatter (e.g. 'money', 'date:d/m/Y') to a value.
     * Used by block renderers for table cells that bind to a row field.
     */
    public function formatValue(mixed $value, ?string $formatter): mixed
    {
        if ($formatter === null || $formatter === '') {
            return $value;
        }
        [$name, $arg] = $this->splitFormatter($formatter);
        if (! isset($this->formatters[$name])) {
            $this->warnings[] = "Unknown formatter '{$name}'.";

            return $value;
        }

        return ($this->formatters[$name])($value, $arg, $this->data);
    }

    /**
     * Resolve every {{ token }} inside a free-text string.
     */
    public function resolveString(string $text): string
    {
        return preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/u', function (array $m) {
            return htmlspecialchars((string) $this->resolveToken($m[1]), ENT_QUOTES, 'UTF-8');
        }, $text) ?? $text;
    }

    /**
     * Resolve a single raw token expression (e.g. "invoice.total | money").
     * Returns scalar value (NOT html-escaped — callers decide context).
     */
    public function resolveToken(string $expression): mixed
    {
        $pipes = preg_split('/\s*\|\s*/u', trim($expression)) ?: [];
        $path = trim(array_shift($pipes) ?? '');

        $value = $this->lookup($path);

        foreach ($pipes as $pipe) {
            [$name, $arg] = $this->splitFormatter($pipe);
            if (! isset($this->formatters[$name])) {
                $this->warnings[] = "Unknown formatter '{$name}' in '{{ {$expression} }}'.";

                continue;
            }
            $value = ($this->formatters[$name])($value, $arg, $this->data);
        }

        return $value;
    }

    /** Dot-path lookup; null-safe; records warnings for missing paths. */
    private function lookup(string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $current = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                $this->warnings[] = "Unresolved merge field '{{ {$path} }}'.";

                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /** @return array{0:string,1:?string} */
    private function splitFormatter(string $pipe): array
    {
        if (str_contains($pipe, ':')) {
            [$name, $arg] = explode(':', $pipe, 2);

            return [trim($name), trim($arg)];
        }

        return [trim($pipe), null];
    }

    private function formatMoney(mixed $value, array $data): string
    {
        if (! is_numeric($value)) {
            return (string) ($value ?? '');
        }
        $symbol = $data['meta']['currency_symbol'] ?? '';
        $sep = ($data['meta']['number_format'] ?? '1,234.56');

        if (str_contains($sep, ',') && strpos($sep, ',') < strpos($sep, '.')) {
            $formatted = number_format((float) $value, 2, '.', ',');
        } else {
            $formatted = number_format((float) $value, 2, ',', '.');
        }

        return $symbol.$formatted;
    }

    private function formatDate(mixed $value, ?string $format): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $date = \Illuminate\Support\Carbon::parse($value);

            // Use explicit format, or fall back to tenant-configured format, or Y-m-d
            $resolvedFormat = $format ?: $this->getConfiguredDateFormat();

            return $date->format($resolvedFormat);
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    private function getConfiguredDateFormat(): string
    {
        try {
            return \App\Models\Setting::getValue('template_date_format', 'Y-m-d');
        } catch (\Throwable $e) {
            return 'Y-m-d';
        }
    }
}
