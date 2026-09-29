<?php

namespace App\Services;

use Modules\AI\Services\OllamaService;

/**
 * Tax advisory orchestration. The deterministic engine remains authoritative;
 * AI may explain and identify review questions, but cannot change rules or
 * post/file tax transactions.
 *
 * LLM output shape is never trusted: raw responses (prose, markdown sections,
 * fenced JSON, or any mix) are normalized into the structured advisory contract
 * {summary, risks[], actions[]} before reaching the UI, so the frontend renders
 * real components instead of pasted model text.
 */
class TaxAdvisoryService
{
    public function __construct(
        private readonly TaxEngineService $engine,
        private readonly OllamaService $ai,
    ) {}

    public function review(array $input): array
    {
        $jurisdiction = strtoupper($input['jurisdiction']);
        $direction = $input['direction'] ?? 'output';
        $resolved = $this->engine->resolve(null, $jurisdiction, $direction, $input['tax_type'] ?? null);
        $rate = array_key_exists('rate', $input) ? (float) $input['rate'] : $resolved['rate'];
        $inclusive = array_key_exists('inclusive', $input) ? (bool) $input['inclusive'] : $resolved['is_inclusive'];
        $calculation = $this->engine->calculate((float) $input['amount'], $rate, $inclusive);
        $warnings = [];

        if ($resolved['source'] === 'none') {
            $warnings[] = 'No applicable tax rule is configured for this jurisdiction and direction. Tax must not be charged until a rule is configured and reviewed.';
        }
        if ($jurisdiction === 'MY' && $direction === 'input') {
            $warnings[] = 'Malaysia SST is generally single-stage; input tax is not treated as a GST-style credit.';
        }

        return [
            'basis' => $resolved,
            'calculation' => $calculation,
            'warnings' => $warnings,
            'status' => $warnings ? 'review_required' : 'ready_for_review',
        ];
    }

    public function aiReview(array $input): array
    {
        $review = $this->review($input);
        $prompt = 'You are NoovaERP Tax Advisory. Review the deterministic tax engine result below. '
            .'Explain the result in plain language, identify missing evidence or configuration, and list '
            .'up to three accounting review actions. Never invent a rate, never tell the user to file, and '
            .'state that LHDN/RMCD or IRAS must be checked for current law. Respond with ONLY a JSON object '
            .'with keys summary (one short paragraph), risks (array of short strings), actions (array of '
            ."short strings). No prose before or after the JSON, no code fences, no markdown.\n\n"
            .json_encode([
                'request' => $input,
                'engine_review' => $review,
            ], JSON_THROW_ON_ERROR);

        try {
            $response = $this->ai->chat([
                ['role' => 'system', 'content' => 'You provide controlled tax review assistance. The engine result is authoritative. Reply with only a JSON object: {"summary":"...","risks":["..."],"actions":["..."]}'],
                ['role' => 'user', 'content' => $prompt],
            ]);

            return [
                'mode' => 'ai',
                'review' => $review,
                'advice' => self::parseAdvice((string) ($response['content'] ?? '')),
            ];
        } catch (\Throwable $exception) {
            return [
                'mode' => 'deterministic_fallback',
                'review' => $review,
                'advice' => [
                    'summary' => 'AI review is unavailable. Use the engine warnings below and confirm current LHDN/RMCD or IRAS guidance before posting or filing.',
                    'risks' => [],
                    'actions' => $review['warnings'],
                ],
            ];
        }
    }

    /**
     * Normalize any LLM response into the advisory contract. Order of attempts:
     * 1) embedded JSON object (with or without code fences), 2) markdown/plain
     * sections keyed on Summary / Risks / Actions, 3) whole text as summary.
     *
     * @return array{summary: string, risks: array<int,string>, actions: array<int,string>}
     */
    public static function parseAdvice(string $content): array
    {
        $content = trim($content);

        if ($content === '') {
            return ['summary' => 'The AI provider returned no advice.', 'risks' => [], 'actions' => []];
        }

        // 1) JSON anywhere in the response wins (models often append it after prose).
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

            if (is_array($decoded) && (array_key_exists('summary', $decoded) || array_key_exists('risks', $decoded) || array_key_exists('actions', $decoded))) {
                return [
                    'summary' => self::cleanInline((string) ($decoded['summary'] ?? '')),
                    'risks' => self::toStringList($decoded['risks'] ?? []),
                    'actions' => self::toStringList($decoded['actions'] ?? []),
                ];
            }
        }

        // 2) Section-based parsing (markdown **bold**, ##, or "Summary:" headings).
        $sections = ['summary' => [], 'risks' => [], 'actions' => []];
        $current = 'summary';
        $seenSection = false;

        foreach (preg_split('/\R/', $content) as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '```')) {
                continue;
            }

            if (preg_match('/^(?:#*\s*|\*\*)?\s*(summary|overview|risks?|actions?|recommendations?)\s*:?\s*(?:\*\*)?\s*[:—-]?\s*(.*)$/i', $line, $m) === 1
                && strlen(trim($m[1])) <= 20) {
                $heading = strtolower($m[1]);
                $current = str_starts_with($heading, 'risk') ? 'risks' : (str_starts_with($heading, 'action') || str_starts_with($heading, 'recommend') ? 'actions' : 'summary');
                $seenSection = true;

                if (trim($m[2]) !== '') {
                    $sections[$current][] = self::cleanInline($m[2]);
                }

                continue;
            }

            $sections[$current][] = self::cleanInline($line);
        }

        $summary = trim(implode(' ', $sections['summary']));

        if (! $seenSection && $summary === '') {
            $summary = self::cleanInline($content);
        }

        return [
            'summary' => $summary,
            'risks' => array_values(array_filter($sections['risks'], fn ($v) => $v !== '')),
            'actions' => array_values(array_filter($sections['actions'], fn ($v) => $v !== '')),
        ];
    }

    /**
     * @param  array<int|string, mixed>|string  $value
     * @return array<int, string>
     */
    private static function toStringList(array|string $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : [$value];
        }

        return array_values(array_filter(
            array_map(fn ($item) => self::cleanInline((string) $item), $value),
            fn ($item) => $item !== '',
        ));
    }

    private static function cleanInline(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/^(?:[-*•]|\d+[.)])\s+/', '', $line) ?? $line;
        $line = str_replace(['**', '`', '##'], '', $line);

        return trim($line);
    }
}
