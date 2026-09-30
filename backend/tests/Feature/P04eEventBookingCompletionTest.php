<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Event;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Tests\TenantTestCase;

/**
 * P-04e — platform event bookings complete via the canonical service
 * (status + ledger). Before this change a paid booking stayed 'pending'
 * with NO ledger posting (Appendix-A L-10): the webhook's invoice-only
 * error path swallowed it and the controller decremented capacity on
 * every call. These tests pin the canonical chain: booking state via the
 * module's OWN machine, ONE engine-journal entry per booking, conditional
 * capacity decrement, replay convergence, fail-closed guards.
 */
class P04eEventBookingCompletionTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();

        DB::statement('SET search_path TO public');
        $this->bindTenantContext((string) Str::uuid());
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'P04e Events', 'email' => 'p04e-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        PaymentGateway::query()->create(['gateway_name' => 'hitpay', 'is_active' => true, 'is_test_mode' => true]);

        $owner = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $owner->email],
            ['name' => $owner->name, 'password' => $owner->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $owner->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $owner->assignRole('Owner');
        $this->bindTenantContext($this->tenantId);

        // Fixture shim (test-only): the module's TenantSchemaManager keeps
        // its own tenant id set by its production middleware; the harness
        // binds tenancy via stancl directly. Populate the property WITHOUT
        // the connection-switch side effects so the module's audit trail
        // attributes correctly. No behaviour under test is bypassed.
        $mgr = app(\Modules\EventManagement\Services\TenantSchemaManager::class);
        $tenantId = $this->tenantId;
        $bind = \Closure::bind(function () use ($tenantId) {
            $this->currentTenantId = $tenantId;
        }, $mgr, get_class($mgr));
        $bind();
    }

    public function test_paid_booking_completes_state_ledger_and_capacity_once(): void
    {
        [$event, $booking, $txn] = $this->pendingBookingWithIntent(45.00);

        app(PaymentTransactionCompletionService::class)->complete($txn, 'paynow');

        $booking->refresh();
        $this->assertSame('confirmed', $booking->state);
        $this->assertSame($txn->transaction_id, $booking->payment_reference);
        $txn->refresh();
        $this->assertNotNull($txn->processed_at, 'processed_at stamped LAST by the chain');

        $event->refresh();
        $this->assertSame(99, (int) $event->available_capacity);

        $link = JournalEntryLink::query()
            ->where('source_document_type', 'event_booking')
            ->where('source_document_id', (int) $booking->id)
            ->where('relation_type', 'post')
            ->sole();
        $je = JournalEntry::query()->with('lines')->find($link->journal_entry_id);
        $this->assertSame(45.00, round((float) $je->lines->sum('debit'), 2));
        $this->assertSame(45.00, round((float) $je->lines->sum('credit'), 2), 'DR cash / CR ticket revenue (P-04a: zero-config cash)');

        // replay: idempotent on every leg
        app(PaymentTransactionCompletionService::class)->complete($txn->fresh(), 'paynow');
        $event->refresh();
        $this->assertSame(99, (int) $event->available_capacity, 'replay must not double-consume capacity');
        $this->assertSame(1, JournalEntryLink::query()->where('source_document_type', 'event_booking')->count(),
            'exactly one ledger entry per booking');
    }

    public function test_gateway_money_clears_through_gateway_receivable_when_configured(): void
    {
        $recv = ChartOfAccount::factory()->create(['code' => '1050-'.uniqid(), 'type' => 'asset', 'is_active' => true]);
        AccountMapping::query()->updateOrCreate(['mapping_key' => 'gateway_receivable'], ['account_id' => $recv->id, 'is_active' => true]);

        [, $booking, $txn] = $this->pendingBookingWithIntent(60.00);
        app(PaymentTransactionCompletionService::class)->complete($txn, 'paynow');

        $link = JournalEntryLink::query()->where('source_document_type', 'event_booking')->sole();
        $je = JournalEntry::query()->with('lines')->find($link->journal_entry_id);
        $this->assertTrue($je->lines->contains(fn ($l) => (float) $l->debit === 60.00 && (int) $l->account_id === (int) $recv->id),
            'ticket money is a gateway receivable until settled (FR-PAY-06 parity)');
    }

    public function test_completion_fails_closed_without_the_module_handler(): void
    {
        [, , $txn] = $this->pendingBookingWithIntent(10.00);

        app()->offsetUnset(\App\Contracts\EventBookingPaymentCompletion::class);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('handler is unavailable');
            app(PaymentTransactionCompletionService::class)->complete($txn->fresh(), 'paynow');
        } finally {
            app()->singleton(
                \App\Contracts\EventBookingPaymentCompletion::class,
                \Modules\EventManagement\Services\EventBookingCompletionService::class,
            );
        }
    }

    public function test_paid_cancelled_booking_never_resurrects_and_stays_retryable(): void
    {
        [$event, $booking, $txn] = $this->pendingBookingWithIntent(20.00);
        $booking->update(['state' => 'cancelled']);

        try {
            app(PaymentTransactionCompletionService::class)->complete($txn, 'paynow');
            $this->fail('cancelled bookings must fail closed, not silently complete');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('manual refund review', $e->getMessage());
        }

        $txn->refresh();
        $this->assertNull($txn->processed_at, 'failure leaves the delivery retryable');
        $this->assertSame(0, JournalEntryLink::query()->where('source_document_type', 'event_booking')->count());
        $this->assertSame(100, (int) $event->fresh()->available_capacity);
    }

    public function test_capacity_never_goes_negative_at_sellout(): void
    {
        [$event, $booking, $txn] = $this->pendingBookingWithIntent(30.00);
        $event->update(['available_capacity' => 0]);

        app(PaymentTransactionCompletionService::class)->complete($txn, 'paynow');

        $this->assertSame('confirmed', $booking->fresh()->state, 'the buyer paid — the booking confirms');
        $this->assertSame(0, (int) $event->fresh()->available_capacity, 'and capacity floors at zero');
        $this->assertSame(1, JournalEntryLink::query()->where('source_document_type', 'event_booking')->count());
    }

    /** @return array{0: Event, 1: Booking, 2: PaymentTransaction} */
    private function pendingBookingWithIntent(float $price): array
    {
        $typeId = DB::table('event_types')->insertGetId([
            'name' => 'Concert '.Str::random(4), 'slug' => 'ct-'.Str::lower(Str::random(6)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = Event::query()->create([
            'title' => 'P04e Show '.Str::random(4),
            'event_date' => now()->addDays(30)->toDateString(),
            'venue' => 'Arena 1',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'event_type_id' => $typeId,
            'sale_start_date' => now()->subDay()->toDateString(),
            'sale_end_date' => now()->addDays(29)->toDateString(),
            'status' => 'published',
            'price' => $price,
        ]);

        $booking = Booking::query()->create([
            'booking_reference' => 'P04E'.strtoupper(Str::random(6)),
            'event_id' => $event->id,
            'buyer_name' => 'Ticket Buyer',
            'buyer_email' => 'p04e-'.Str::random(6).'@example.test',
            'buyer_phone' => '+6590000000',
            'total_amount' => $price,
            'currency' => 'SGD',
            'state' => 'pending',
        ]);

        $gateway = PaymentGateway::query()->where('gateway_name', 'hitpay')->sole();
        $txn = PaymentTransaction::query()->create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'p04e_'.Str::random(12),
            'amount' => $price,
            'currency' => 'SGD',
            'status' => 'completed',
            'metadata' => [
                'type' => 'event_booking',
                'booking_id' => (int) $booking->id,
                'booking_reference' => $booking->booking_reference,
                'event_id' => (int) $event->id,
            ],
        ]);

        return [$event, $booking, $txn];
    }
}
