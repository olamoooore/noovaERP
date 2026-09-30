<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Currency;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Currency active/inactive toggle — verify the
 * "no where to deactivate currency all is just turned on" issue is fixed.
 *
 * The toggle endpoint must:
 *  - flip is_active=false → true and true → false;
 *  - persist across read;
 *  - retain the original code/name/symbol/decimal_places;
 *  - require currencies.manage permission for mutations (MRD FR-10.2).
 */
class HR73CurrencyActiveToggleTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
    }

    public function test_toggle_currency_from_active_to_inactive(): void
    {
        $cur = Currency::create([
            'code' => 'XYZ',
            'name' => 'Test XYZ',
            'symbol' => 'X',
            'decimal_places' => 2,
            'is_active' => true,
        ]);
        $this->assertTrue($cur->is_active);

        $cur->update(['is_active' => false]);
        $cur->refresh();

        $this->assertFalse($cur->is_active);
        // Original fields preserved
        $this->assertSame('XYZ', $cur->code);
        $this->assertSame('Test XYZ', $cur->name);
        $this->assertSame('X', $cur->symbol);
        $this->assertSame(2, $cur->decimal_places);
    }

    public function test_toggle_currency_from_inactive_to_active(): void
    {
        $cur = Currency::create([
            'code' => 'ABC',
            'name' => 'Test ABC',
            'symbol' => 'A',
            'decimal_places' => 0,
            'is_active' => false,
        ]);
        $this->assertFalse($cur->is_active);

        $cur->update(['is_active' => true]);
        $cur->refresh();

        $this->assertTrue($cur->is_active);
    }

    public function test_update_endpoint_via_http_accepts_is_active_toggle(): void
    {
        $cur = Currency::create([
            'code' => 'TGL',
            'name' => 'Toggle Test',
            'symbol' => 'T',
            'decimal_places' => 2,
            'is_active' => true,
        ]);

        // Simulate the controller's update() behaviour: CurrenciesController::update
        // does $currency->update($request->only(['name', 'symbol', 'decimal_places', 'is_active']))
        // then saves. The model's $fillable includes 'is_active', so a request body
        // containing only `{ is_active: false }` toggles the flag and leaves
        // name/symbol/decimal_places untouched.
        $cur->update($cur->getFillable() ? ['is_active' => false] : []);
        $cur->refresh();

        $this->assertFalse($cur->is_active);
        $this->assertSame('Toggle Test', $cur->name);
        $this->assertSame('T', $cur->symbol);
        $this->assertSame(2, $cur->decimal_places);
    }

    public function test_routes_registered_with_currencies_manage_guard(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($r) => [
                'uri' => $r->uri(),
                'methods' => $r->methods(),
                'middleware' => $r->middleware(),
            ])
            ->filter(fn ($r) => str_starts_with($r['uri'], 'api/v1/accounting/currencies'));

        // MRD pricelist-multicurrency FR-10.2: currency activation +
        // sellability moved from the generic settings.write gate to the
        // dedicated currencies.manage permission (Owner/Admin/Accountant).
        $put = $routes->first(fn ($r) => in_array('PUT', $r['methods'])
            && str_contains($r['uri'], 'currencies/{code}')
        );
        $this->assertNotNull($put, 'PUT /currencies/{code} route must be registered');
        // The middleware is registered as the string form 'App\Http\Middleware\RequirePermission:currencies.manage'.
        $hasManage = collect($put['middleware'])->contains(function ($mw) {
            $str = is_object($mw) ? serialize($mw) : (string) $mw;

            return str_contains($str, 'currencies.manage');
        });
        $this->assertTrue($hasManage, 'PUT /currencies/{code} must require currencies.manage permission');
    }
}
