<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAddress;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;
use Tests\TenantTestCase;

/**
 * POS order and OCR document lists must support the same wide search as the
 * sales documents: a customer/vendor contact, address or line value must find
 * the row — not just their own number/vendor name.
 */
class PosOcrWideSearchTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'abcabcab-cabc-4abc-8abc-abcabcabcabc';
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'POS OCR Wide Search Tenant',
            'email' => 'pos-ocr-wide@example.com',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($tenantId);

        $this->user = User::create([
            'name' => 'Tenant User',
            'email' => 'pos-ocr-wide-user@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    /**
     * @return int[]
     */
    private function searchIds(string $path, string $term): array
    {
        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson($path.'?search='.urlencode($term).'&per_page=50')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->map('intval')->all();
    }

    public function test_pos_order_search_matches_customer_contact_and_line(): void
    {
        $customer = Customer::factory()->create(['name' => 'Astra Specialists']);
        CustomerContact::create([
            'customer_id' => $customer->id,
            'name' => 'Tan Toh Lick',
            'contact_category' => 'doctor',
            'contact_type' => 'doctor',
        ]);
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'type' => 'billing',
            'label' => 'HQ',
            'street' => '12 Jalan Kilang',
            'city' => 'Petaling Jaya',
            'country' => 'Malaysia',
        ]);

        $terminal = PosTerminal::create(['name' => 'POS T', 'code' => 'POST-'.\Illuminate\Support\Str::random(5), 'is_active' => true]);
        $session = PosSession::create([
            'terminal_id' => $terminal->id,
            'user_id' => $this->user->id,
            'session_number' => 'POSS-'.\Illuminate\Support\Str::random(5),
            'status' => 'open',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        $order = PosOrder::create([
            'external_id' => (string) \Illuminate\Support\Str::uuid(),
            'order_number' => 'POS-TEST-1',
            'session_id' => $session->id,
            'customer_id' => $customer->id,
            'status' => 'draft',
            'subtotal' => 10,
            'tax_amount' => 0,
            'total' => 10,
            'currency' => 'SGD',
            'created_by' => $this->user->id,
        ]);
        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => null,
            'name' => 'Counter Widget',
            'sku' => 'POS-SKU-1',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 10,
        ]);

        $this->assertContains($order->id, $this->searchIds('/api/v1/pos/orders', 'Tan Toh'));
        $this->assertContains($order->id, $this->searchIds('/api/v1/pos/orders', 'Petaling Jaya'));
        $this->assertContains($order->id, $this->searchIds('/api/v1/pos/orders', 'POS-SKU-1'));
        $this->assertContains($order->id, $this->searchIds('/api/v1/pos/orders', 'POS-TEST-1'));
        $this->assertSame([], $this->searchIds('/api/v1/pos/orders', 'Nobody Zzz'));
    }

    public function test_ocr_document_search_matches_vendor_address(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Astra Supplies Pte Ltd']);
        VendorAddress::create([
            'vendor_id' => $vendor->id,
            'type' => 'billing',
            'label' => 'HQ',
            'street' => '12 Jalan Kilang',
            'city' => 'Petaling Jaya',
            'country' => 'Malaysia',
        ]);

        $document = OcrDocument::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'file_path' => 'ocr/test/bill.pdf',
            'file_name' => 'astra-bill.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'status' => 'NEEDS_REVIEW',
            'vendor_id' => $vendor->id,
            'vendor_name' => 'Astra Supplies Pte Ltd',
            'document_reference' => 'REF-123',
            'total_amount' => 123.45,
            'created_by' => $this->user->id,
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson('/api/v1/ocr/documents?search='.urlencode('Petaling Jaya').'&per_page=50')
            ->assertOk();

        $rows = collect($response->json('data'));
        $row = $rows->firstWhere('id', $document->id);
        $this->assertNotNull($row, 'Searching the vendor address must find the OCR document.');
        $this->assertNotEmpty($row['matched_addresses'] ?? [], 'The row must show WHY it matched.');
    }
}
