<?php

namespace Modules\AccountingCountry\Services;

use Illuminate\Support\Facades\DB;

/**
 * #861 (RP7): shared plumbing for the SG audit-file and MY SST audit-listing
 * exports — company profile, journal-entry metadata, CSV rendering and the
 * written disclosures about what the platform does (and does not) record.
 *
 * One implementation so the two country exports cannot drift in their
 * evidence columns or lifecycle wording.
 */
class TaxAuditExportService
{
    /**
     * #860 (RP6): the single lifecycle scope every tax report shares.
     */
    public const LIFECYCLE_SCOPE = 'Originals kept (including reversed); signed reversal rows counted; only voided and superseded entries excluded (#860).';

    /**
     * IRAS publishes the Audit File (IAF) against a defined XML schema. That
     * schema is not bundled in this repository, so the export emits the IAF
     * listing concepts (company info, supply, purchase and GL listings) from
     * the data the platform actually records — no statutory column is
     * invented, and the payload says so.
     */
    public const IAF_SPEC_DISCLOSURE = 'Official IRAS IAF XML schema is not bundled in this repository. This is a data-driven audit listing (company, supply, purchase and GL listings) built from the posted tax trail, ledger and source-document references — not a spec-validated IRAS IAF XML file.';

    /**
     * The platform records exemption evidence on the invoice (exemption code
     * and reason) and on the contact tax profile (tax id, reason, expiry);
     * it has no dedicated exemption-certificate column, and the payload says
     * so instead of inventing one.
     */
    public const SST_SPEC_DISCLOSURE = 'This MY SST listing is data-driven from the posted tax trail and source-document references. The repository has no dedicated exemption-certificate column; exemption references shown are the fields the platform records (invoice tax_exemption_code, the exemption reason, and the contact tax profile tax_id).';

    /**
     * @return array{name: ?string, code: ?string, country_code: ?string, base_currency: ?string, registration_number: ?string, sst_registration_number: ?string}
     */
    public function company(?int $legalEntityId): array
    {
        $blank = [
            'name' => null,
            'code' => null,
            'country_code' => null,
            'base_currency' => null,
            'registration_number' => null,
            'sst_registration_number' => null,
        ];

        if ($legalEntityId === null) {
            return $blank;
        }

        $entity = DB::table('legal_entities')->where('id', $legalEntityId)->first();

        if ($entity === null) {
            return $blank;
        }

        return [
            'name' => $entity->name,
            'code' => $entity->code,
            'country_code' => $entity->country_code,
            'base_currency' => $entity->base_currency,
            'registration_number' => $entity->registration_number ?? null,
            'sst_registration_number' => $entity->sst_registration_number ?? null,
        ];
    }

    /**
     * Journal-entry evidence (date, number, description) keyed by entry id —
     * one query per export instead of one per trail row.
     *
     * @param  array<int, int|string|null>  $journalEntryIds
     * @return array<int, array{entry_number: ?string, entry_date: ?string, description: ?string}>
     */
    public function entryMeta(array $journalEntryIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $journalEntryIds),
            fn (int $id) => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $meta = [];

        $entries = DB::table('journal_entries')
            ->whereIn('id', $ids)
            ->get(['id', 'entry_number', 'entry_date', 'description']);

        foreach ($entries as $entry) {
            $meta[(int) $entry->id] = [
                'entry_number' => $entry->entry_number !== null ? (string) $entry->entry_number : null,
                'entry_date' => $entry->entry_date !== null ? (string) $entry->entry_date : null,
                'description' => $entry->description,
            ];
        }

        return $meta;
    }

    /**
     * Render a header/row set as CSV via fputcsv (correct quoting/escaping).
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header) => $row[$header] ?? '', $headers));
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content === false ? '' : $content;
    }
}
