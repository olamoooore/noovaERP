<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Support;

use App\Models\LegalEntity;
use Modules\TemplateBuilder\Models\DocumentTemplate;

/**
 * #627 (TP4 follow-up): statutory checker for template publishing.
 *
 * The required fields come from the country-pack DATA already used by #614
 * (`config/tax_packs.php` <jurisdiction>.invoice_fields, generic labels in
 * `config/tax_geography.php`) — this class invents no statutory content. A
 * jurisdiction without a pack, or the generic pack, requires nothing.
 */
final class StatutoryTemplateValidator
{
    /** Pack `invoice_fields` keys → the platform token that prints them (#614). */
    private const TOKEN_BY_FIELD = [
        'tin' => 'company.tin',
        'sst_number' => 'company.sst_number',
        'gst_number' => 'company.gst_number',
        'bank' => 'company.bank.account_number',
    ];

    /** Tax documents that must carry the pack's statutory fields. */
    private const TAX_DOCUMENT_TYPES = ['invoice', 'credit_note', 'debit_note'];

    /**
     * @return array<int, string> missing field labels ([] when compliant)
     */
    public function missing(DocumentTemplate $template): array
    {
        if (! in_array((string) $template->document_type, self::TAX_DOCUMENT_TYPES, true)) {
            return [];
        }

        $jurisdiction = $this->jurisdiction();

        if ($jurisdiction === null) {
            return [];
        }

        $required = collect(StatutoryDocumentFields::forJurisdiction($jurisdiction))
            ->filter(fn (array $field) => (bool) ($field['required'] ?? false));

        if ($required->isEmpty()) {
            // Generic pack / unused fields: publishing is never blocked.
            return [];
        }

        // Templates are stored as layout blocks and/or raw HTML/CSS — scan all
        // of them, ignoring whitespace variants of the {{ token }} syntax.
        $usage = preg_replace('/\s+/', '', implode(' ', array_filter([
            (string) $template->html,
            (string) $template->css,
            json_encode($template->layout_config) ?: '',
        ]))) ?? '';

        $missing = [];

        foreach ($required as $key => $field) {
            $token = self::TOKEN_BY_FIELD[$key] ?? null;

            if ($token === null) {
                // Pack declares a field the platform has no token for yet.
                continue;
            }

            if (! str_contains($usage, '{{'.$token.'}}')) {
                $missing[] = (string) ($field['label'] ?? $key);
            }
        }

        return $missing;
    }

    /**
     * The tenant's issuing country — the first legal entity (same source the
     * e-invoice supplier identity uses). #921: no fallback to undeclared
     * company_country / business_country keys — nothing writes them.
     */
    private function jurisdiction(): ?string
    {
        $country = LegalEntity::query()->orderBy('id')->value('country_code');

        $country = strtoupper(trim((string) $country));

        return $country !== '' ? $country : null;
    }
}
