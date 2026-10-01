<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Renderers;

use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Services\MergeFieldResolver;

/**
 * BlockRenderer — renders a structured `layout_config` block tree to HTML.
 *
 * This is the "visual mode" renderer: templates authored in the editor are a
 * JSON tree of typed blocks. No user code is executed; merge tokens are
 * resolved strictly via MergeFieldResolver (dot-path + whitelisted formatters).
 *
 * Output HTML targets the dompdf CSS subset (tables, inline styles, inline-block).
 */
final class BlockRenderer implements TemplateRendererInterface
{
    public function __construct(private readonly MergeFieldResolver $resolver) {}

    public function supports(string $mode): bool
    {
        return $mode === DocumentTemplate::MODE_BLOCK;
    }

    public function render(DocumentTemplate $template, array $data): array
    {
        $this->resolver->with($data);
        $meta = $template->metadata ?? [];
        $dateFormat = $meta['date_format'] ?? null;
        if ($dateFormat) {
            $this->resolver->setDateFormat($dateFormat);
        }

        $layout = is_array($template->layout_config) ? $template->layout_config : [];
        $blocks = $layout['blocks'] ?? [];

        $blocks = is_array($blocks) ? $blocks : [];
        $body = $this->renderBlocks($blocks, '');

        return [
            'html' => $body,
            'warnings' => $this->resolver->warnings(),
        ];
    }

    /** @param array<int,array<string,mixed>> $blocks */
    private function renderBlocks(array $blocks, string $context): string
    {
        if ($context === '') {
            $totalsIndex = array_key_last(array_filter($blocks, fn ($block) => ($block['type'] ?? null) === 'totals'));
            $footerIndex = array_key_last(array_filter($blocks, fn ($block) => ($block['type'] ?? null) === 'footer'));

            if ($totalsIndex !== null && $footerIndex !== null) {
                $totals = $blocks[$totalsIndex];
                $footer = $blocks[$footerIndex];
                $remaining = array_values(array_filter(
                    $blocks,
                    fn ($block, $index) => $index !== $totalsIndex && $index !== $footerIndex,
                    ARRAY_FILTER_USE_BOTH,
                ));
                $body = $this->renderBlocks($remaining, 'document');
                // Totals BEFORE footer in the DOM: natural flow (single-page
                // PDFs skip the pinned decoration band) then reads
                // content → totals → footer, which is the required invoice
                // order. Multi-page documents pin the footer to the page
                // bottom and move the totals above it via callbacks.
                $summary = $this->renderBlock($totals, 'document')."\n".$this->renderBlock($footer, 'document');

                // Keep footer + totals together so they can never split
                // across pages (report 2026-10-01).
                return $body."\n".'<div class="tb-final-summary" style="page-break-inside:avoid;">'.$summary.'</div>';
            }
        }

        return collect($blocks)
            ->filter(fn ($b) => $this->conditionPasses($b))
            ->map(fn ($b) => $this->renderBlock($b, $context))
            ->implode("\n");
    }

    /** @param array<string,mixed> $block */
    private function renderBlock(array $block, string $context): string
    {
        $type = $block['type'] ?? 'text';

        return match ($type) {
            'text' => $this->renderText($block),
            'image' => $this->renderImage($block),
            'columns' => $this->renderColumns($block, $context),
            'table' => $this->renderTable($block),
            'case_information' => $this->renderInformationTable($block),
            'delivery_detail' => $this->renderInformationTable($block),
            'quotation_detail' => $this->renderInformationTable($block),
            'totals' => $this->renderTotals($block),
            'spacer' => $this->renderSpacer($block),
            'divider' => $this->renderDivider(),
            'header' => $this->renderBlocks($this->children($block), 'header'),
            'footer' => '<div class="tb-document-footer">'.$this->renderBlocks($this->children($block), 'footer').'</div>',
            'conditional' => $this->renderBlocks($this->children($block), $context),
            default => '',
        };
    }

    /** @param array<string,mixed> $block */
    private function renderText(array $block): string
    {
        $content = (string) ($block['content'] ?? '');
        if ($content === '') {
            return '';
        }
        $html = $this->resolver->resolveString($content);

        $style = $this->textStyle($block);

        return "<div{$style}>{$html}</div>";
    }

    /** @param array<string,mixed> $block */
    private function renderImage(array $block): string
    {
        $url = $this->resolver->resolveString((string) ($block['url'] ?? ''));
        if ($url === '') {
            return '';
        }
        $width = (int) ($block['width'] ?? 0);
        $align = $block['align'] ?? 'left';
        $offsetX = (int) ($block['offset_x'] ?? 0);
        $w = $width > 0 ? " width=\"{$width}\"" : '';
        $style = " style=\"text-align:{$align};position:relative;left:{$offsetX}px\"";

        return "<div{$style}><img src=\"{$url}\"{$w} style=\"max-width:100%\"/></div>";
    }

    /** @param array<string,mixed> $block */
    private function renderColumns(array $block, string $context): string
    {
        $children = $this->children($block);
        if (empty($children)) {
            return '';
        }
        $gap = (int) ($block['gap'] ?? 8);

        $cells = '';
        foreach ($children as $child) {
            $inner = $this->renderBlock($child, $context);
            $align = $child['align'] ?? 'left';
            $valign = $child['valign'] ?? 'top';
            $cells .= "<td style=\"vertical-align:{$valign};text-align:{$align};padding:0 {$gap}px 0 0;\">{$inner}</td>";
        }

        return "<table style=\"width:100%;border-collapse:collapse;\"><tr>{$cells}</tr></table>";
    }

    /** @param array<string,mixed> $block */
    private function renderTable(array $block): string
    {
        $source = $block['source'] ?? 'lines';
        $rows = is_array($this->resolverValue("{$source}") ?? null) ? $this->resolverValue($source) : [];
        $columns = $block['columns'] ?? [];
        if (empty($columns) || ! is_array($columns)) {
            return '';
        }

        $head = '';
        foreach ($columns as $col) {
            $label = htmlspecialchars((string) ($col['label'] ?? ''), ENT_QUOTES);
            $w = isset($col['width']) ? " width=\"{$col['width']}\"" : '';
            $head .= "<th{$w} style=\"{$this->thStyle()}\">{$label}</th>";
        }

        $body = '';
        $subtotalField = (string) ($block['subtotal_field'] ?? 'line_total');
        $lastIndex = array_key_last($rows);
        foreach ($rows as $index => $row) {
            $lineType = (string) ($row['line_type'] ?? 'product');
            if (in_array($lineType, ['note', 'section'], true)) {
                $description = htmlspecialchars((string) ($row['description'] ?? ''), ENT_QUOTES);
                $textStyle = $lineType === 'section'
                    ? 'font-weight:700;background:#f3f4f6;color:#111827;'
                    : 'font-style:italic;background:#f9fafb;color:#6b7280;';
                if ($lineType === 'section' && count($columns) > 1) {
                    $sectionTotal = 0.0;
                    for ($nextIndex = $index + 1; $nextIndex < count($rows); $nextIndex++) {
                        $nextLineType = (string) ($rows[$nextIndex]['line_type'] ?? 'product');
                        if ($nextLineType === 'section') {
                            break;
                        }
                        if ($nextLineType === 'product' && is_numeric($rows[$nextIndex][$subtotalField] ?? null)) {
                            $sectionTotal += (float) $rows[$nextIndex][$subtotalField];
                        }
                    }
                    $formattedSectionTotal = htmlspecialchars(
                        (string) $this->resolver->formatValue($sectionTotal, (string) ($block['subtotal_format'] ?? 'money')),
                        ENT_QUOTES,
                    );
                    $body .= '<tr class="tb-line-row tb-line-row-section"><td colspan="'.(count($columns) - 1).'" style="'.$this->tdStyle([]).$textStyle.'">'.$description.'</td><td style="'.$this->tdStyle(['align' => 'right']).$textStyle.'text-align:right;white-space:nowrap;">'.$formattedSectionTotal.'</td></tr>';
                } else {
                    $body .= '<tr class="tb-line-row tb-line-row-'.$lineType.'"><td colspan="'.count($columns).'" style="'.$this->tdStyle([]).$textStyle.'">'.$description.'</td></tr>';
                }

                continue;
            }
            $amount = is_numeric($row[$subtotalField] ?? null) ? (float) $row[$subtotalField] : 0.0;
            $class = $index === $lastIndex ? 'tb-line-row tb-line-row-last' : 'tb-line-row';
            $body .= '<tr class="'.$class.'" data-tb-line-index="'.((int) $index).'" data-tb-line-amount="'.$amount.'">';
            foreach ($columns as $col) {
                $body .= '<td style="'.$this->tdStyle($col).'">'.$this->renderCell($col, $row).'</td>';
            }
            $body .= '</tr>';
        }

        return "<table class=\"tb-line-items\" style=\"width:100%;border-collapse:collapse;\"><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>";
    }

    /** @param array<string,mixed> $block */
    private function renderTotals(array $block): string
    {
        $rows = $block['rows'] ?? [];
        if (empty($rows) || ! is_array($rows)) {
            return '';
        }
        $out = '<table style="width:55%;border-collapse:collapse;margin-left:auto;">';
        foreach ($rows as $row) {
            $value = $this->resolver->resolveToken((string) ($row['field'] ?? ''));
            $value = $this->resolver->formatValue($value, $row['format'] ?? null);
            $value = htmlspecialchars((string) $value, ENT_QUOTES);
            $label = htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES);
            $bold = ! empty($row['bold']);
            $style = $bold
                ? 'font-weight:bold;border-top:2px solid #d1d5db;padding-top:6px;'
                : '';
            $out .= "<tr><td style=\"padding:5px 10px;text-align:left;{$style}\">{$label}</td>"
                ."<td style=\"padding:5px 10px;text-align:right;{$style}\">{$value}</td></tr>";
        }
        $out .= '</table>';

        return '<div class="tb-document-totals">'.$out.'</div>';
    }

    /** @param array<string,mixed> $block */
    private function renderInformationTable(array $block): string
    {
        $rows = is_array($block['rows'] ?? null) ? $block['rows'] : [];
        if ($rows === []) {
            return '';
        }

        $out = '<table class="tb-information-table" style="width:100%;border-collapse:collapse;page-break-inside:avoid;">';
        $out .= '<thead>';
        $out .= '<tr>';
        foreach ($rows as $row) {
            $label = htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES);
            $out .= '<th style="padding:6px 9px;border:1px solid #d1d5db;background:#f9fafb;text-align:left;font-weight:600;vertical-align:top;">'.$label.'</th>';
        }
        $out .= '</tr></thead><tbody><tr>';
        foreach ($rows as $row) {
            $value = $this->resolver->resolveToken((string) ($row['field'] ?? ''));
            $value = $this->resolver->formatValue($value, $row['format'] ?? null);
            $value = htmlspecialchars((string) $value, ENT_QUOTES);
            $out .= '<td style="padding:6px 9px;border:1px solid #d1d5db;text-align:left;vertical-align:top;">'.$value.'</td>';
        }
        $out .= '</tr></tbody></table>';

        return $out;
    }

    /** @param array<string,mixed> $block */
    private function renderSpacer(array $block): string
    {
        $h = (int) ($block['height'] ?? 10);

        return "<div style=\"height:{$h}px;line-height:{$h}px;font-size:1px;\">&nbsp;</div>";
    }

    private function renderDivider(): string
    {
        return '<hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;"/>';
    }

    /** @param array<string,mixed> $col */
    private function renderCell(array $col, array $row): string
    {
        $field = $col['field'] ?? '';
        $value = $field === 'index' ? ($row['index'] ?? '') : ($row[$field] ?? '');
        $value = $this->resolver->formatValue($value, $col['format'] ?? null);

        return htmlspecialchars((string) $value, ENT_QUOTES);
    }

    private function thStyle(): string
    {
        return 'padding:8px 10px;border-bottom:2px solid #111827;font-size:10px;text-transform:uppercase;color:#374151;text-align:left;background-color:#f9fafb;';
    }

    /** @param array<string,mixed> $col */
    private function tdStyle(array $col): string
    {
        $align = $col['align'] ?? 'left';

        return "padding:8px 10px;border-bottom:1px solid #e5e7eb;text-align:{$align};font-size:11px;";
    }

    /** @param array<string,mixed> $block */
    private function textStyle(array $block): string
    {
        $parts = [];
        if (isset($block['align'])) {
            $parts[] = 'text-align:'.$block['align'];
        }
        if (isset($block['size'])) {
            $parts[] = 'font-size:'.((int) $block['size']).'px';
        }
        if (isset($block['color'])) {
            $parts[] = 'color:'.$block['color'];
        }
        if (isset($block['bold']) && $block['bold']) {
            $parts[] = 'font-weight:bold';
        }

        return empty($parts) ? '' : ' style="'.implode(';', $parts).'"';
    }

    /** @param array<string,mixed> $block */
    private function children(array $block): array
    {
        return is_array($block['children'] ?? null) ? $block['children'] : [];
    }

    /** @param array<string,mixed> $block */
    private function conditionPasses(array $block): bool
    {
        $condition = $block['condition'] ?? null;
        if (! $condition) {
            return true;
        }

        return $this->evaluateCondition((string) $condition);
    }

    /**
     * Tiny safe expression evaluator for conditional blocks.
     * Accepts only:  {{ field }} <op> <literal>   where op ∈ ==,!=,>,>=,<,<=
     * No eval, no function calls, no arbitrary tokens.
     */
    private function evaluateCondition(string $condition): bool
    {
        if (! preg_match('/^\s*(\{\{[^}]+\}\}|[\w.\-]+)\s*(==|!=|>=|<=|>|<)\s*(.+?)\s*$/', $condition, $m)) {
            return true;
        }
        $leftExpr = trim($m[1]);
        $op = $m[2];
        $right = trim($m[3], " \t\n\r\0\"'");

        $left = str_starts_with($leftExpr, '{{')
            ? $this->resolver->resolveToken(trim($leftExpr, '{} '))
            : $this->resolverValue($leftExpr);

        $leftNum = is_numeric($left) ? (float) $left : null;
        $rightNum = is_numeric($right) ? (float) $right : null;

        if ($leftNum !== null && $rightNum !== null) {
            return match ($op) {
                '==' => $leftNum === $rightNum,
                '!=' => $leftNum !== $rightNum,
                '>' => $leftNum > $rightNum,
                '>=' => $leftNum >= $rightNum,
                '<' => $leftNum < $rightNum,
                '<=' => $leftNum <= $rightNum,
                default => true,
            };
        }

        $leftStr = (string) $left;

        return match ($op) {
            '==' => $leftStr === $right,
            '!=' => $leftStr !== $right,
            default => true,
        };
    }

    private function resolverValue(string $path): mixed
    {
        return $this->resolver->resolveToken($path);
    }
}
