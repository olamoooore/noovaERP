<?php

namespace Modules\AI\SubModules;

/**
 * Inventory Module
 * 
 * Handles inventory and stock queries
 */
class InventoryModule extends BaseModule
{
    public function getName(): string
    {
        return 'inventory';
    }

    public function getDisplayName(): string
    {
        return 'Inventory';
    }

    public function getDescription(): string
    {
        return 'Check stock levels, low stock items, warehouses, and recent stock movements';
    }

    public function getIcon(): string
    {
        return '📦';
    }

    public function getFunctions(): array
    {
        return [
            'check_inventory_stock',
            'list_low_stock_items',
            'list_out_of_stock_items',
            'list_warehouses',
            'list_recent_stock_movements',
        ];
    }

    public function getSystemPrompt(): string
    {
        return <<<PROMPT
**INVENTORY MODULE ACTIVE**

You are now in Inventory mode. Focus on:
- Checking stock levels and availability
- Listing low stock and out-of-stock items
- Listing warehouses
- Summarizing recent stock movements

Scope rules:
- Stay strictly within inventory topics (stock, warehouses, movements).
- Do not answer questions about orders, invoices, loyalty, accounts, or general ERP topics while this module is active.
- If the user asks something outside inventory, tell them to switch modules.

Available functions:
- check_inventory_stock: Check stock for a product by SKU or product_id (optionally by warehouse)
- list_low_stock_items: List items that are at/below their low-stock threshold
- list_out_of_stock_items: List items that are out of stock
- list_warehouses: List available warehouses
- list_recent_stock_movements: Show recent stock movements (optionally by warehouse)

Guidelines:
- Prefer using SKU when user provides a code.
- If the user asks for "in which warehouse" and none is specified, ask which warehouse or use the default warehouse.
- For inventory data questions (stock, low stock, out of stock, warehouses, movements), call the most relevant function rather than guessing.
- Keep answers short and data-driven.
PROMPT;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'display_name' => $this->getDisplayName(),
            'description' => $this->getDescription(),
            'icon' => $this->getIcon(),
            'functions' => $this->getFunctions(),
            'functions_count' => count($this->getFunctions()),
        ];
    }
}
