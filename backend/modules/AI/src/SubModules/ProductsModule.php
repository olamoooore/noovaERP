<?php

namespace Modules\AI\SubModules;

/**
 * Products Module
 *
 * Handles product search and information queries
 */
class ProductsModule extends BaseModule
{
    /**
     * Get module name
     */
    public function getName(): string
    {
        return 'products';
    }

    /**
     * Get display name
     */
    public function getDisplayName(): string
    {
        return 'Products & Catalog';
    }

    /**
     * Get module description
     */
    public function getDescription(): string
    {
        return 'Search products, check availability, and get product details';
    }

    /**
     * Get module icon
     */
    public function getIcon(): string
    {
        return '🛍️';
    }

    /**
     * Get available functions for this module
     */
    public function getFunctions(): array
    {
        return [
            'search_products',
            'get_product_details',
            'check_product_availability',
            'list_product_categories',
        ];
    }

    /**
     * Get module-specific system prompt
     */
    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
**PRODUCTS MODULE ACTIVE**

You are now in Products mode. Focus on:
- Searching for products by name, SKU, barcode, or description
- Checking product availability and stock levels
- Providing detailed product information
- Answering questions about product categories
- Helping customers find the right products

Available functions:
- search_products: Search for products by name, SKU, description, or category
- get_product_details: Get detailed information about a specific product
- check_product_availability: Check if a product is in stock and available
- list_product_categories: List all available product categories

When users ask about products:
1. Use search_products to find products matching their query
2. Use get_product_details to provide comprehensive product information
3. Use check_product_availability when asked about stock or availability
4. Present information clearly with prices, stock status, and relevant details

Keep responses focused on products and catalog information. Be helpful in guiding customers to find what they need.
PROMPT;
    }

    /**
     * Get module metadata
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'display_name' => $this->getDisplayName(),
            'description' => $this->getDescription(),
            'icon' => $this->getIcon(),
            'functions' => $this->getFunctions(),
        ];
    }
}
