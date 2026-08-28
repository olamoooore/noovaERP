<?php

namespace App\Services;

use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Services\ContactMasterService;

/**
 * ADR-005 §20.2 / ADR-003A: Vendor (AP) identity alignment with the canonical
 * Contact Master. Mirrors the customer-side pattern in CustomerContactSyncService.
 *
 * Each Vendor row is a CRM/AP vendor profile; its canonical identity is an
 * ORGANISATION core_contact (CT-VEN-003). This service ensures that link
 * exists and is kept in sync when vendor fields change.
 */
class VendorContactSyncService
{
    public function __construct(
        private readonly ContactMasterService $contacts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ensure the vendor has a canonical ORGANISATION contact and return its
     * core_contact_uuid. Idempotent — if the vendor already has a link,
     * return the existing UUID without creating a duplicate.
     */
    public function ensureOrganisationContact(Vendor $vendor): string
    {
        $tenantId = tenant()->id ?? null;

        if ($vendor->core_contact_uuid) {
            $existing = $this->contacts->find($vendor->core_contact_uuid, $tenantId);
            if ($existing) {
                return $existing->id;
            }
        }

        $contact = $this->contacts->create([
            'contact_type' => 'company',
            'company_name' => $vendor->name,
            'display_name' => $vendor->name,
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'website' => $vendor->website,
            'source' => 'ap_vendor',
            'legacy_id' => (string) $vendor->id,
        ], $tenantId);

        $vendor->forceFill(['core_contact_uuid' => $contact->id])->save();

        try {
            $this->audit->log(
                'vendor_contact_synced',
                'vendor',
                $vendor->id,
                Auth::id(),
                null,
                null,
                null,
                ['core_contact_uuid' => $contact->id],
            );
        } catch (\Throwable $e) {
            Log::warning('VendorContactSyncService audit log failed', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $contact->id;
    }

    /**
     * Backfill: link all existing vendors without a core_contact_uuid to
     * newly-created ORGANISATION contacts. Returns the count linked.
     */
    public function backfillUnlinkedVendors(): int
    {
        $count = 0;
        Vendor::whereNull('core_contact_uuid')
            ->whereNull('deleted_at')
            ->chunkById(100, function ($vendors) use (&$count) {
                foreach ($vendors as $vendor) {
                    try {
                        $this->ensureOrganisationContact($vendor);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('VendorContactSyncService::backfill failed for vendor', [
                            'vendor_id' => $vendor->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }
}
