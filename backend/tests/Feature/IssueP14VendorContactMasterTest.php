<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Vendor;
use App\Services\VendorContactSyncService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue P1-4 — Vendor/Supplier identity alignment with the
 * canonical Contact Master.
 *
 * Verifies that creating a Vendor through the canonical path results in a
 * CoreContact row and a populated vendors.core_contact_uuid link.
 */
class IssueP14VendorContactMasterTest extends TenantTestCase
{
    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        // Authenticated user required by ContactMasterService for audit attribution
        $user = \App\Models\User::factory()->create();
        Auth::login($user);
    }

    public function test_ensure_organisation_contact_creates_core_contact_and_links_vendor(): void
    {
        $vendor = Vendor::create([
            'name' => 'Acme Supplies Ltd',
            'code' => 'ACME-'.uniqid(),
            'email' => 'ap@acme.example',
            'phone' => '+1-555-0100',
            'is_active' => true,
        ]);

        $this->assertNull($vendor->core_contact_uuid, 'New vendor should not have a core_contact_uuid yet');

        $contactUuid = app(VendorContactSyncService::class)->ensureOrganisationContact($vendor);

        $this->assertNotEmpty($contactUuid);

        $vendor->refresh();
        $this->assertNotNull($vendor->core_contact_uuid);
        $this->assertSame($contactUuid, $vendor->core_contact_uuid);

        // The CoreContact row exists
        $contact = CoreContact::where('id', $contactUuid)->first();
        $this->assertNotNull($contact);
        $this->assertSame('Acme Supplies Ltd', $contact->company_name);
        $this->assertSame('ap_vendor', $contact->source);
        $this->assertSame((string) $vendor->id, $contact->legacy_id);
    }

    public function test_ensure_organisation_contact_is_idempotent(): void
    {
        $vendor = Vendor::create([
            'name' => 'Repeat Vendor',
            'code' => 'REP-'.uniqid(),
            'is_active' => true,
        ]);

        $first = app(VendorContactSyncService::class)->ensureOrganisationContact($vendor);
        $second = app(VendorContactSyncService::class)->ensureOrganisationContact($vendor);

        $this->assertSame($first, $second);
        $this->assertSame(1, CoreContact::where('source', 'ap_vendor')
            ->where('legacy_id', (string) $vendor->id)
            ->count(), 'No duplicate CoreContact should be created');
    }

    public function test_backfill_unlinked_vendors_links_existing_vendors(): void
    {
        // Create vendors without core_contact_uuid (simulates existing data)
        $v1 = Vendor::create(['name' => 'Vendor 1', 'code' => 'V1-'.uniqid(), 'is_active' => true]);
        $v2 = Vendor::create(['name' => 'Vendor 2', 'code' => 'V2-'.uniqid(), 'is_active' => true]);

        $count = app(VendorContactSyncService::class)->backfillUnlinkedVendors();

        $this->assertGreaterThanOrEqual(2, $count);

        $v1->refresh();
        $v2->refresh();
        $this->assertNotNull($v1->core_contact_uuid);
        $this->assertNotNull($v2->core_contact_uuid);
    }

    public function test_vendor_model_has_core_contact_uuid_fillable(): void
    {
        $vendor = new Vendor();
        $this->assertContains('core_contact_uuid', $vendor->getFillable());
    }
}
