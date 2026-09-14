<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FinancialClose\Http\Resources\FindingResource;
use Modules\FinancialClose\Models\AiFinding;
use Tests\TenantTestCase;

/**
 * FindingResource must surface the real journal entry number (JE-YYYY-NNNN)
 * and a deep-link to the exact entry — for BOTH newly generated and
 * previously-stored findings whose structured_output still carries the old
 * opaque "Journal Entry #<id>" label / list route.
 */
class AiFindingJournalReferenceTest extends TenantTestCase
{
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'T', 'email' => $this->tenantId.'@e.test',
            'plan' => 'trial', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);
    }

    public function test_journal_ref_label_uses_real_entry_number_and_deep_links(): void
    {
        $user = User::factory()->create();
        $je = JournalEntry::create([
            'entry_number' => 'JE-2026-0238',
            'entry_date' => '2026-02-01',
            'description' => 'Ref test',
            'status' => 'posted',
            'created_by' => $user->id,
            'posted_by' => $user->id,
        ]);

        // Simulate a previously-stored finding with the OLD opaque label/route.
        $finding = new AiFinding([
            'tenant_id' => $this->tenantId,
            'entity_id' => 'e1',
            'account_code' => '1000',
            'finding_type' => 'variance',
            'status' => 'open',
            'risk_status' => 'high',
            'confidence' => 0.9,
            'description' => 'test',
            'structured_output' => [
                'source_refs' => [
                    [
                        'type' => 'journal_entry',
                        'id' => (string) $je->id,
                        'label' => 'Journal Entry #'.$je->id,
                        'route' => '/accounting/core/journal-entries',
                        'meta' => ['line_id' => '1207'],
                    ],
                ],
            ],
        ]);

        $resource = (new FindingResource($finding))->toArray(app(\Illuminate\Http\Request::class));
        $ref = $resource['source_refs'][0];

        $this->assertSame('JE-2026-0238', $ref['label'],
            'Journal ref must show the real entry number, not the opaque id');
        $this->assertStringContainsString('entry_id='.$je->id, $ref['route'],
            'Journal ref route must deep-link to the exact entry dialog');
    }
}
