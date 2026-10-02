<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\DocumentNumberingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;

/**
 * Issue #538 (ST1): one-off data check for document numbering integrity.
 *
 * Reports, per document type: duplicate numbers, gaps in a period's sequence,
 * numbers issued out of order, and numbers that do not conform to the
 * configured format. The old timestamp fallback (INV-YYYY-<mmss>) shows up as
 * a large sequence jump that fills a period with gaps.
 */
class CheckDocumentNumberingCommand extends Command
{
    protected $signature = 'documents:check-numbering
        {--tenant= : Tenant UUID to inspect (defaults to the current context)}
        {--type=all : invoice|quotation|sales_order|delivery_order|all}';

    protected $description = 'Report duplicate, gapped, out-of-sequence or non-conforming document numbers';

    private const TYPES = [
        'invoice' => ['table' => 'invoices', 'column' => 'invoice_number', 'format' => 'INV-{YYYY}-{SEQ:4}'],
        'quotation' => ['table' => 'quotations', 'column' => 'quotation_number', 'format' => 'QUO-{YYYY}-{SEQ:4}'],
        'sales_order' => ['table' => 'sales_orders', 'column' => 'sales_order_number', 'format' => 'SO-{YYYY}-{SEQ:4}'],
        'delivery_order' => ['table' => 'delivery_orders', 'column' => 'delivery_order_number', 'format' => 'DO-{YYYY}-{SEQ:4}'],
    ];

    public function handle(Tenancy $tenancy): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);

            if (! $tenant) {
                $this->error("Tenant {$tenantId} not found.");

                return self::FAILURE;
            }

            $tenancy->initialize($tenant);
        }

        try {
            $requested = (string) $this->option('type');
            $types = $requested === 'all' ? array_keys(self::TYPES) : [$requested];

            $problems = 0;

            foreach ($types as $type) {
                if (! isset(self::TYPES[$type])) {
                    $this->error("Unknown document type '{$type}'.");

                    return self::FAILURE;
                }

                $problems += $this->inspect($type, self::TYPES[$type]);
            }

            if ($problems === 0) {
                $this->info('No numbering problems found.');
            }

            return self::SUCCESS;
        } finally {
            if ($tenantId) {
                $tenancy->end();
            }
        }
    }

    /**
     * @param  array{table: string, column: string, format: string}  $config
     */
    private function inspect(string $type, array $config): int
    {
        $format = app(DocumentNumberingService::class)->getFormat($type, $config['format']);
        $pattern = $this->patternFor($format);

        $rows = DB::table($config['table'])
            ->whereNotNull($config['column'])
            ->orderBy('id')
            ->get(['id', $config['column'].' as number']);

        $problems = 0;

        foreach ($rows->groupBy('number') as $number => $group) {
            if ($group->count() > 1) {
                $this->warn("[{$type}] duplicate: {$number} appears {$group->count()} times");
                $problems++;
            }
        }

        $byPrefix = [];
        $runningMax = [];
        $outOfOrder = [];

        foreach ($rows as $row) {
            $number = (string) $row->number;

            if (! preg_match($pattern, $number, $matches, PREG_OFFSET_CAPTURE)) {
                $this->warn("[{$type}] non-conforming: {$number} does not match {$format}");
                $problems++;

                continue;
            }

            $prefix = substr($number, 0, $matches['seq'][1]);
            $sequence = (int) $matches['seq'][0];

            if (isset($runningMax[$prefix]) && $sequence < $runningMax[$prefix]) {
                $outOfOrder[] = $number;
            }

            $runningMax[$prefix] = max($runningMax[$prefix] ?? 0, $sequence);
            $byPrefix[$prefix][] = $sequence;
        }

        foreach ($outOfOrder as $number) {
            $this->warn("[{$type}] out-of-sequence: {$number} was issued after a higher number");
            $problems++;
        }

        foreach ($byPrefix as $prefix => $sequences) {
            sort($sequences);

            $missing = array_values(array_diff(range($sequences[0], max($sequences)), $sequences));

            if ($missing === []) {
                continue;
            }

            $preview = implode(', ', array_map(
                fn (int $seq): string => $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                array_slice($missing, 0, 10),
            ));

            $more = count($missing) > 10 ? ' … (+'.(count($missing) - 10).' more)' : '';

            $this->warn("[{$type}] gap after {$prefix}: missing {$preview}{$more}");
            $problems++;
        }

        $this->line(sprintf(
            '[%s] %d numbers, %d prefixes, %d problems',
            $type,
            $rows->count(),
            count($byPrefix),
            $problems,
        ));

        return $problems;
    }

    /**
     * A period-agnostic regex for the configured format: tokens match any
     * period and {SEQ:n} captures the sequence.
     */
    private function patternFor(string $format): string
    {
        if (! preg_match('/\{SEQ(?::\d+)?\}/', $format, $m, PREG_OFFSET_CAPTURE)) {
            return '/^'.$this->quoteWithTokens($format).'$/';
        }

        $before = $this->quoteWithTokens(substr($format, 0, $m[0][1]));
        $after = $this->quoteWithTokens(substr($format, $m[0][1] + strlen($m[0][0])));

        return '/^'.$before.'(?<seq>\d+)'.$after.'$/';
    }

    /**
     * preg_quote() escapes the token braces (and the SEQ colon), so the date
     * tokens are recognised in their quoted form after quoting. Handling SEQ
     * separately keeps its colon out of the replacement.
     */
    private function quoteWithTokens(string $literal): string
    {
        return str_replace(
            ['\\{YYYY\\}', '\\{YY\\}', '\\{MM\\}'],
            ['\d{4}', '\d{2}', '\d{2}'],
            preg_quote($literal, '/'),
        );
    }
}
