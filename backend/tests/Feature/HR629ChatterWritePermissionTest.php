<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #629 (P1, CH1) — chatter write actions (post, activity, mark-done,
 * upload, delete) were gated only by the record's VIEW permission, so VIEWER
 * and CASHIER could post/upload/delete on any invoice and VIEWER on any SO.
 *
 * Fix: config/chatter.php gains a write permission per subject; writes require
 * it. Attachment deletion additionally requires the uploader or a manager role
 * and is refused outright for evidence-linked files (CH2 / #630).
 *
 * Permission matrix relied on (RolePermissionSeeder):
 *   invoices.view -> … CASHIER, VIEWER    invoices.write -> … SALES (not viewer/cashier)
 *   sales.view    -> … VIEWER             sales.write    -> … SALES
 *   customers.view-> … VIEWER             customers.write-> … SALES
 */
class HR629ChatterWritePermissionTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR629 Tenant', 'email' => 'hr629-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function actingAsRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);

        return $user;
    }

    private function enc(string $fqcn): string
    {
        return rawurlencode($fqcn);
    }

    private function makeInvoice(int $userId): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR629 Customer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR629-'.Str::random(6),
            'customer_id' => $customerId,
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'subtotal' => 100, 'tax_amount' => 0, 'discount_amount' => 0,
            'shipping_cost' => 0, 'total' => 100, 'status' => 'posted',
            'lifecycle_status' => 'posted', 'version_number' => 1, 'is_active_version' => true,
            'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeSalesOrder(int $userId): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR629 SO Customer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('sales_orders')->insertGetId([
            'sales_order_number' => 'SO-HR629-'.Str::upper(Str::random(6)),
            'customer_id' => $customerId,
            'channel' => 'admin',
            'status' => 'draft',
            'accounting_status' => 'not_posted',
            'subtotal' => 10,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 10,
            'order_date' => now()->toDateString(),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttachment(string $subjectType, int $subjectId, int $uploadedById, string $path = ''): int
    {
        $path = $path !== '' ? $path : 'chatter/'.Str::random(12).'.pdf';
        Storage::disk('public')->put($path, 'dummy');

        return (int) DB::table('chatter_attachments')->insertGetId([
            'tenant_id' => $this->tenantId,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'uploaded_by_id' => $uploadedById,
            'filename' => basename($path),
            'mime_type' => 'application/pdf',
            'size' => 5,
            'storage_disk' => 'public',
            'storage_path' => $path,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── Writes are denied for view-only roles ───────────────────────

    public function test_viewer_cannot_post_message_to_invoice_chatter(): void
    {
        $viewer = $this->actingAsRole('viewer', 'Viewer');
        $invoiceId = $this->makeInvoice($viewer->id);

        $response = $this->actingAs($viewer)->postJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/messages",
            ['body' => 'A viewer must not be able to post here.']
        );

        $response->assertStatus(403);
    }

    public function test_viewer_cannot_add_activity_to_invoice_chatter(): void
    {
        $viewer = $this->actingAsRole('viewer', 'Viewer');
        $invoiceId = $this->makeInvoice($viewer->id);

        $response = $this->actingAs($viewer)->postJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/activities",
            ['activity_type' => 'call', 'summary' => 'Viewer activity must fail']
        );

        $response->assertStatus(403);
    }

    public function test_viewer_cannot_upload_attachment_to_invoice_chatter(): void
    {
        $viewer = $this->actingAsRole('viewer', 'Viewer');
        $invoiceId = $this->makeInvoice($viewer->id);

        $response = $this->actingAs($viewer)->postJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/attachments",
            ['file' => UploadedFile::fake()->create('viewer.pdf', 4, 'application/pdf')]
        );

        $response->assertStatus(403);
    }

    public function test_viewer_cannot_delete_invoice_attachment(): void
    {
        $viewer = $this->actingAsRole('viewer', 'Viewer');
        $invoiceId = $this->makeInvoice($viewer->id);
        $attachmentId = $this->insertAttachment(Invoice::class, $invoiceId, $viewer->id);

        $response = $this->actingAs($viewer)->deleteJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/attachments/{$attachmentId}"
        );

        $response->assertStatus(403);
        $this->assertDatabaseHas('chatter_attachments', ['id' => $attachmentId]);
    }

    public function test_cashier_cannot_delete_invoice_attachment(): void
    {
        $cashier = $this->actingAsRole('cashier', 'Cashier');
        $invoiceId = $this->makeInvoice($cashier->id);
        $attachmentId = $this->insertAttachment(Invoice::class, $invoiceId, $cashier->id);

        $response = $this->actingAs($cashier)->deleteJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/attachments/{$attachmentId}"
        );

        $response->assertStatus(403);
        $this->assertDatabaseHas('chatter_attachments', ['id' => $attachmentId]);
    }

    public function test_viewer_can_still_read_invoice_chatter(): void
    {
        $viewer = $this->actingAsRole('viewer', 'Viewer');
        $invoiceId = $this->makeInvoice($viewer->id);

        $this->actingAs($viewer)
            ->getJson('/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}")
            ->assertStatus(200);
    }

    // ─── Writes are allowed for roles holding the write permission ───

    public function test_sales_can_post_message_on_sales_order(): void
    {
        $sales = $this->actingAsRole('sales', 'Sales');
        $orderId = $this->makeSalesOrder($sales->id);

        $response = $this->actingAs($sales)->postJson(
            '/api/v1/chatter/'.$this->enc(SalesOrder::class)."/{$orderId}/messages",
            ['body' => 'Sales note on the order.']
        );

        $response->assertStatus(201);
    }

    public function test_sales_can_upload_attachment_on_sales_order(): void
    {
        $sales = $this->actingAsRole('sales', 'Sales');
        $orderId = $this->makeSalesOrder($sales->id);

        $response = $this->actingAs($sales)->postJson(
            '/api/v1/chatter/'.$this->enc(SalesOrder::class)."/{$orderId}/attachments",
            ['file' => UploadedFile::fake()->create('order.pdf', 4, 'application/pdf')]
        );

        $response->assertStatus(201);
    }

    // ─── Deletion requires uploader or manager ───────────────────────

    public function test_sales_cannot_delete_another_users_attachment(): void
    {
        $sales = $this->actingAsRole('sales', 'Sales');
        $uploader = $this->actingAsRole('manager', 'Manager');
        $invoiceId = $this->makeInvoice($sales->id);
        $attachmentId = $this->insertAttachment(Invoice::class, $invoiceId, $uploader->id);

        $response = $this->actingAs($sales)->deleteJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/attachments/{$attachmentId}"
        );

        $response->assertStatus(403);
        $this->assertDatabaseHas('chatter_attachments', ['id' => $attachmentId]);
    }

    public function test_sales_can_delete_own_attachment(): void
    {
        $sales = $this->actingAsRole('sales', 'Sales');
        $invoiceId = $this->makeInvoice($sales->id);
        $attachmentId = $this->insertAttachment(Invoice::class, $invoiceId, $sales->id);

        $response = $this->actingAs($sales)->deleteJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/attachments/{$attachmentId}"
        );

        $response->assertStatus(200);
        $this->assertDatabaseMissing('chatter_attachments', ['id' => $attachmentId]);
    }

    public function test_manager_can_delete_another_users_attachment(): void
    {
        $manager = $this->actingAsRole('manager', 'Manager');
        $uploader = $this->actingAsRole('sales', 'Sales');
        $invoiceId = $this->makeInvoice($manager->id);
        $attachmentId = $this->insertAttachment(Invoice::class, $invoiceId, $uploader->id);

        $response = $this->actingAs($manager)->deleteJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/attachments/{$attachmentId}"
        );

        $response->assertStatus(200);
        $this->assertDatabaseMissing('chatter_attachments', ['id' => $attachmentId]);
    }

    // ─── Evidence-linked attachments are never deleted via chatter ───

    public function test_evidence_linked_attachment_cannot_be_deleted_by_its_uploader(): void
    {
        $sales = $this->actingAsRole('sales', 'Sales');
        $invoiceId = $this->makeInvoice($sales->id);

        $upload = $this->actingAs($sales)->postJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/attachments",
            ['file' => UploadedFile::fake()->create('proof.pdf', 4, 'application/pdf')]
        );
        $upload->assertStatus(201);

        $attachment = DB::table('chatter_attachments')
            ->where('subject_type', Invoice::class)
            ->where('subject_id', (string) $invoiceId)
            ->where('filename', 'proof.pdf')
            ->first();

        $this->assertNotNull($attachment);

        // The file is the invoice's evidence (same disk path).
        DB::table('invoice_evidence')->insert([
            'invoice_id' => $invoiceId,
            'type' => 'payment_proof',
            'source' => 'upload',
            'file_path' => $attachment->storage_path,
            'file_name' => 'proof.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($sales)->deleteJson(
            '/api/v1/chatter/'.$this->enc(Invoice::class)."/{$invoiceId}/attachments/{$attachment->id}"
        );

        $response->assertStatus(403);
        $this->assertDatabaseHas('chatter_attachments', ['id' => $attachment->id]);
        $this->assertTrue(
            Storage::disk('public')->exists($attachment->storage_path),
            'The evidence file must survive the refused chatter delete.'
        );
    }

    // ─── Config integrity: every subject has a write mapping ─────────

    public function test_every_allowed_model_has_a_subject_write_permission_mapping(): void
    {
        $allowed = config('chatter.allowed_models', []);
        $map = config('chatter.subject_write_permissions', []);

        $missing = [];
        foreach ($allowed as $model) {
            if (! isset($map[$model])) {
                $missing[] = $model;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These whitelisted chatter models have no subject_write_permissions mapping (writes would fail closed): '.implode(', ', $missing)
        );
    }
}
