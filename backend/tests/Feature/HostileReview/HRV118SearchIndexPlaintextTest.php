<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Services\ContactMasterService;
use Tests\TenantTestCase;

/**
 * Hostile review repro (#118): an encrypted tax identifier must not be copied
 * in plaintext into contact_search_index.
 */
class HRV118SearchIndexPlaintextTest extends TenantTestCase
{
    public function test_tax_id_is_not_plaintext_in_search_index(): void
    {
        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);
        $service = app(ContactMasterService::class);

        $contactId = (string) Str::uuid();
        DB::table('core_contacts')->insert([
            'id' => $contactId, 'tenant_id' => $tenantId, 'display_name' => 'HRV118 Pte Ltd',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service->addIdentifier($contactId, 'tax_id', 'UEN-201912345K', $tenantId, ['is_primary' => true]);
        $service->rebuildSearchIndex(CoreContact::findOrFail($contactId));

        $stored = DB::table('contact_identifiers')->where('contact_id', $contactId)->value('identifier_value');
        $index = DB::table('contact_search_index')->where('contact_id', $contactId)->first(['identifiers_text', 'primary_identifier']);

        fwrite(STDERR, sprintf("\n[HRV118] contact_identifiers.identifier_value(raw)=%s...\n[HRV118] contact_search_index.identifiers_text=%s primary_identifier=%s\n",
            substr((string) $stored, 0, 24), $index->identifiers_text ?? 'null', $index->primary_identifier ?? 'null'));

        $this->assertStringNotContainsString('201912345K', (string) $stored, 'identifier is encrypted at rest');
        $this->assertStringNotContainsString('201912345K', (string) $index->identifiers_text, 'search index must not hold the plaintext tax ID');
    }
}
