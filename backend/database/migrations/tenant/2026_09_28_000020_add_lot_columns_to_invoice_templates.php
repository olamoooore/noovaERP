<?php

use Illuminate\Database\Migrations\Migration;
use Modules\TemplateBuilder\Models\DocumentTemplate;

return new class extends Migration
{
    /**
     * Issue: the invoice (and pro-forma) PDF/preview never showed the selected
     * lot because their line-items table has no Lot/Batch column and the
     * InvoiceDataSource did not expose lot_number/expiry_date.
     *
     * This backfills the columns into every existing published invoice template
     * that binds a `lines` table, leaving templates that already carry a lot
     * column untouched. Idempotent: re-running is a no-op.
     */
    public function up(): void
    {
        $this->eachInvoiceTable(function (array &$table) {
            $fields = array_column($table['columns'] ?? [], 'field');

            if (in_array('lot_number', $fields, true)) {
                return false;
            }

            $descriptionIndex = array_search('description', $fields, true);
            $insertAt = $descriptionIndex === false ? null : $descriptionIndex + 1;

            $columns = $this->squeezeDescription($table['columns'] ?? []);
            $newColumns = [
                ['label' => 'Lot/Batch', 'field' => 'lot_number', 'width' => '10%'],
                ['label' => 'Expiry', 'field' => 'expiry_date', 'width' => '10%'],
            ];

            if ($insertAt === null) {
                $columns = array_merge($columns, $newColumns);
            } else {
                array_splice($columns, $insertAt, 0, $newColumns);
            }

            $table['columns'] = $columns;

            return true;
        });
    }

    public function down(): void
    {
        $this->eachInvoiceTable(function (array &$table) {
            $columns = $table['columns'] ?? [];
            $filtered = array_values(array_filter(
                $columns,
                fn ($column) => ! in_array($column['field'] ?? null, ['lot_number', 'expiry_date'], true)
            ));

            if (count($filtered) === count($columns)) {
                return false;
            }

            $table['columns'] = $filtered;

            return true;
        });
    }

    /**
     * Walk every published invoice template and hand each `lines` table block
     * to the callback; persist the template only when the callback changed it.
     */
    private function eachInvoiceTable(callable $mutate): void
    {
        if (! class_exists(DocumentTemplate::class)) {
            return;
        }

        DocumentTemplate::query()
            ->whereIn('document_type', ['invoice', 'proforma_invoice'])
            ->where('status', 'published')
            ->orderBy('id')
            ->chunkById(50, function ($templates) use ($mutate) {
                foreach ($templates as $template) {
                    $config = $template->layout_config;
                    if (! is_array($config)) {
                        continue;
                    }

                    $blocks = $config['blocks'] ?? [];
                    $changed = $this->walkBlocks($blocks, $mutate);
                    if ($changed) {
                        $config['blocks'] = $blocks;
                        $template->layout_config = $config;
                        $template->save();
                    }
                }
            });
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function walkBlocks(array &$blocks, callable $mutate): bool
    {
        $changed = false;

        foreach ($blocks as &$block) {
            if (($block['type'] ?? null) === 'table' && ($block['source'] ?? null) === 'lines') {
                $changed = $mutate($block) || $changed;
            }

            foreach (['children', 'blocks'] as $key) {
                if (isset($block[$key]) && is_array($block[$key])) {
                    $changed = $this->walkBlocks($block[$key], $mutate) || $changed;
                }
            }
        }

        return $changed;
    }

    /**
     * Reclaim horizontal space for the new columns from an oversized
     * Description column, so the table width stays ~100%.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array<string, mixed>>
     */
    private function squeezeDescription(array $columns): array
    {
        $freed = 0;

        foreach ($columns as &$column) {
            if (($column['field'] ?? null) !== 'description') {
                continue;
            }

            $width = (int) rtrim((string) ($column['width'] ?? ''), '%');
            if ($width > 30) {
                $newWidth = max(30, $width - 20);
                $freed = $width - $newWidth;
                $column['width'] = $newWidth.'%';
            }
        }

        // Not enough room taken from Description: shrink the widest money
        // columns instead so the added lot columns do not overflow.
        if ($freed < 20) {
            $order = ['unit_price', 'line_total'];
            foreach ($order as $field) {
                if ($freed >= 20) {
                    break;
                }

                foreach ($columns as &$column) {
                    if (($column['field'] ?? null) !== $field) {
                        continue;
                    }

                    $width = (int) rtrim((string) ($column['width'] ?? ''), '%');
                    if ($width <= 10) {
                        continue;
                    }

                    $reduce = min($width - 10, 20 - $freed);
                    $column['width'] = ($width - $reduce).'%';
                    $freed += $reduce;
                }
            }
        }

        return $columns;
    }
};
