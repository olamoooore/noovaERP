<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #78 (MEDIUM, path disclosure) — the chatter API returned the raw
 * storage URL (disk, model name, subject id, timestamp, suffix), enabling
 * enumeration. Attachments are now served through an authenticated download
 * endpoint and the API never exposes the storage path.
 */
class HR78ChatterAttachmentUrlTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR78 Tenant', 'email' => 'hr78-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        User::factory()->create();
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole('Admin');

        return $user;
    }

    private function makeInvoice(int $userId): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR78 Customer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR78-'.Str::random(6),
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

    private function subjectPath(int $invoiceId): string
    {
        return '/api/v1/chatter/'.rawurlencode('App\Models\Invoice').'/'.$invoiceId;
    }

    private function upload(User $user, int $invoiceId): array
    {
        Storage::fake('public');

        $response = $this->actingAs($user)->post(
            $this->subjectPath($invoiceId).'/attachments',
            ['file' => UploadedFile::fake()->create('invoice.pdf', 4, 'application/pdf')],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201, $response->getContent());

        return $response->json('data');
    }

    public function test_api_url_does_not_expose_the_storage_path(): void
    {
        $user = $this->actingAsAdmin();
        $invoiceId = $this->makeInvoice($user->id);

        $attachment = $this->upload($user, $invoiceId);

        $this->assertStringNotContainsString('/storage/', $attachment['url']);
        $this->assertStringNotContainsString('App_Models', $attachment['url']);
        $this->assertStringContainsString('/attachments/'.$attachment['id'].'/download', $attachment['url']);

        // The bundle listing must not leak the path either.
        $bundle = $this->actingAs($user)->getJson($this->subjectPath($invoiceId));
        $bundle->assertStatus(200);
        $url = $bundle->json('data.attachments.0.url');
        $this->assertStringNotContainsString('/storage/', (string) $url);
        $this->assertStringContainsString('/download', (string) $url);
    }

    public function test_attachment_downloads_through_the_authenticated_endpoint(): void
    {
        $user = $this->actingAsAdmin();
        $invoiceId = $this->makeInvoice($user->id);

        $attachment = $this->upload($user, $invoiceId);

        $download = $this->actingAs($user)->get($attachment['url']);

        $download->assertStatus(200);
        $this->assertSame('application/pdf', $download->headers->get('content-type'));
    }

    public function test_download_is_scoped_to_the_subject(): void
    {
        $user = $this->actingAsAdmin();
        $invoiceA = $this->makeInvoice($user->id);
        $invoiceB = $this->makeInvoice($user->id);

        $attachment = $this->upload($user, $invoiceA);

        // Same attachment id under a different subject must 404.
        $crossSubject = '/api/v1/chatter/'.rawurlencode('App\Models\Invoice')."/{$invoiceB}/attachments/{$attachment['id']}/download";

        $this->actingAs($user)->get($crossSubject)->assertStatus(404);
    }

    public function test_unauthenticated_download_is_rejected(): void
    {
        $user = $this->actingAsAdmin();
        $invoiceId = $this->makeInvoice($user->id);

        $attachment = $this->upload($user, $invoiceId);

        // actingAs() persists for the rest of the test — clear it to simulate
        // a genuinely unauthenticated request.
        $this->app['auth']->forgetGuards();

        $this->get($attachment['url'])->assertStatus(401);
    }
}
