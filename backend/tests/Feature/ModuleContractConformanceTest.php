<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Contracts\ModuleContract;
use Tests\TestCase;

/**
 * Module Contract adoption test (ADC §5 — Module Contract).
 *
 * Verifies that every real module contract:
 *  1. Implements the ModuleContract interface
 *  2. Matches its module.json declaration (key, name, type, deps)
 *  3. Returns valid structured data for all required accessors
 *  4. Is instantiable and callable
 */
class ModuleContractConformanceTest extends TestCase
{
    private const CONTRACTS = [
        \Modules\Core\Contracts\CoreModuleContract::class,
        \Modules\Sales\Contracts\SalesModuleContract::class,
        \Modules\POS\Contracts\POSModuleContract::class,
        \Modules\Inventory\Contracts\InventoryModuleContract::class,
        \Modules\AI\Contracts\AIModuleContract::class,
        \Modules\Contact\Contracts\ContactModuleContract::class,
        // Newly adopted (16 remaining modules)
        \Modules\AccountingCountry\Contracts\AccountingCountryModuleContract::class,
        \Modules\Communication\Contracts\CommunicationModuleContract::class,
        \Modules\CRM\Contracts\CRMModuleContract::class,
        \Modules\EventManagement\Contracts\EventManagementModuleContract::class,
        \Modules\FieldService\Contracts\FieldServiceModuleContract::class,
        \Modules\FinancialClose\Contracts\FinancialCloseModuleContract::class,
        \Modules\FinancialReporting\Contracts\FinancialReportingModuleContract::class,
        \Modules\Fulfillment\Contracts\FulfillmentModuleContract::class,
        \Modules\HRM\Contracts\HRMModuleContract::class,
        \Modules\IoT\Contracts\IoTModuleContract::class,
        \Modules\Loyalty\Contracts\LoyaltyModuleContract::class,
        \Modules\OCRBookkeeping\Contracts\OCRBookkeepingModuleContract::class,
        \Modules\PaymentGateway\Contracts\PaymentGatewayModuleContract::class,
        \Modules\WebsiteBuilder\Contracts\WebsiteBuilderModuleContract::class,
        \Modules\WhatsApp\Contracts\WhatsAppModuleContract::class,
    ];

    private function loadModuleJson(string $class): object
    {
        // Derive the module directory from the contract class namespace
        // e.g. Modules\EventManagement\Contracts\... → EventManagement
        $parts = explode('\\', $class);
        $directory = $parts[1] ?? $parts[0];
        $path = base_path("modules/{$directory}/module.json");
        $this->assertFileExists($path, "module.json missing for directory '{$directory}' (class: {$class})");

        return json_decode(file_get_contents($path));
    }

    /**
     * @dataProvider contractProvider
     */
    public function test_implements_module_contract_interface(string $class): void
    {
        $instance = new $class;
        $this->assertInstanceOf(ModuleContract::class, $instance);
    }

    /**
     * @dataProvider contractProvider
     */
    public function test_contract_matches_module_json(string $class): void
    {
        $instance = new $class;
        $json = $this->loadModuleJson($class);

        $this->assertSame($json->key, $instance->getKey());
        $this->assertSame($json->name, $instance->getName());
        $this->assertSame($json->type, $instance->getType());
        $this->assertSame((array) ($json->dependencies ?? []), $instance->getDependencies());
    }

    /**
     * @dataProvider contractProvider
     */
    public function test_contract_returns_valid_metadata(string $class): void
    {
        $instance = new $class;

        $this->assertNotEmpty($instance->getDescription());
        $this->assertNotEmpty($instance->getVersion());
        $this->assertIsArray($instance->getPermissions());
        $this->assertIsArray($instance->getEvents());
        $this->assertIsArray($instance->getDependencies());
    }

    /**
     * @dataProvider contractProvider
     */
    public function test_onboarding_config_shape(string $class): void
    {
        $config = (new $class)->getOnboardingConfig();

        $this->assertArrayHasKey('required_setup_steps', $config);
        $this->assertArrayHasKey('default_settings', $config);
        $this->assertArrayHasKey('recommended_roles', $config);
        $this->assertIsArray($config['required_setup_steps']);
    }

    /**
     * @dataProvider contractProvider
     */
    public function test_activation_rules_shape(string $class): void
    {
        $rules = (new $class)->getActivationRules();

        $this->assertArrayHasKey('requires', $rules);
        $this->assertArrayHasKey('settings', $rules);
        $this->assertArrayHasKey('min_tier', $rules);
    }

    /**
     * @dataProvider contractProvider
     */
    public function test_deactivation_rules_shape(string $class): void
    {
        $rules = (new $class)->getDeactivationRules();

        $this->assertArrayHasKey('removes_data', $rules);
        $this->assertArrayHasKey('preserves_entities', $rules);
        $this->assertArrayHasKey('cleanup_jobs', $rules);
        $this->assertIsBool($rules['removes_data']);
    }

    /**
     * @dataProvider contractProvider
     */
    public function test_ai_capabilities_shape(string $class): void
    {
        $caps = (new $class)->getAICapabilities();

        $this->assertArrayHasKey('has_ai_assistant', $caps);
        $this->assertArrayHasKey('ai_suggestions', $caps);
        $this->assertArrayHasKey('ai_automation', $caps);
        $this->assertArrayHasKey('ai_data_access', $caps);
        $this->assertIsArray($caps['ai_data_access']);
    }

    /**
     * @dataProvider contractProvider
     */
    public function test_external_mode_config_shape(string $class): void
    {
        $config = (new $class)->getExternalModeConfig();

        $this->assertArrayHasKey('supports_coupled', $config);
        $this->assertArrayHasKey('supports_decoupled', $config);
        $this->assertArrayHasKey('supports_external', $config);
        $this->assertArrayHasKey('external_api_endpoints', $config);
    }

    /**
     * @dataProvider contractProvider
     */
    public function test_can_activate_returns_bool_in_ready(string $class): void
    {
        $result = (new $class)->canActivate();

        $this->assertArrayHasKey('ready', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertIsBool($result['ready']);
        $this->assertIsArray($result['reasons']);
    }

    public function test_module_contracts_are_registered(): void
    {
        // 21 since the legacy Ecommerce contract was deleted in P5 slice 22
        // (FR-ARC-06 code deletion — the module keeps only its read surface,
        // no contract; test-semantic change disclosed in the retirement report).
        $this->assertCount(21, self::CONTRACTS, 'All 21 live module contracts must be registered');
    }

    public static function contractProvider(): array
    {
        return array_combine(
            array_map(fn ($c) => class_basename($c), self::CONTRACTS),
            array_map(fn ($c) => [$c], self::CONTRACTS),
        );
    }
}
