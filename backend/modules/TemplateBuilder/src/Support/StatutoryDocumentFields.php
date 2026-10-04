<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Support;

/**
 * Issue #614 (TP4): statutory invoice field labels and requiredness.
 *
 * The labels a document must print ("Tax Identification No. (TIN)" in MY,
 * "Unique Entity Number (UEN)" in SG) are country-pack DATA, not template
 * hardcoding: config/tax_packs.php<jurisdiction>.invoice_fields overrides the
 * generic wording in config/tax_geography.php.
 */
final class StatutoryDocumentFields
{
    /**
     * @return array<string, array{label:string, required:bool}>
     */
    public static function forJurisdiction(?string $jurisdiction): array
    {
        $generic = config('tax_geography.document_field_labels', []);
        $generic = is_array($generic) ? $generic : [];

        $jurisdiction = strtoupper(trim((string) $jurisdiction));
        $pack = $jurisdiction !== ''
            ? config('tax_packs.'.$jurisdiction.'.invoice_fields')
            : null;

        $pack = is_array($pack) ? $pack : [];

        return array_replace_recursive($generic, $pack);
    }
}
