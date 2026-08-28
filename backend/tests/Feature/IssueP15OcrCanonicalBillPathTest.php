<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Vendor;
use App\Services\OcrPromotionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue P1-5 — OCR documents must promote into canonical
 * Bills (via BillService::createBill), never via raw DB::table('bills')->insert().
 */
class IssueP15OcrCanonicalBillPathTest extends TenantTestCase
{
    private string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        $user = \App\Models\User::factory()->create();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $user->assignRole('Admin');
        Auth::login($user);
    }

    private function makeVendor(): Vendor
    {
        return Vendor::create([
            'name' => 'OCR Test Vendor '.uniqid(),
            'code' => 'OCR-V-'.uniqid(),
            'email' => 'vendor@ocr-test.example',
            'is_active' => true,
        ]);
    }

    private function makeOcrDocument(Vendor $vendor, array $overrides = []): OcrDocument
    {
        $userId = Auth::id() ?? 0;

        return OcrDocument::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'file_name' => 'invoice-'.uniqid().'.pdf',
            'file_path' => '/tmp/fake-'.uniqid().'.pdf',
            'file_hash' => md5(uniqid('content', true)),
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $vendor->id,
            'vendor_name' => $vendor->name,
            'document_reference' => 'INV-OCR-'.uniqid(),
            'document_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 100,
            'tax_amount' => 9,
            'total_amount' => 109,
            'currency' => 'USD',
            'line_items' => [
                [
                    'description' => 'Test service line',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 9,
                    'tax_amount' => 9,
                    'amount' => 109,
                ],
            ],
            'extracted_at' => now(),
            'created_by' => $userId,
        ], $overrides));
    }

    public function test_ocr_promotion_uses_billservice_createbill_not_raw_db_insert(): void
    {
        // Verify that the OcrPromotionService class does NOT contain the
        // forbidden raw-DB-insert path anymore.
        $source = file_get_contents(app_path('Services/OcrPromotionService.php'));

        $this->assertStringNotContainsString(
            "DB::table('bills')->insertGetId",
            $source,
            'OcrPromotionService must not use raw DB::table(\'bills\')->insertGetId() (P1-5)'
        );
        $this->assertStringNotContainsString(
            "DB::table('bill_lines')->insert",
            $source,
            'OcrPromotionService must not use raw DB::table(\'bill_lines\')->insert() (P1-5)'
        );
        $this->assertStringContainsString(
            'BillService::class',
            $source,
            'OcrPromotionService must call BillService'
        );
    }

    public function test_promote_to_bill_creates_canonical_bill_via_billservice(): void
    {
        $vendor = $this->makeVendor();
        $doc = $this->makeOcrDocument($vendor);

        $result = app(OcrPromotionService::class)->promoteToBill($doc->id);

        $this->assertArrayHasKey('bill_id', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertSame('draft', $result['status']);

        $bill = Bill::findOrFail($result['bill_id']);
        $this->assertSame($vendor->id, $bill->vendor_id);
        $this->assertSame('draft', $bill->status);
        $this->assertEquals(109, (float) $bill->total);

        // Bill lines must exist with the canonical data
        $this->assertGreaterThan(0, $bill->lines()->count(), 'Bill must have at least one line');

        // OCR document is marked PROMOTED and points at the bill
        $doc->refresh();
        $this->assertSame('PROMOTED', $doc->status);
        $this->assertSame($bill->id, $doc->promoted_bill_id);
    }

    public function test_promotion_creates_draft_bill_without_posting_journal_entry(): void
    {
        // Promotion creates a draft bill; posting happens via the standard flow.
        $vendor = $this->makeVendor();
        $doc = $this->makeOcrDocument($vendor);

        $result = app(OcrPromotionService::class)->promoteToBill($doc->id);

        $bill = Bill::findOrFail($result['bill_id']);
        $this->assertNull($bill->journal_entry_id, 'Promotion must produce a draft bill, not a posted one');
    }
}
