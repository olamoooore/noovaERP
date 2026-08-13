<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactImportBatch;
use Modules\Contact\src\Models\ContactImportStaging;
use Modules\Contact\src\Services\ContactMasterService;
use Modules\Contact\src\Support\ContactTaxonomyRegistry;
use Modules\Contact\src\Support\LegacyContactTypeMapping;

/**
 * ADR-003A Import Contract v1 — staged, validated Acme customer import.
 *
 * The flat CSV is a compatibility input, not the target domain model. Rows
 * are staged and validated before any authoritative write (CT-VEN-016):
 *   DRY_RUN  — unknown controlled values warn and stay non-commit-ready.
 *   COMMIT   — unknown controlled values reject the row until an approved
 *              mapping exists. Canonical taxonomy codes are never invented.
 *
 * Deduplication uses deterministic tiers and never auto-merges name-only
 * matches (CT-VEN-017). Customer codes keep their original VCxxxx value;
 * conflicts are reported, never silently renumbered (CT-VEN-011).
 */
class AcmeCustomerImportService
{
    /**
     * Staging row statuses.
     */
    public const STATUS_VALID = 'valid';          // commit-ready
    public const STATUS_WARNING = 'warning';      // unknown controlled value; not commit-ready (DRY_RUN)
    public const STATUS_DUPLICATE = 'duplicate';  // strong dedupe match; commit-ready as link/reuse
    public const STATUS_CANDIDATE = 'candidate';  // name-only candidate; not auto-merged, needs review
    public const STATUS_ERROR = 'error';          // hard validation failure
    public const STATUS_IMPORTED = 'imported';

    public function __construct(
        private readonly ContactMasterService $contacts,
        private readonly CustomerContactSyncService $sync,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Stage + validate rows (DRY_RUN). No authoritative writes occur.
     */
    public function stageBatch(
        array $rows,
        string $tenantId,
        int $userId,
        string $fileName,
        string $fileHash,
        ?array $mapping = null,
        ?int $legalEntityId = null,
        string $sourceSystem = 'acme_customer_master'
    ): array {
        $batch = ContactImportBatch::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'file_name' => $fileName,
            'file_hash' => $fileHash,
            'source_system' => $sourceSystem,
            'total_rows' => count($rows),
            'valid_rows' => 0,
            'error_rows' => 0,
            'duplicate_rows' => 0,
            'status' => 'validated',
            'mapping_config' => $mapping,
        ]);

        $counts = ['valid' => 0, 'warning' => 0, 'duplicate' => 0, 'candidate' => 0, 'error' => 0];
        $codeConflicts = [];
        $seenCodes = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // account for CSV header
            $mapped = $this->mapRow($row, $mapping);
            $original = $this->captureOriginalLegacyValues($row);

            $errors = $this->validateRow($mapped);
            $warnings = [];

            // Taxonomy resolution: unknown controlled values warn (DRY_RUN),
            // never invent canonical codes (CT-VEN-016).
            $taxonomy = $this->resolveContactTaxonomy($mapped, $warnings);

            // Customer-code uniqueness within (tenant, legal_entity, code).
            $code = $mapped['code'] ?? null;
            $status = self::STATUS_VALID;

            if (! $errors) {
                if ($code !== null) {
                    $lower = strtolower($code);
                    $existsLive = Customer::query()
                        ->whereRaw('LOWER(code) = ?', [$lower])
                        ->when($legalEntityId, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
                        ->exists();
                    $inBatch = isset($seenCodes[$lower]);
                    if ($existsLive || $inBatch) {
                        $codeConflicts[] = ['row' => $rowNumber, 'code' => $code];
                        // A conflicting code is a reportable conflict; the row
                        // is not commit-ready as a NEW customer.
                        $status = self::STATUS_ERROR;
                        $errors = true;
                    }
                    $seenCodes[$lower] = true;
                }

                if ($warnings && ! $errors) {
                    $status = self::STATUS_WARNING;
                } elseif (! $errors) {
                    // Deterministic person dedupe tiers (CT-VEN-017).
                    if (! empty($mapped['contact_name'])) {
                        $match = $this->sync->matchPersonIdentity(
                            $tenantId,
                            null,
                            $mapped['contact_name'],
                            $mapped['contact_email'] ?? null,
                            $mapped['contact_mobile'] ?? null,
                            $mapped['contact_phone'] ?? null
                        );
                        if ($match['tier'] === 1 || $match['tier'] === 2) {
                            $status = self::STATUS_DUPLICATE;
                        } elseif ($match['tier'] === 3) {
                            $status = self::STATUS_CANDIDATE;
                        }
                    }
                }
            } else {
                $status = self::STATUS_ERROR;
            }

            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            ContactImportStaging::create([
                'id' => Str::uuid(),
                'tenant_id' => $tenantId,
                'batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'source_record_key' => $code ?? ('row_'.$rowNumber),
                'source_payload_hash' => hash('sha256', json_encode($row)),
                'raw_data' => $row,
                'mapped_data' => $mapped + ['_taxonomy' => $taxonomy],
                'original_legacy_values' => $original,
                'status' => $status,
                'errors' => $errors ? $this->errorText($errors) : ($warnings ? implode('; ', $warnings) : null),
                'matched_contact_id' => null,
                'match_type' => $status,
            ]);
        }

        $batch->update([
            'valid_rows' => $counts['valid'] + $counts['duplicate'],
            'error_rows' => $counts['error'],
            'duplicate_rows' => $counts['duplicate'],
            'error_summary' => $codeConflicts ? ['code_conflicts' => $codeConflicts] : null,
        ]);

        $this->audit->log(
            operation: 'customer_import_staged',
            referenceType: 'import_batch',
            referenceId: 0,
            actorId: $userId,
            metadata: [
                'batch_id' => $batch->id,
                'total_rows' => $batch->total_rows,
                'valid_rows' => $counts['valid'] + $counts['duplicate'],
                'error_rows' => $counts['error'],
                'warning_rows' => $counts['warning'],
                'file_name' => $fileName,
                'source_system' => $sourceSystem,
            ]
        );

        return [
            'batch_id' => $batch->id,
            'total_rows' => $batch->total_rows,
            'counts' => $counts,
            'code_conflicts' => $codeConflicts,
            'status' => $batch->status,
        ];
    }

    /**
     * COMMIT: write only commit-ready rows. Rows carrying unknown controlled
     * values are rejected here (CT-VEN-016 COMMIT behaviour).
     */
    public function commitBatch(string $batchId, string $tenantId, ?int $legalEntityId = null): array
    {
        $batch = ContactImportBatch::where('tenant_id', $tenantId)->where('id', $batchId)->first();
        if (! $batch) {
            throw new \DomainException('IMPORT_BATCH_NOT_FOUND');
        }
        if ($batch->status === 'completed') {
            throw new \DomainException('IMPORT_BATCH_ALREADY_COMPLETED');
        }

        $rows = ContactImportStaging::where('batch_id', $batch->id)->orderBy('row_number')->get();

        $imported = 0;
        $rejected = 0;

        DB::transaction(function () use ($rows, $tenantId, $legalEntityId, &$imported, &$rejected, &$batch) {
            foreach ($rows as $staging) {
                if (in_array($staging->status, [self::STATUS_IMPORTED], true)) {
                    continue;
                }
                // Only commit-ready rows are written; warnings/candidates/errors
                // are rejected or held for review at COMMIT time.
                if (! in_array($staging->status, [self::STATUS_VALID, self::STATUS_DUPLICATE], true)) {
                    $rejected++;

                    continue;
                }

                $mapped = $staging->mapped_data;
                $taxonomy = $mapped['_taxonomy'] ?? [];

                // Reject at COMMIT if any controlled value remained unresolved.
                if (! empty($taxonomy['unknown'])) {
                    $staging->update(['status' => self::STATUS_ERROR, 'errors' => 'Unknown controlled value at COMMIT']);
                    $rejected++;

                    continue;
                }

                $this->writeAuthoritative($mapped, $taxonomy, $tenantId, $legalEntityId);

                $staging->update(['status' => self::STATUS_IMPORTED]);
                $imported++;
            }

            $batch->update(['status' => 'completed', 'imported_at' => now()]);
        });

        $this->audit->log(
            operation: 'customer_import_committed',
            referenceType: 'import_batch',
            referenceId: 0,
            metadata: [
                'batch_id' => $batch->id,
                'imported' => $imported,
                'rejected' => $rejected,
                'total_rows' => $batch->total_rows,
            ]
        );

        return ['imported' => $imported, 'rejected' => $rejected, 'batch_id' => $batch->id];
    }

    /**
     * Authoritative write for one commit-ready row:
     * customer account (CRM profile) + canonical ORGANISATION/PERSON contacts
     * + org-person relationship with functional category/classification/role.
     */
    private function writeAuthoritative(array $mapped, array $taxonomy, string $tenantId, ?int $legalEntityId): void
    {
        $code = $mapped['code'] ?? null;
        $name = $mapped['name'];

        $entityType = strtolower(trim((string) ($mapped['type'] ?? 'individual')));
        $isCompany = in_array($entityType, ['company', 'organisation', 'organization'], true);

        $customer = Customer::query()
            ->when($code, fn ($q) => $q->whereRaw('LOWER(code) = ?', [strtolower($code)]))
            ->when(! $code, fn ($q) => $q->whereRaw('LOWER(name) = ?', [strtolower($name)]))
            ->first();

        if (! $customer) {
            $customer = new Customer;
        }

        $customer->fill([
            'code' => $code ?: Customer::generateCode(),
            'name' => $name,
            'type' => $isCompany ? 'company' : 'individual',
            'email' => $mapped['email'] ?? null,
            'phone' => $mapped['phone'] ?? null,
            'mobile' => $mapped['mobile'] ?? null,
            'company' => $mapped['company'] ?? null,
            'tax_number' => $mapped['tax_number'] ?? null,
            'website' => $mapped['website'] ?? null,
            'billing_address' => $mapped['billing_address'] ?? $customer->billing_address,
            'shipping_address' => $mapped['shipping_address'] ?? $customer->shipping_address,
            'notes' => $mapped['notes'] ?? $customer->notes,
            'is_active' => true,
        ]);
        if ($legalEntityId) {
            $customer->legal_entity_id = $legalEntityId;
        }
        if (isset($mapped['credit_limit']) && $mapped['credit_limit'] !== '') {
            $customer->credit_limit = (float) $mapped['credit_limit'];
        }
        if (isset($mapped['payment_terms']) && $mapped['payment_terms'] !== '') {
            $customer->payment_terms = (int) $mapped['payment_terms'];
        }

        // ADR-003A CT-VEN-012/029: differing billing legal party is preserved
        // as CRM migration/compatibility data only — never used to create a
        // separate ORGANISATION or Accounting master under this ADR.
        $billingParty = array_filter([
            'billing_address' => $mapped['billing_address'] ?? null,
        ]);
        if ($billingParty) {
            $customer->billing_party = array_merge($customer->billing_party ?? [], $billingParty);
        }

        $customer->save();

        // Canonical identity link + relationship-scoped semantics.
        $contact = $this->sync->ensureOrganisationContact($customer);

        $contactName = trim((string) ($mapped['contact_name'] ?? ''));
        if ($contactName !== '') {
            $customerContact = \App\Models\CustomerContact::firstOrNew([
                'customer_id' => $customer->id,
                'name' => $contactName,
            ]);
            $customerContact->fill([
                'job_title' => $mapped['contact_job_title'] ?? null,
                'contact_category' => $taxonomy['functional_category_label'] ?? ($mapped['contact_category'] ?? null),
                'contact_type' => $mapped['contact_type'] ?? null,
                'email' => $mapped['contact_email'] ?? null,
                'phone' => $mapped['contact_phone'] ?? null,
                'mobile' => $mapped['contact_mobile'] ?? null,
                'street' => $mapped['contact_street'] ?? null,
                'street2' => $mapped['contact_street2'] ?? null,
                'city' => $mapped['contact_city'] ?? null,
                'state' => $mapped['contact_state'] ?? null,
                'zip' => $mapped['contact_zip'] ?? null,
                'country' => $mapped['contact_country'] ?? null,
            ]);
            $customerContact->save();

            $canonical = [
                'functional_category_code' => $taxonomy['functional_category'] ?? null,
                'classification_code' => $taxonomy['classification'] ?? null,
                'persistent_role_codes' => $taxonomy['persistent_role'] ? [$taxonomy['persistent_role']] : [],
                'department_name' => null,
            ];

            $this->sync->syncPersonForCustomerContact($customer, $customerContact, $canonical);

            if (! empty($taxonomy['is_primary'])) {
                $this->sync->setPrimaryContact($customer, $customerContact->id);
            }
        }
    }

    // ─── Mapping (ADR-003A §8) ────────────────────────────────────

    private function mapRow(array $row, ?array $mapping): array
    {
        if ($mapping) {
            $mapped = [];
            foreach ($mapping as $target => $sourceColumn) {
                $mapped[$target] = $row[$sourceColumn] ?? null;
            }

            return $mapped;
        }

        return [
            'code' => $this->str($row['code'] ?? $row['customer_code'] ?? null),
            'name' => $this->str($row['name'] ?? $row['customer_name'] ?? null),
            'type' => $this->str($row['type'] ?? null),
            'category' => $this->str($row['category'] ?? null),
            'contact_name' => $this->str($row['contact_name'] ?? null),
            'contact_job_title' => $this->str($row['contact_job_title'] ?? null),
            'contact_category' => $this->str($row['contact_category'] ?? null),
            'contact_type' => $this->str($row['contact_type'] ?? null),
            'contact_email' => $this->str($row['contact_email'] ?? null),
            'contact_phone' => $this->str($row['contact_phone'] ?? null),
            'contact_mobile' => $this->str($row['contact_mobile'] ?? null),
            'contact_street' => $this->str($row['contact_street'] ?? null),
            'contact_street2' => $this->str($row['contact_street2'] ?? null),
            'contact_city' => $this->str($row['contact_city'] ?? null),
            'contact_state' => $this->str($row['contact_state'] ?? null),
            'contact_zip' => $this->str($row['contact_zip'] ?? null),
            'contact_country' => $this->str($row['contact_country'] ?? null),
            'email' => $this->str($row['email'] ?? null),
            'phone' => $this->str($row['phone'] ?? null),
            'mobile' => $this->str($row['mobile'] ?? null),
            'company' => $this->str($row['company'] ?? null),
            'tax_number' => $this->str($row['tax_number'] ?? null),
            'website' => $this->str($row['website'] ?? null),
            'billing_address' => $this->str($row['billing_address'] ?? null),
            'shipping_address' => $this->str($row['shipping_address'] ?? null),
            'credit_limit' => $this->str($row['credit_limit'] ?? null),
            'payment_terms' => $this->str($row['payment_terms'] ?? null),
            'notes' => $this->str($row['notes'] ?? null),
        ];
    }

    /**
     * Preserve unresolved legacy values for reconciliation (CT-VEN-014/022).
     */
    private function captureOriginalLegacyValues(array $row): array
    {
        return array_filter([
            'category' => $this->str($row['category'] ?? null),
            'contact_category' => $this->str($row['contact_category'] ?? null),
            'contact_type' => $this->str($row['contact_type'] ?? null),
            'billing_address' => $this->str($row['billing_address'] ?? null),
            'type' => $this->str($row['type'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Resolve contact_category/contact_type into canonical taxonomy targets.
     * Unknown controlled values are flagged (warn in DRY_RUN, reject in COMMIT).
     */
    private function resolveContactTaxonomy(array $mapped, array &$warnings): array
    {
        $result = [
            'functional_category' => null,
            'functional_category_label' => null,
            'classification' => null,
            'persistent_role' => null,
            'is_primary' => false,
            'transaction_role' => null,
            'unknown' => [],
        ];

        $contactCategory = $mapped['contact_category'] ?? null;
        if ($contactCategory) {
            $code = LegacyContactTypeMapping::resolveFunctionalCategory($contactCategory);
            if ($code) {
                $result['functional_category'] = $code;
                $result['functional_category_label'] = ContactTaxonomyRegistry::FUNCTIONAL_CATEGORIES[$code];
            } else {
                $result['unknown'][] = 'contact_category: '.$contactCategory;
                $warnings[] = "Unknown contact_category '{$contactCategory}' — no canonical code created.";
            }
        }

        $contactType = $mapped['contact_type'] ?? null;
        if ($contactType) {
            $mappedType = LegacyContactTypeMapping::transformContactType($contactType);
            if ($mappedType['unknown']) {
                $result['unknown'][] = 'contact_type: '.$contactType;
                $warnings[] = "Unknown contact_type '{$contactType}' — no canonical code created.";
            } else {
                $result['is_primary'] = $mappedType['is_primary'];
                $result['classification'] = $mappedType['classification'];
                $result['persistent_role'] = $mappedType['persistent_role'];
                $result['transaction_role'] = $mappedType['transaction_role'];
                if ($result['functional_category'] === null && $mappedType['functional_category'] !== null) {
                    $result['functional_category'] = $mappedType['functional_category'];
                    $result['functional_category_label'] = ContactTaxonomyRegistry::FUNCTIONAL_CATEGORIES[$mappedType['functional_category']];
                }
            }
        }

        return $result;
    }

    private function validateRow(array $mapped)
    {
        if (empty($mapped['name'])) {
            return ['Missing required field: name'];
        }

        return null;
    }

    private function str($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function errorText($errors): string
    {
        return is_array($errors) ? implode('; ', $errors) : (string) $errors;
    }
}
