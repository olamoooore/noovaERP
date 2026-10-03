<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\ContactDuplicateCandidate;

/**
 * Single canonical customer-creation service.
 *
 * All production customer creation MUST go through this service.
 * Customer + Contact Master organisation contact are created in the
 * same database transaction. If contact creation fails, the entire
 * transaction rolls back — no partial state is persisted.
 *
 * Allowed exceptions:
 * - Factory / test setup (direct Customer::create)
 * - Migrations / seeders
 * - Approved compatibility code (walk-in Customer::firstOrCreate with immediate sync)
 */
class CanonicalCustomerService
{
    private DocumentSnapshotService $snapshotService;

    private CustomerContactSyncService $syncService;

    public function __construct(
        DocumentSnapshotService $snapshotService,
        CustomerContactSyncService $syncService
    ) {
        $this->snapshotService = $snapshotService;
        $this->syncService = $syncService;
    }

    /**
     * Create a customer with their canonical organisation contact.
     *
     * Both are created in the same database transaction.
     * If either fails, the entire operation rolls back.
     *
     * @param  array{code?: string, name: string, email?: string, phone?: string, ...}  $customerData
     * @return Customer The created customer with core_contact_uuid set
     *
     * @throws \Exception If customer or contact creation fails
     */
    public function create(array $customerData): Customer
    {
        return DB::transaction(function () use ($customerData) {
            $customer = Customer::create($customerData);

            $this->syncService->ensureOrganisationContact($customer);

            return $customer->fresh();
        });
    }

    /**
     * Get or create a walk-in customer with canonical identity.
     *
     * Uses firstOrCreate for idempotency, then ensures organisation contact.
     */
    public function getOrCreateWalkIn(): Customer
    {
        return DB::transaction(function () {
            $customer = Customer::firstOrCreate(
                ['email' => 'walkin@system.local'],
                [
                    'name' => 'Walk-in Customer',
                    'phone' => null,
                    'address' => null,
                    'notes' => 'System customer for walk-in POS transactions',
                ]
            );

            $this->syncService->ensureOrganisationContact($customer);

            return $customer->fresh();
        });
    }

    /**
     * Get-or-create a customer by name, with canonical identity (#51 import flow).
     *
     * Idempotent: if a customer with the given name exists, returns the
     * existing row; otherwise creates a new one with the canonical Contact
     * Master link. Used by AccountingImportService so production customer
     * creation never bypasses this canonical service.
     */
    public function getOrCreateByName(string $name, array $additionalAttributes = []): Customer
    {
        return DB::transaction(function () use ($name, $additionalAttributes) {
            $customer = Customer::firstOrCreate(
                ['name' => $name],
                array_merge([
                    'name' => $name,
                    'phone' => null,
                    'address' => null,
                    'notes' => null,
                ], $additionalAttributes)
            );

            $this->syncService->ensureOrganisationContact($customer);

            return $customer->fresh();
        });
    }

    // ─── Issue #431 (CU3): import resolution without silent name merges ──

    /**
     * Resolve the customer for an accounting-import row.
     *
     * Strong key first (customer_code; customer_id is handled by the caller).
     * A name-only path only ever matches ONE existing customer or creates a
     * new one — several customers sharing the name are never collapsed into
     * the first match: that raises CustomerAmbiguousForImportException after
     * recording duplicate candidates for review (ADR-003A VEN-AT-04).
     */
    public function resolveForImport(array $data): Customer
    {
        $code = trim((string) ($data['customer_code'] ?? ''));

        if ($code !== '') {
            $byCode = Customer::where('code', $code)->get();

            if ($byCode->count() === 1) {
                $customer = $byCode->first();
                $this->syncService->ensureOrganisationContact($customer);

                return $customer->fresh();
            }

            if ($byCode->count() > 1) {
                throw $this->ambiguousImportCustomer($code, $byCode, ['matched_by' => 'customer_code']);
            }

            // A strong key that does not exist is an error — never fall back
            // to a name match the code disagrees with.
            throw new \DomainException("Customer code '{$code}' was not found. Provide a known customer_code, or customer_id / customer_name.");
        }

        $name = trim((string) ($data['customer_name'] ?? ''));
        if ($name === '') {
            throw new \DomainException('Cannot resolve customer for import row: customer_id, customer_code or customer_name is required.');
        }

        $matches = Customer::where('name', $name)->get();
        if ($matches->count() > 1) {
            throw $this->ambiguousImportCustomer($name, $matches, ['matched_by' => 'name']);
        }

        return $this->getOrCreateByName($name, $code !== '' ? ['code' => $code] : []);
    }

    /**
     * Dry-run (staging) side of resolveForImport: read-only check that returns
     * the row's error message when the row cannot be resolved safely, and
     * records duplicate candidates when the name (or code) is ambiguous.
     * Returns null when the row resolves cleanly or will be created new.
     */
    public function flagCustomerResolutionIssues(array $data, array $context = []): ?string
    {
        if (! empty($data['customer_id'])) {
            return null;
        }

        $code = trim((string) ($data['customer_code'] ?? ''));

        if ($code !== '') {
            $byCode = Customer::where('code', $code)->get();

            if ($byCode->count() === 1) {
                return null;
            }

            if ($byCode->count() > 1) {
                $name = trim((string) ($data['customer_name'] ?? '')) ?: $code;

                return $this->ambiguousImportCustomer($name, $byCode, ['matched_by' => 'customer_code'] + $context)->getMessage();
            }

            // A strong key that does not exist is an error at staging time
            // too — resolveForImport() refuses the same row at commit time,
            // so staging must not report it as importable.
            return "Customer code '{$code}' was not found. Provide a known customer_code, or customer_id / customer_name.";
        }

        $name = trim((string) ($data['customer_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $matches = Customer::where('name', $name)->get();
        if ($matches->count() > 1) {
            return $this->ambiguousImportCustomer($name, $matches, ['matched_by' => 'name'] + $context)->getMessage();
        }

        return null;
    }

    /**
     * Record pending contact-duplicate candidates for every pair of colliding
     * same-name customers, so a human review queue exists instead of a silent
     * merge. No-op when fewer than two of the customers have organisation
     * contacts (nothing to pair).
     *
     * @param  list<Customer>  $customers
     */
    public function flagDuplicateCandidates(string $name, array $customers, array $context = []): void
    {
        $contacts = array_values(array_unique(array_filter(array_map(
            fn (Customer $customer) => $customer->core_contact_uuid,
            $customers,
        ))));

        if (count($contacts) < 2) {
            return;
        }

        sort($contacts);
        $customerIds = array_map(fn (Customer $customer) => (string) $customer->id, $customers);
        $customerCodes = array_map(fn (Customer $customer) => $customer->code, $customers);

        foreach ($contacts as $i => $contactId1) {
            foreach (array_slice($contacts, $i + 1) as $contactId2) {
                [$first, $second] = $contactId1 < $contactId2 ? [$contactId1, $contactId2] : [$contactId2, $contactId1];

                ContactDuplicateCandidate::firstOrCreate(
                    [
                        'tenant_id' => tenant()->id,
                        'contact_id_1' => $first,
                        'contact_id_2' => $second,
                        'match_type' => 'exact_name',
                        'status' => 'pending',
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'match_score' => 100,
                        'match_details' => array_merge([
                            'source' => 'accounting_import',
                            'customer_name' => $name,
                            'customer_ids' => $customerIds,
                            'customer_codes' => $customerCodes,
                        ], $context),
                    ]
                );
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Customer>  $matches
     */
    private function ambiguousImportCustomer(string $name, $matches, array $context = []): CustomerAmbiguousForImportException
    {
        $customers = $matches->all();
        $this->flagDuplicateCandidates($name, $customers, $context);

        return new CustomerAmbiguousForImportException($name, array_map(
            fn (Customer $customer) => ['id' => (string) $customer->id, 'code' => $customer->code],
            $customers,
        ));
    }
}
