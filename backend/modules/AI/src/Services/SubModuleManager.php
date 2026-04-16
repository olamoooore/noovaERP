<?php

namespace Modules\AI\Services;

use Modules\AI\SubModules\BaseModule;
use Modules\AI\SubModules\OrdersModule;
use Modules\AI\SubModules\LoyaltyModule;
use Modules\AI\SubModules\GeneralModule;
use Modules\AI\SubModules\ProductsModule;
use Modules\AI\SubModules\InvoicesModule;
use Modules\AI\SubModules\AccountModule;
use Modules\AI\SubModules\InventoryModule;
use Modules\AI\SubModules\AccountingInsightsModule;

/**
 * Sub-Module Manager
 * 
 * Manages all AI chatbot sub-modules
 */
class SubModuleManager
{
    /**
     * @var array<string, BaseModule>
     */
    private array $modules = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->registerModules();
    }

    /**
     * Register all available modules
     *
     * @return void
     */
    private function registerModules(): void
    {
        $this->register(new GeneralModule());
        $this->register(new OrdersModule());
        $this->register(new LoyaltyModule());
        $this->register(new ProductsModule());
        $this->register(new InventoryModule());
        $this->register(new InvoicesModule());
        $this->register(new AccountModule());
        $this->register(new AccountingInsightsModule());
    }

    /**
     * Register a module
     *
     * @param BaseModule $module
     * @return void
     */
    public function register(BaseModule $module): void
    {
        $this->modules[$module->getName()] = $module;
    }

    /**
     * Get a module by name
     *
     * @param string $name
     * @return BaseModule|null
     */
    public function getModule(string $name): ?BaseModule
    {
        return $this->modules[$name] ?? null;
    }

    /**
     * Get all registered modules
     *
     * @return array<string, BaseModule>
     */
    public function getAllModules(): array
    {
        return $this->modules;
    }

    /**
     * Get all modules as array for API response
     *
     * @return array
     */
    public function getModulesForApi(): array
    {
        return array_map(function (BaseModule $module) {
            return $module->toArray();
        }, $this->modules);
    }

    /**
     * Check if a module exists
     *
     * @param string $name
     * @return bool
     */
    public function hasModule(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    /**
     * Get functions for a specific module
     *
     * @param string $moduleName
     * @return array
     */
    public function getModuleFunctions(string $moduleName): array
    {
        $module = $this->getModule($moduleName);
        return $module ? $module->getFunctions() : [];
    }

    /**
     * Get system prompt for a specific module
     *
     * @param string $moduleName
     * @return string
     */
    public function getModulePrompt(string $moduleName): string
    {
        $module = $this->getModule($moduleName);
        return $module ? $module->getSystemPrompt() : '';
    }
}
