<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\CompanySettingsController;
use App\Platform\Settings\Contracts\SettingsRegistryContract;
use App\Platform\Settings\SettingDefinition;
use App\Platform\Settings\SettingsRegistry;
use App\Platform\Settings\SettingsUsageScanner;
use App\Services\InvoiceApprovalService;
use App\Services\SalesSettings;
use App\Services\TaxEngineService;
use Tests\TestCase;

/**
 * Issue #921 (SS5) — the CI check. Runs in the Feature suite (backend-ci).
 *
 * Fails when:
 *  - a declared setting has no reader outside its own settings screen, or a
 *    declared reader no longer reaches the key ("saved but silently ignored");
 *  - code reads or writes a `Setting` key that is not declared and is not in
 *    the shrink-only baseline;
 *  - a baselined key has since been declared or is no longer used (the
 *    baseline must shrink with it).
 */
class Issue921SettingsRegistryReadersTest extends TestCase
{
    private const BASELINE = 'app/Platform/Settings/undeclared-settings-baseline.json';

    private function registry(): SettingsRegistryContract
    {
        return app(SettingsRegistryContract::class);
    }

    private function scanner(): SettingsUsageScanner
    {
        return app(SettingsUsageScanner::class);
    }

    public function test_every_declared_setting_has_a_reader_that_reaches_its_key(): void
    {
        $this->assertNotEmpty($this->registry()->all());
        $this->assertSame([], $this->scanner()->readerProblems());
    }

    public function test_code_reads_no_undeclared_setting_key_outside_the_shrink_only_baseline(): void
    {
        $baseline = json_decode((string) file_get_contents(base_path(self::BASELINE)), true, flags: JSON_THROW_ON_ERROR)['keys'];
        $usages = $this->scanner()->literalKeyUsages()['keys'];

        $undeclared = array_values(array_filter(array_keys($usages), fn (string $key) => ! $this->registry()->isDeclared($key)));

        $new = array_values(array_diff($undeclared, $baseline));
        $this->assertSame([], $new, "Undeclared Setting keys used in code — declare them in SettingsRegistry:\n"
            .implode("\n", array_map(fn ($k) => "  {$k}: ".implode(', ', $usages[$k]), $new)));

        $stale = array_values(array_diff($baseline, $undeclared));
        $this->assertSame([], $stale, 'Remove these keys from '.self::BASELINE.' (now declared or no longer used).');
    }

    public function test_sales_screen_defaults_are_the_549_defaults_and_every_one_is_declared(): void
    {
        $declared = $this->registry()->forScreen(SettingsRegistry::SCREEN_SALES);

        foreach (SalesSettings::defaults() as $group => $fields) {
            foreach ($fields as $field => $default) {
                $this->assertArrayHasKey("{$group}.{$field}", $declared, "{$group}.{$field} has a #549 default but is not declared.");
                $this->assertSame($default, $declared["{$group}.{$field}"]->default, "{$group}.{$field}");
            }
        }
    }

    public function test_the_check_flags_a_setting_with_no_reader_or_a_reader_that_does_not_read_it(): void
    {
        $make = fn (string $field, string $key, array $readers, array $via = [], ?string $path = null, array $keyParts = []) => new SettingDefinition(
            screen: SettingsRegistry::SCREEN_SALES, field: $field, key: $key, type: 'bool', default: null,
            scope: 'tenant', editPermission: 'settings.write', readers: $readers, via: $via, path: $path, keyParts: $keyParts,
        );

        $problems = $this->scanner()->readerProblems([
            // Saved, nothing declared to read it (#539).
            $make('invoice.orphan', 'sales.invoice.orphan', []),
            // Only the settings screen echoing it back.
            $make('invoice.echo_only', 'sales.invoice.echo_only', [CompanySettingsController::class.'::getSalesSettings']),
            // A reader that does not exist.
            $make('invoice.ghost', 'sales.invoice.ghost', ['App\\Services\\NoSuchService::read']),
            // A real method that never touches the key.
            $make('invoice.wrong_reader', 'sales.invoice.wrong_reader', [InvoiceApprovalService::class.'::submit']),
            // Right block, wrong field: the engine never reads pricing_mode.
            $make('tax.prices_include_tax', 'tax', [TaxEngineService::class.'::resolveRaw'], path: 'pricing_mode'),
            // Composed key whose prefix the reader class never uses.
            $make('nope.reviewer_roles', 'sales.nope.reviewer_roles', [InvoiceApprovalService::class.'::submit'], keyParts: ['sales.nope', '.reviewer_roles']),
        ]);

        $text = implode("\n", $problems);
        foreach (['invoice.orphan', 'invoice.echo_only', 'invoice.ghost', 'invoice.wrong_reader', 'tax.prices_include_tax', 'nope.reviewer_roles'] as $field) {
            $this->assertStringContainsString("sales:{$field} ", $text."\n", "{$field} should be flagged");
            $this->assertMatchesRegularExpression('/sales:'.preg_quote($field, '/').' \([^)]*\): declared setting has no reader/', $text, $field);
        }
        $this->assertStringContainsString('is the settings screen itself', $text);
        $this->assertStringContainsString('does not exist', $text);
        $this->assertStringContainsString("never reads 'pricing_mode'", $text);
    }
}
