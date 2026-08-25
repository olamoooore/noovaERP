<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Support\Facades\DB;

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
     * @param array{code?: string, name: string, email?: string, phone?: string, ...} $customerData
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
}
