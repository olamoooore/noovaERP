<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * #490 (PAY-UX4): before posting a foreign-currency payment the operator sees
 * the currency, the payment rate (defaulted from the stored rate snapshot,
 * editable), the base-currency amount and the expected realized FX gain/loss.
 * The preview MUST be the same computation the posting uses, so preview ==
 * posted. An edited rate is sent as `exchange_rate` and is the rate posted.
 *
 * Everything runs through the real router. Rates are stored ExchangeRate
 * fixtures (tenant base SGD; USD→SGD 1.3500 at invoice, 1.3620 on payment day).
 */
class Issue490PaymentFxPreviewTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) \Illuminate\Support\Str::uuid();
        $this->bindTenantContextWithPermissions($this->tenantId, null, UserRole::ADMIN->spatieName(), autoAssignRole: false);
        \App\Models\Tenant::withoutEvents(fn () => \App\Models\Tenant::create([
            'id' => $this->tenantId,
            'name' => 'FX Preview Tenant',
            'email' => 'fxp-'.substr(md5($this->tenantId), 0, 8).'@example.com',
            'plan' => 'standard',
        ]));
        $this->admin = $this->makeRoleUser(UserRole::ADMIN);
        CompanySettings::query()->create(['base_currency' => 'SGD']);

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3500,
            'effective_date' => now()->subDays(2)->toDateString(), 'source' => 'api',
        ]);

        $this->customer = Customer::factory()->create(['name' => 'FX Preview Customer', 'payment_terms' => 30]);
    }

    private function makeRoleUser(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->spatieName());

        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => (int) $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $role->value, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return $user;
    }

    private function postedInvoice(string $currency = 'USD'): int
    {
        $product = Product::factory()->create(['price' => 100.0, 'cost' => 40.0, 'track_inventory' => false]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'currency_code' => $currency,
            'lines' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);
        $response->assertCreated();
        $invoiceId = (int) $response->json('id');
        $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/post")->assertOk();

        return $invoiceId;
    }

    /** @return array{bank: float, ar: float, gain: float, loss: float, entry: JournalEntry} */
    private function postedLegs(int $paymentId): array
    {
        $entry = Payment::with('journalEntry.lines.account')->find($paymentId)->journalEntry;
        $this->assertNotNull($entry, 'payment posted');

        $sum = fn (string $code, string $side) => round((float) $entry->lines
            ->filter(fn ($l) => optional($l->account)->code === $code)->sum($side), 2);

        $this->assertSame(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2),
            'payment journal must balance in base currency'
        );

        return [
            'bank' => round($sum('1010', 'debit') + $sum('1020', 'debit'), 2),
            'ar' => $sum('1100', 'credit'),
            'gain' => $sum('7201', 'credit'),
            'loss' => $sum('7202', 'debit'),
            'entry' => $entry,
        ];
    }

    public function test_preview_defaults_rate_from_snapshot_and_equals_the_posted_fx_gain(): void
    {
        $invoiceId = $this->postedInvoice();

        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3620,
            'effective_date' => now()->toDateString(), 'source' => 'manual',
        ]);

        $preview = $this->actingAs($this->admin)->getJson('/api/v1/payments/fx-preview?'.http_build_query([
            'invoice_id' => $invoiceId,
            'amount' => 1000,
            'payment_date' => now()->toDateString(),
        ]));
        $preview->assertOk();
        $preview->assertJsonPath('data.foreign', true);
        $preview->assertJsonPath('data.currency', 'USD');
        $preview->assertJsonPath('data.base_currency_code', 'SGD');
        $this->assertSame(1.35, round((float) $preview->json('data.invoice_rate'), 6));
        $this->assertSame(1.362, round((float) $preview->json('data.exchange_rate'), 6), 'rate defaulted from the payment-date snapshot');
        $this->assertSame(1362.00, (float) $preview->json('data.base_amount'));
        $this->assertSame(1350.00, (float) $preview->json('data.ar_base_amount'));
        $this->assertSame(12.00, (float) $preview->json('data.fx_amount'));
        $preview->assertJsonPath('data.fx_type', 'gain');

        // Post through the real routes with the defaulted rate (no exchange_rate sent).
        $paymentId = (int) $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 1000.00,
            'method' => 'bank_transfer',
            'payment_date' => now()->toDateString(),
        ])->assertCreated()->json('id');
        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$paymentId}/post")->assertOk();

        $legs = $this->postedLegs($paymentId);
        $this->assertSame((float) $preview->json('data.base_amount'), $legs['bank'], 'preview base amount == posted bank debit');
        $this->assertSame((float) $preview->json('data.ar_base_amount'), $legs['ar'], 'preview AR == posted AR credit');
        $this->assertSame((float) $preview->json('data.fx_amount'), $legs['gain'], 'preview FX gain == posted realized gain');
        $this->assertSame(0.0, $legs['loss']);
    }

    public function test_edited_rate_is_sent_as_exchange_rate_and_preview_equals_the_posted_fx_loss(): void
    {
        $invoiceId = $this->postedInvoice();

        // The bank's actual rate differs from the stored snapshot (1.35).
        $bankRate = 1.3311;

        $preview = $this->actingAs($this->admin)->getJson('/api/v1/payments/fx-preview?'.http_build_query([
            'invoice_id' => $invoiceId,
            'amount' => 400,
            'payment_date' => now()->toDateString(),
            'exchange_rate' => $bankRate,
        ]));
        $preview->assertOk();
        $this->assertSame($bankRate, round((float) $preview->json('data.exchange_rate'), 6));
        $preview->assertJsonPath('data.rate_source', 'manual');
        // 400 × 1.3311 = 532.44; AR 400 × 1.35 = 540.00; loss 7.56
        $this->assertSame(532.44, (float) $preview->json('data.base_amount'));
        $this->assertSame(-7.56, (float) $preview->json('data.fx_amount'));
        $preview->assertJsonPath('data.fx_type', 'loss');

        $paymentId = (int) $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 400.00,
            'method' => 'bank_transfer',
            'payment_date' => now()->toDateString(),
            'exchange_rate' => $bankRate,
        ])->assertCreated()->json('id');

        $this->assertSame($bankRate, round((float) Payment::find($paymentId)->exchange_rate, 6), 'edited rate is stored on the draft');

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$paymentId}/post")->assertOk();

        $legs = $this->postedLegs($paymentId);
        $this->assertSame((float) $preview->json('data.base_amount'), $legs['bank'], 'preview base amount == posted bank debit');
        $this->assertSame(round(-(float) $preview->json('data.fx_amount'), 2), $legs['loss'], 'preview FX loss == posted realized loss');
        $this->assertSame(0.0, $legs['gain']);
    }

    public function test_base_currency_invoice_preview_has_no_fx(): void
    {
        $invoiceId = $this->postedInvoice('SGD');

        $preview = $this->actingAs($this->admin)->getJson('/api/v1/payments/fx-preview?'.http_build_query([
            'invoice_id' => $invoiceId,
            'amount' => 250,
        ]));
        $preview->assertOk();
        $preview->assertJsonPath('data.foreign', false);
        $this->assertSame(1.0, (float) $preview->json('data.exchange_rate'));
        $this->assertSame(250.00, (float) $preview->json('data.base_amount'));
        $this->assertSame(0.0, (float) $preview->json('data.fx_amount'));
        $preview->assertJsonPath('data.fx_type', 'none');
    }

    public function test_invalid_exchange_rate_is_rejected(): void
    {
        $invoiceId = $this->postedInvoice();

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 100,
            'method' => 'bank_transfer',
            'exchange_rate' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('exchange_rate');
    }
}
