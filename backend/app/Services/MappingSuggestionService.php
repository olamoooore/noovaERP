<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Auto-suggest entity-to-master-COA mappings based on code patterns, names, and types.
 * Confidence scoring: HIGH (≥0.8) auto-approved, MEDIUM (0.5-0.79) suggested, LOW (<0.5) manual.
 * Control accounts always require manual approval regardless of confidence.
 */
class MappingSuggestionService
{
    private const CONTROL_ROLES = [
        'AR_CONTROL', 'AP_CONTROL', 'CASH_MAIN', 'BANK_MAIN',
        'RETAINED_EARNINGS', 'SUSPENSE',
    ];

    /**
     * Suggest master COA mappings for a list of imported accounts.
     *
     * @param array $importedAccounts Each: ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset']
     * @return array Each: ['imported' => [...], 'suggestion' => [...], 'confidence' => float, 'requires_approval' => bool]
     */
    public function suggestMappings(array $importedAccounts): array
    {
        $masterNodes = DB::table('master_coa_nodes')->get();
        $results = [];

        foreach ($importedAccounts as $imported) {
            $bestMatch = null;
            $bestScore = 0.0;

            foreach ($masterNodes as $node) {
                $score = $this->calculateScore($imported, $node);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $node;
                }
            }

            $requiresApproval = $this->requiresManualApproval($imported, $bestScore);

            $results[] = [
                'imported'          => $imported,
                'suggestion'        => $bestMatch ? [
                    'master_node_id' => $bestMatch->id,
                    'code'           => $bestMatch->code,
                    'name'           => $bestMatch->name,
                ] : null,
                'confidence'        => round($bestScore, 2),
                'confidence_level'  => $this->confidenceLevel($bestScore),
                'requires_approval' => $requiresApproval,
            ];
        }

        return $results;
    }

    /**
     * Calculate match score between imported account and master node.
     */
    private function calculateScore(array $imported, object $masterNode): float
    {
        $score = 0.0;

        // Code prefix match (weight: 0.4)
        if (isset($imported['code']) && isset($masterNode->code)) {
            $importedCode = ltrim($imported['code'], '0');
            $masterCode   = ltrim($masterNode->code, '0');

            if ($importedCode === $masterCode) {
                $score += 0.4;
            } elseif (str_starts_with($importedCode, substr($masterCode, 0, 2))) {
                $score += 0.25;
            } elseif (str_starts_with($importedCode, substr($masterCode, 0, 1))) {
                $score += 0.1;
            }
        }

        // Name similarity (weight: 0.35)
        if (isset($imported['name']) && isset($masterNode->name)) {
            $importedName = strtolower(trim($imported['name']));
            $masterName   = strtolower(trim($masterNode->name));

            similar_text($importedName, $masterName, $pct);
            $score += ($pct / 100) * 0.35;
        }

        // Type match (weight: 0.25)
        if (isset($imported['type']) && isset($masterNode->type)) {
            if (strtolower($imported['type']) === strtolower($masterNode->type)) {
                $score += 0.25;
            }
        }

        return min($score, 1.0);
    }

    /**
     * Determine if manual approval is required.
     */
    private function requiresManualApproval(array $imported, float $confidence): bool
    {
        // Low confidence always requires approval
        if ($confidence < 0.5) {
            return true;
        }

        // Check if this maps to a control account role
        $code = $imported['code'] ?? '';
        $name = strtolower($imported['name'] ?? '');

        $controlPatterns = [
            'receivable', 'payable', 'cash', 'bank',
            'retained', 'suspense', 'control',
        ];

        foreach ($controlPatterns as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function confidenceLevel(float $score): string
    {
        if ($score >= 0.8) return 'high';
        if ($score >= 0.5) return 'medium';
        return 'low';
    }
}
