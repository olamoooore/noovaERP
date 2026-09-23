<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/**
 * Issue #102 — exchange-rate sync must run on a schedule (and FX revaluation
 * must be wired into period close, which PeriodCloseService's automatic
 * adjustments already do).
 */
class Issue102ExchangeRateScheduleTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '10210210-2030-4050-6070-809010201020',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['exchange_rates', 'currencies', 'company_settings']);

        CompanySettings::query()->create(['base_currency' => 'SGD']);

        // The migrated currencies catalogue is already seeded (USD, MYR, SGD, …).
        $this->assertTrue(Currency::whereIn('code', ['SGD', 'USD', 'MYR'])->count() >= 3);
    }

    public function test_exchange_rate_sync_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $event = $events->first(fn ($event) => str_contains((string) $event->command, 'exchange-rates:sync'));

        $this->assertNotNull($event, 'exchange-rates:sync must be registered on the scheduler.');
        $this->assertSame('30 5 * * *', $event->expression, 'Rate sync must run daily.');
        $this->assertTrue($event->withoutOverlapping, 'Rate sync must not overlap.');
    }

    public function test_sync_command_updates_rates_for_the_tenant(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'conversion_rates' => [
                    'USD' => 0.74,
                    'MYR' => 3.45,
                ],
            ], 200),
        ]);

        config(['services.exchange_rate.api_key' => null]);
        putenv('EXCHANGE_RATE_API_KEY=test-key');
        $_ENV['EXCHANGE_RATE_API_KEY'] = 'test-key';
        $_SERVER['EXCHANGE_RATE_API_KEY'] = 'test-key';

        $this->artisan('exchange-rates:sync')->assertExitCode(0);

        $this->assertDatabaseHas('exchange_rates', [
            'from_currency' => 'SGD',
            'to_currency' => 'USD',
            'source' => 'api',
        ]);
        $this->assertDatabaseHas('exchange_rates', [
            'from_currency' => 'SGD',
            'to_currency' => 'MYR',
            'source' => 'api',
        ]);
        $this->assertSame(2, ExchangeRate::count());

        putenv('EXCHANGE_RATE_API_KEY');
        unset($_ENV['EXCHANGE_RATE_API_KEY'], $_SERVER['EXCHANGE_RATE_API_KEY']);
    }

    public function test_sync_command_skips_the_base_currency(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'conversion_rates' => ['USD' => 0.74, 'MYR' => 3.45, 'SGD' => 1.0],
            ], 200),
        ]);

        putenv('EXCHANGE_RATE_API_KEY=test-key');
        $_ENV['EXCHANGE_RATE_API_KEY'] = 'test-key';
        $_SERVER['EXCHANGE_RATE_API_KEY'] = 'test-key';

        $this->artisan('exchange-rates:sync')->assertExitCode(0);

        $this->assertSame(0, ExchangeRate::where('to_currency', 'SGD')->count());

        putenv('EXCHANGE_RATE_API_KEY');
        unset($_ENV['EXCHANGE_RATE_API_KEY'], $_SERVER['EXCHANGE_RATE_API_KEY']);
    }
}
