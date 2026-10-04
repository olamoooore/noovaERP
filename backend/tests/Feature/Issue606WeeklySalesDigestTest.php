<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BonusScheme;
use App\Models\BonusSchemeProduct;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\CustomerBonusScheme;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Notifications\NotificationPreference;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Sales\Mail\WeeklySalesDigestMail;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #606 — weekly per-salesperson sales digest.
 *
 * The digest runs weekly per tenant, builds every line from the shared read
 * models (SalesAnalyticsService, AgedReceivablesService, the shared
 * MedicalCaseBonusAccrualService), is entity-scoped to the recipient's
 * accessible entities, skips sections the recipient has no view permission
 * for, sends over WhatsApp when the tenant has an active WhatsApp key and the
 * user has a phone (else email) and records one send-log row per
 * user/channel/period.
 *
 * Transport is faked (Http / Mail); the data is real.
 */
class Issue606WeeklySalesDigestTest extends TenantTestCase
{
    private const WEEK_START = '2026-05-11';

    private const WEEK_END = '2026-05-17';

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday. The command covers the previous completed week
        // (2026-05-11 .. 2026-05-17).
        Carbon::setTestNow(Carbon::parse('2026-05-18 07:00:00'));

        $this->tenantId = (string) Str::uuid();

        Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => $this->tenantId,
            'name' => 'Issue 606 Tenant',
        ]));

        $this->bindTenantContextWithPermissions($this->tenantId);

        CompanySettings::query()->create(['base_currency' => 'USD', 'timezone' => 'UTC']);

        config(['frontend.url' => 'https://noovaerp.test']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            [
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $user;
    }

    private function optIn(User $user, bool $enabled = true): void
    {
        NotificationPreference::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'event' => 'sales.weekly_digest',
            'channel' => 'mail',
            'mail_enabled' => $enabled,
        ]);
    }

    private function activeWhatsAppKey(): WhatsAppApiKey
    {
        return WhatsAppApiKey::create([
            'key' => 'issue606-test-whatsapp-key',
            'name' => 'Issue 606 WhatsApp Key',
            'whatsapp_provider' => 'custom',
            'api_endpoint' => 'https://wa.example.test/send',
            'is_active' => true,
        ]);
    }

    private function sale(User $seller, Product $product, float $total, string $date, ?int $entityId = null): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Buyer '.Str::random(6),
            'salesperson_id' => $seller->id,
            'legal_entity_id' => $entityId,
        ]);

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-606-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'legal_entity_id' => $entityId,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'order_date' => $date,
            'created_by' => $seller->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'description' => 'Issue 606 line',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);
    }

    private function overdue(Customer $customer, float $balance, int $daysOverdue): void
    {
        Invoice::factory()->posted()->create([
            'customer_id' => $customer->id,
            'invoice_date' => now()->subDays($daysOverdue + 30)->toDateString(),
            'due_date' => now()->subDays($daysOverdue)->toDateString(),
            'total' => $balance,
            'balance' => $balance,
            'paid_amount' => 0,
        ]);
    }

    private function focAssignment(
        User $creator,
        Customer $customer,
        Product $qualifyingProduct,
        Product $focProduct,
        float $minimumQty,
        float $focQty,
    ): void {
        $scheme = BonusScheme::create([
            'scheme_code' => 'I606-'.Str::upper(Str::random(6)),
            'name' => 'Issue 606 Scheme',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        BonusSchemeProduct::create([
            'bonus_scheme_id' => $scheme->id,
            'qualifying_product_id' => $qualifyingProduct->id,
            'scheme_type' => 'buy_x_get_y',
            'minimum_purchase_qty' => $minimumQty,
            'foc_product_id' => $focProduct->id,
            'foc_qty' => $focQty,
            'status' => 'active',
        ]);

        CustomerBonusScheme::create([
            'customer_id' => $customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    private function runDigest(): void
    {
        $this->artisan('sales:send-weekly-digest', ['--tenant' => $this->tenantId])
            ->assertExitCode(0);
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    public function test_weekly_digest_is_scheduled(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'sales:send-weekly-digest'));

        $this->assertNotNull($event, 'sales:send-weekly-digest must be registered on the scheduler.');
        $this->assertSame('0 7 * * 1', $event->expression, 'The digest must run weekly on Monday 07:00.');
        $this->assertTrue($event->withoutOverlapping, 'The digest must not overlap.');
    }

    public function test_digest_builds_correct_figures_and_sends_via_whatsapp_with_send_log(): void
    {
        Http::fake();
        Mail::fake();

        $seller = $this->makeUser('admin', [
            'name' => 'Sam Seller',
            'phone' => '+60123456789',
        ]);
        $this->optIn($seller);
        $this->activeWhatsAppKey();

        $product = Product::factory()->create(['name' => 'Seller Product', 'price' => 1, 'cost' => 0]);

        // Current week: 82,000. Previous week: 70,000 -> +17.1%.
        $this->sale($seller, $product, 50000, self::WEEK_START);
        $this->sale($seller, $product, 32000, self::WEEK_END);
        $this->sale($seller, $product, 70000, '2026-05-06');

        // Three customers overdue more than 30 days; a fourth customer that is
        // only 10 days overdue must not be counted.
        foreach (range(1, 3) as $i) {
            $overdueCustomer = Customer::factory()->create([
                'name' => "Overdue Customer {$i}",
                'salesperson_id' => $seller->id,
            ]);
            $this->overdue($overdueCustomer, 1000, 40);
        }

        $recentCustomer = Customer::factory()->create([
            'name' => 'Recently Overdue',
            'salesperson_id' => $seller->id,
        ]);
        $this->overdue($recentCustomer, 500, 10);

        // Hospital X: buy 2 get 1 Product A free; nothing accrued yet -> the
        // next FOC is ~2 qualifying cases away.
        $qualifying = Product::factory()->create(['name' => 'Qualifying Product', 'price' => 1, 'cost' => 0]);
        $foc = Product::factory()->create(['name' => 'Product A', 'price' => 1, 'cost' => 0]);
        $hospital = Customer::factory()->create([
            'name' => 'Hospital X',
            'salesperson_id' => $seller->id,
        ]);
        $this->focAssignment($seller, $hospital, $qualifying, $foc, 2, 1);

        $this->runDigest();

        Http::assertSent(function ($request) {
            $message = (string) $request['message'];

            return str_contains($message, 'Your sales USD 82k (+17.1% vs last week)')
                && str_contains($message, '— https://noovaerp.test/sales')
                && str_contains($message, '3 customers overdue > 30 days')
                && str_contains($message, 'https://noovaerp.test/accounting/reporting/aged-receivables')
                && str_contains($message, 'Hospital X earns 1 × Product A FOC in ~2 cases')
                && str_contains($message, 'https://noovaerp.test/sales/customer-bonus-schemes');
        });

        Mail::assertNothingSent();

        $log = DB::table('sales_digest_send_logs')->where('user_id', $seller->id)->first();
        $this->assertNotNull($log, 'A send-log row must be written for the recipient.');
        $this->assertSame('whatsapp', $log->channel);
        $this->assertSame('sent', $log->status);
        $this->assertSame(self::WEEK_START, Carbon::parse($log->period_start)->toDateString());
        $this->assertSame(self::WEEK_END, Carbon::parse($log->period_end)->toDateString());
        $this->assertNotNull($log->sent_at);

        $sections = json_decode((string) $log->sections, true);
        $this->assertSame(['sales', 'overdue', 'foc'], array_keys($sections));
    }

    public function test_users_without_opt_in_are_skipped(): void
    {
        Http::fake();
        Mail::fake();

        $seller = $this->makeUser('admin', ['name' => 'Not Opted In', 'phone' => '+60123456789']);
        $this->activeWhatsAppKey();

        $product = Product::factory()->create(['name' => 'Skip Product', 'price' => 1, 'cost' => 0]);
        $this->sale($seller, $product, 5000, self::WEEK_START);

        $this->runDigest();

        $this->assertSame(0, DB::table('sales_digest_send_logs')->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_sections_without_view_permission_are_skipped(): void
    {
        Http::fake();

        $seller = $this->makeUser('sales', ['name' => 'Sales Only', 'phone' => '+60123456789']);
        $this->optIn($seller);
        $this->activeWhatsAppKey();

        // A salesperson who may see the sales domain but not invoices/bonus.
        Role::findByName('Sales', 'web')->syncPermissions(['sales.view']);

        $product = Product::factory()->create(['name' => 'Perm Product', 'price' => 1, 'cost' => 0]);
        $this->sale($seller, $product, 4000, self::WEEK_START);

        $overdueCustomer = Customer::factory()->create([
            'name' => 'Perm Overdue',
            'salesperson_id' => $seller->id,
        ]);
        $this->overdue($overdueCustomer, 1000, 40);

        $qualifying = Product::factory()->create(['name' => 'Perm Qualifying', 'price' => 1, 'cost' => 0]);
        $foc = Product::factory()->create(['name' => 'Perm FOC', 'price' => 1, 'cost' => 0]);
        $bonusCustomer = Customer::factory()->create([
            'name' => 'Perm Hospital',
            'salesperson_id' => $seller->id,
        ]);
        $this->focAssignment($seller, $bonusCustomer, $qualifying, $foc, 2, 1);

        $this->runDigest();

        Http::assertSent(function ($request) {
            $message = (string) $request['message'];

            return str_contains($message, 'Your sales USD 4k')
                && ! str_contains($message, 'overdue')
                && ! str_contains($message, 'FOC');
        });

        $log = DB::table('sales_digest_send_logs')->where('user_id', $seller->id)->first();
        $this->assertNotNull($log);
        $sections = json_decode((string) $log->sections, true);
        $this->assertSame(['sales'], array_keys($sections));
    }

    public function test_digest_is_scoped_to_the_users_accessible_entities(): void
    {
        Http::fake();

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 606 Group',
            'code' => 'I606-'.Str::upper(Str::random(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entityA = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity A',
            'code' => 'A606-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ]);
        $entityB = LegalEntity::create([
            'group_id' => $groupId,
            'name' => 'Entity B',
            'code' => 'B606-'.Str::upper(Str::random(4)),
            'status' => 'active',
        ]);

        $seller = $this->makeUser('admin', ['name' => 'Scoped Seller', 'phone' => '+60123456789']);
        $this->optIn($seller);
        $this->activeWhatsAppKey();

        DB::table('user_entity_access')->insert([
            'user_id' => $seller->id,
            'legal_entity_id' => $entityA->id,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::factory()->create(['name' => 'Entity Product', 'price' => 1, 'cost' => 0]);
        $this->sale($seller, $product, 5000, self::WEEK_START, $entityA->id);
        $this->sale($seller, $product, 99999, self::WEEK_START, $entityB->id);

        $overdueA = Customer::factory()->create([
            'name' => 'Entity A Overdue',
            'salesperson_id' => $seller->id,
            'legal_entity_id' => $entityA->id,
        ]);
        $this->overdue($overdueA, 1000, 40);

        foreach (range(1, 2) as $i) {
            $overdueB = Customer::factory()->create([
                'name' => "Entity B Overdue {$i}",
                'salesperson_id' => $seller->id,
                'legal_entity_id' => $entityB->id,
            ]);
            $this->overdue($overdueB, 1000, 40);
        }

        // Entity A's bonus line is far less imminent than entity B's, so the
        // message proves the FOC section was scoped to entity A as well.
        $qualifying = Product::factory()->create(['name' => 'Entity Qualifying', 'price' => 1, 'cost' => 0]);
        $foc = Product::factory()->create(['name' => 'Entity FOC', 'price' => 1, 'cost' => 0]);

        $hospitalA = Customer::factory()->create([
            'name' => 'Hospital A',
            'salesperson_id' => $seller->id,
            'legal_entity_id' => $entityA->id,
        ]);
        $this->focAssignment($seller, $hospitalA, $qualifying, $foc, 2, 1);

        $hospitalB = Customer::factory()->create([
            'name' => 'Hospital B',
            'salesperson_id' => $seller->id,
            'legal_entity_id' => $entityB->id,
        ]);
        $this->focAssignment($seller, $hospitalB, $qualifying, $foc, 11, 1);
        DB::table('bonus_scheme_usages')->insert([
            'customer_bonus_scheme_id' => CustomerBonusScheme::query()
                ->where('customer_id', $hospitalB->id)
                ->value('id'),
            'sales_order_item_id' => SalesOrderLine::query()->value('id'),
            'qualifying_qty' => 10,
            'discount_amount' => 0,
            'foc_qty' => 0,
            'status' => 'applied',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runDigest();

        Http::assertSent(function ($request) {
            $message = (string) $request['message'];

            return str_contains($message, 'Your sales USD 5k')
                && str_contains($message, '1 customer overdue > 30 days')
                && str_contains($message, 'Hospital A earns 1 × Entity FOC FOC in ~2 cases')
                && ! str_contains($message, '99')
                && ! str_contains($message, 'Hospital B');
        });
    }

    public function test_falls_back_to_email_when_whatsapp_is_not_configured(): void
    {
        Mail::fake();
        Http::fake();

        $seller = $this->makeUser('admin', [
            'name' => 'Email Seller',
            'email' => 'email-seller@example.test',
            'phone' => '+60123456789',
        ]);
        $this->optIn($seller);

        $product = Product::factory()->create(['name' => 'Email Product', 'price' => 1, 'cost' => 0]);
        $this->sale($seller, $product, 3000, self::WEEK_START);

        $this->runDigest();

        Mail::assertSent(WeeklySalesDigestMail::class, function (WeeklySalesDigestMail $mail) {
            return str_contains($mail->digestBody, 'Your sales USD 3k')
                && str_contains($mail->digestBody, 'https://noovaerp.test/sales');
        });

        Http::assertNothingSent();

        $log = DB::table('sales_digest_send_logs')->where('user_id', $seller->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('mail', $log->channel);
        $this->assertSame('sent', $log->status);
    }

    public function test_whatsapp_send_failure_falls_back_to_email_and_logs_both_attempts(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response(['error' => 'provider down'], 500)]);

        $seller = $this->makeUser('admin', [
            'name' => 'Fallback Seller',
            'email' => 'fallback-seller@example.test',
            'phone' => '+60123456789',
        ]);
        $this->optIn($seller);
        $this->activeWhatsAppKey();

        $product = Product::factory()->create(['name' => 'Fallback Product', 'price' => 1, 'cost' => 0]);
        $this->sale($seller, $product, 2000, self::WEEK_START);

        $this->runDigest();

        Mail::assertSent(WeeklySalesDigestMail::class);

        $logs = DB::table('sales_digest_send_logs')->where('user_id', $seller->id)->get();
        $this->assertCount(2, $logs, 'One row per channel/period: the failed WhatsApp attempt and the email fallback.');

        $whatsApp = $logs->firstWhere('channel', 'whatsapp');
        $this->assertNotNull($whatsApp);
        $this->assertSame('failed', $whatsApp->status);
        $this->assertNotNull($whatsApp->error);

        $mail = $logs->firstWhere('channel', 'mail');
        $this->assertNotNull($mail);
        $this->assertSame('sent', $mail->status);
    }
}
