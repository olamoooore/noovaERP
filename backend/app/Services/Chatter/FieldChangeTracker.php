<?php

declare(strict_types=1);

namespace App\Services\Chatter;

use App\Models\Customer;
use App\Models\PaymentTerm;
use Illuminate\Support\Str;

/**
 * Issue #634 (SALES-CHAT / UX1): turns before/after snapshots of a document
 * into the "field: old → new" list shown in the chatter History feed.
 *
 * The tracked fields are declared on the document model itself
 * (CHATTER_TRACKED_FIELDS for the header, CHATTER_TRACKED_LINE_FIELDS for the
 * lines) as `column => [label, type]`. The snapshots are the ones the update
 * paths already take for the append-only audit trail (#895) — this class
 * only compares and formats them; it stores nothing.
 *
 * Types: text, date, money, decimal, quantity, percent, customer,
 * payment_term.
 */
class FieldChangeTracker
{
    private const EMPTY = '—';

    /**
     * Chatter properties for one save: `changes` (header) and `line_changes`
     * (grouped per line). Keys are omitted when nothing tracked changed.
     *
     * @param  class-string  $modelClass
     * @param  array<string, mixed>  $beforeHeader
     * @param  array<string, mixed>  $afterHeader
     * @param  array<int|string, array<string, mixed>>  $beforeLines
     * @param  array<int|string, array<string, mixed>>  $afterLines
     * @return array{changes?: list<array<string, mixed>>, line_changes?: list<array<string, mixed>>}
     */
    public function track(
        string $modelClass,
        array $beforeHeader,
        array $afterHeader,
        array $beforeLines = [],
        array $afterLines = [],
    ): array {
        $headerFields = defined($modelClass.'::CHATTER_TRACKED_FIELDS')
            ? constant($modelClass.'::CHATTER_TRACKED_FIELDS')
            : [];
        $lineFields = defined($modelClass.'::CHATTER_TRACKED_LINE_FIELDS')
            ? constant($modelClass.'::CHATTER_TRACKED_LINE_FIELDS')
            : [];

        $out = [];

        $changes = $this->diff($headerFields, $beforeHeader, $afterHeader);
        if ($changes !== []) {
            $out['changes'] = $changes;
        }

        $lineChanges = $this->diffLines($lineFields, $beforeLines, $afterLines);
        if ($lineChanges !== []) {
            $out['line_changes'] = $lineChanges;
        }

        return $out;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $fields
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    public function diff(array $fields, array $before, array $after): array
    {
        $changes = [];

        foreach ($fields as $field => [$label, $type]) {
            // A column absent from either snapshot was not part of the save.
            if (! array_key_exists($field, $before) || ! array_key_exists($field, $after)) {
                continue;
            }

            $old = $this->normalize($before[$field], $type);
            $new = $this->normalize($after[$field], $type);

            if ($old === $new) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'old' => $this->display($old, $type),
                'new' => $this->display($new, $type),
            ];
        }

        return $changes;
    }

    /**
     * Line changes grouped per line. Lines that keep their id (confirmed
     * order amendment) are matched by id; a draft edit replaces every row, so
     * there the n-th line before is compared with the n-th line after.
     *
     * @param  array<string, array{0: string, 1: string}>  $fields
     * @param  array<int|string, array<string, mixed>>  $before
     * @param  array<int|string, array<string, mixed>>  $after
     * @return list<array<string, mixed>>
     */
    public function diffLines(array $fields, array $before, array $after): array
    {
        if ($fields === []) {
            return [];
        }

        $before = $this->sortById($before);
        $after = $this->sortById($after);

        $beforeIds = array_filter(array_map(fn ($l) => $l['id'] ?? null, $before));
        $afterIds = array_filter(array_map(fn ($l) => $l['id'] ?? null, $after));
        $matchById = array_intersect($beforeIds, $afterIds) !== [];

        $pairs = [];
        if ($matchById) {
            $beforeById = [];
            foreach ($before as $position => $line) {
                $beforeById[(int) $line['id']] = [$position, $line];
            }
            foreach ($after as $position => $line) {
                $id = (int) ($line['id'] ?? 0);
                $pairs[] = [$position + 1, $beforeById[$id][1] ?? null, $line];
                unset($beforeById[$id]);
            }
            foreach ($beforeById as [$position, $line]) {
                $pairs[] = [$position + 1, $line, null];
            }
        } else {
            $count = max(count($before), count($after));
            for ($i = 0; $i < $count; $i++) {
                $pairs[] = [$i + 1, $before[$i] ?? null, $after[$i] ?? null];
            }
        }

        $out = [];
        foreach ($pairs as [$number, $old, $new]) {
            $entry = $this->lineEntry($fields, $number, $old, $new);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        usort($out, fn ($a, $b) => $a['line'] <=> $b['line']);

        return $out;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $fields
     */
    private function lineEntry(array $fields, int $number, ?array $old, ?array $new): ?array
    {
        if ($old !== null && $new !== null) {
            $changes = $this->diff($fields, $old, $new);
            if ($changes === []) {
                return null;
            }

            return [
                'line' => $number,
                'action' => 'changed',
                'description' => $this->lineDescription($new),
                'changes' => $changes,
                'summary' => "Line {$number}: ".implode(', ', array_map(
                    fn ($c) => "{$c['label']} {$c['old']} → {$c['new']}",
                    $changes,
                )),
            ];
        }

        $line = $new ?? $old;
        $action = $new !== null ? 'added' : 'removed';
        $description = $this->lineDescription($line);

        $values = [];
        if ($action === 'added') {
            foreach ($fields as $field => [$label, $type]) {
                if ($field === 'description' || ! array_key_exists($field, $line)) {
                    continue;
                }
                $value = $this->normalize($line[$field], $type);
                if ($value === null || $value === 0.0) {
                    continue;
                }
                $values[] = "{$label} ".$this->display($value, $type);
            }
        }

        return [
            'line' => $number,
            'action' => $action,
            'description' => $description,
            'changes' => [],
            'summary' => "Line {$number} {$action}".($description !== '' ? ": {$description}" : '')
                .($values !== [] ? ' ('.implode(', ', $values).')' : ''),
        ];
    }

    private function lineDescription(array $line): string
    {
        return Str::limit(trim((string) ($line['description'] ?? '')), 80);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function sortById(array $lines): array
    {
        $lines = array_values($lines);
        usort($lines, fn ($a, $b) => ((int) ($a['id'] ?? PHP_INT_MAX)) <=> ((int) ($b['id'] ?? PHP_INT_MAX)));

        return $lines;
    }

    /**
     * Comparable form of a value: equal business values compare equal
     * whatever their storage form ("120.0000" vs 120, a Carbon vs a date
     * string, null vs "").
     */
    private function normalize(mixed $value, string $type): string|float|int|null
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($type) {
            'date' => $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d')
                : substr((string) $value, 0, 10),
            'money', 'decimal', 'quantity', 'percent' => is_numeric($value) ? round((float) $value, 4) : null,
            'customer', 'payment_term' => is_numeric($value) ? (int) $value : null,
            default => is_scalar($value) ? trim((string) $value) : json_encode($value),
        };
    }

    private function display(string|float|int|null $value, string $type): string
    {
        if ($value === null) {
            return self::EMPTY;
        }

        return match ($type) {
            'money' => number_format((float) $value, 2),
            'decimal', 'quantity' => $this->trimNumber((float) $value),
            'percent' => $this->trimNumber((float) $value).'%',
            'customer' => (string) (Customer::query()->find($value)?->name ?? "#{$value}"),
            'payment_term' => (string) (PaymentTerm::query()->find($value)?->name ?? "#{$value}"),
            default => Str::limit((string) $value, 120),
        };
    }

    private function trimNumber(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
