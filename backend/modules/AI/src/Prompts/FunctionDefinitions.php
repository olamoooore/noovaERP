<?php

namespace Modules\AI\Prompts;

/**
 * Function Definitions
 * 
 * Defines all AI-callable functions
 */
class FunctionDefinitions
{
    public static function getAll(): array
    {
        // Re-enabled for 3B model with proper architecture
        // LLM = Intent recognition + Structured calling
        // LLM ≠ Knowledge source (data comes from DB via functions)
        return [
            self::searchCustomers(),
            self::checkOrderStatus(),
            self::listCustomerOrders(),  // NEW: List all orders for logged-in customer
            self::getLoyaltyPoints(),
            self::getLoyaltyRewards(),
            // New Loyalty Functions
            self::checkLoyaltyPoints(),
            self::getLoyaltyProgramDetails(),
            self::getCustomerTierInfo(),
            self::listAvailableRewards(),
            self::checkRedemptionHistory(),
            self::calculatePointsForPurchase(),
            self::getTierBenefits(),
            self::checkNextTierProgress(),
            // Product Functions
            self::searchProducts(),
            self::getProductDetails(),
            self::checkProductAvailability(),
            self::listProductCategories(),
            // Inventory Functions
            self::checkInventoryStock(),
            self::listLowStockItems(),
            self::listOutOfStockItems(),
            self::listWarehouses(),
            self::listRecentStockMovements(),
            // Other Functions
            self::getInvoice(),
            self::getOutstandingInvoices(),
            self::getInvoiceDetails(),
            self::getOverdueInvoices(),
            self::getRecentPayments(),
            self::getCustomerBalance(),
            self::getRevenueSummary(),
            self::getJournalEntries(),
            self::getSupplierInvoices(),
            self::getFinancialReport(),
            self::getCustomerLedger(),
            self::getSupplierLedger(),
            self::createTicket(),
        ];
    }

    protected static function checkInventoryStock(): array
    {
        return [
            'name' => 'check_inventory_stock',
            'description' => 'Check inventory stock for a product by SKU or product_id. Optionally check within a specific warehouse. Use when user asks about stock levels, inventory quantity, or availability in a warehouse.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => [
                        'type' => 'integer',
                        'description' => 'Product ID (if known)',
                    ],
                    'sku' => [
                        'type' => 'string',
                        'description' => 'Product SKU code (if user provides a SKU)',
                    ],
                    'warehouse_id' => [
                        'type' => 'integer',
                        'description' => 'Optional warehouse ID to check stock in a specific warehouse',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    protected static function listLowStockItems(): array
    {
        return [
            'name' => 'list_low_stock_items',
            'description' => 'List items that are low on stock. Optionally filter by a specific warehouse. Use when user asks "what is low stock" or "which items need reorder".',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'warehouse_id' => [
                        'type' => 'integer',
                        'description' => 'Optional warehouse ID',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max results (default: 10, max: 50)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    protected static function listOutOfStockItems(): array
    {
        return [
            'name' => 'list_out_of_stock_items',
            'description' => 'List items that are out of stock. Optionally filter by a specific warehouse.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'warehouse_id' => [
                        'type' => 'integer',
                        'description' => 'Optional warehouse ID',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max results (default: 10, max: 50)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    protected static function listWarehouses(): array
    {
        return [
            'name' => 'list_warehouses',
            'description' => 'List active warehouses (including default warehouse). Use when user asks about warehouses or wants stock by warehouse.',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ];
    }

    protected static function listRecentStockMovements(): array
    {
        return [
            'name' => 'list_recent_stock_movements',
            'description' => 'List recent stock movements (stock in/out/adjustments). Optionally filter by warehouse.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'warehouse_id' => [
                        'type' => 'integer',
                        'description' => 'Optional warehouse ID',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max results (default: 10, max: 50)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }
    
    protected static function searchCustomers(): array
    {
        return [
            'name' => 'search_customers',
            'description' => 'Search for customers by name, email, or phone number. Use this FIRST when user mentions a customer name to get their customer_id.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Customer name, email, or phone to search for',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }
    
    protected static function checkOrderStatus(): array
    {
        return [
            'name' => 'check_order_status',
            'description' => 'Check order status by order number',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'order_number' => [
                        'type' => 'string',
                        'description' => 'Order number (e.g., ORD-001)',
                    ],
                ],
                'required' => ['order_number'],
            ],
        ];
    }
    
    protected static function listCustomerOrders(): array
    {
        return [
            'name' => 'list_customer_orders',
            'description' => 'List ALL orders/invoices for the logged-in customer. Use this when customer asks about THEIR orders WITHOUT specifying a specific order number. Examples: "how many orders?", "how many invoices?", "i got how many invoice", "do I have any orders?", "show me my orders", "list my orders", "what are my orders?". NO parameters needed - customer is already logged in. DO NOT use get_invoice or check_order_status for these questions!',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(), // Empty object for no parameters
                'required' => [],
            ],
        ];
    }
    
    protected static function getLoyaltyPoints(): array
    {
        return [
            'name' => 'get_loyalty_points',
            'description' => 'Get loyalty points and tier for a customer. Use when asking about points or tier. Examples: "how many points does Jane Smith have?", "what\'s John\'s tier?", "check customer loyalty". Provide customer_name if asking about specific customer.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'Customer name to search for (optional). If not provided, uses logged-in customer.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }
    
    protected static function getLoyaltyRewards(): array
    {
        return [
            'name' => 'get_loyalty_rewards',
            'description' => 'Get available rewards that a customer can redeem. Use when asking about rewards. Examples: "what rewards can Jane Smith get?", "what can John redeem?", "show rewards". Provide customer_name if asking about specific customer.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'Customer name to search for (optional). If not provided, uses logged-in customer.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }
    
    protected static function searchProducts(): array
    {
        return [
            'name' => 'search_products',
            'description' => 'Search products by name, SKU, barcode, or description. Use when customer wants to find products. Examples: "search for laptop", "do you have iPhone?", "show me phones", "find SKU-123"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Product name, SKU, barcode, or search keywords',
                    ],
                    'category_id' => [
                        'type' => 'integer',
                        'description' => 'Optional category ID to filter results',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results (default: 10, max: 50)',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }
    
    protected static function getProductDetails(): array
    {
        return [
            'name' => 'get_product_details',
            'description' => 'Get detailed information about a specific product including price, description, stock, variants. Use when customer asks for details about a product they already found or know. Examples: "tell me more about product ID 5", "details for SKU-123", "what is the price of this item?"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => [
                        'type' => 'integer',
                        'description' => 'Product ID (use if known from previous search)',
                    ],
                    'sku' => [
                        'type' => 'string',
                        'description' => 'Product SKU code (use if customer mentions SKU)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }
    
    protected static function checkProductAvailability(): array
    {
        return [
            'name' => 'check_product_availability',
            'description' => 'Check if a product is in stock and available for purchase. Use when customer asks about availability or stock status. Examples: "is this in stock?", "do you have 5 units available?", "can I buy 10 pieces?"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => [
                        'type' => 'integer',
                        'description' => 'Product ID',
                    ],
                    'sku' => [
                        'type' => 'string',
                        'description' => 'Product SKU code',
                    ],
                    'quantity' => [
                        'type' => 'integer',
                        'description' => 'Desired quantity to check (default: 1)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }
    
    protected static function listProductCategories(): array
    {
        return [
            'name' => 'list_product_categories',
            'description' => 'List all available product categories in the catalog. Use when customer wants to browse categories or asks what types of products are available. Examples: "what categories do you have?", "show me product types", "what can I buy?"',
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(), // Empty object for no parameters
                'required' => [],
            ],
        ];
    }
    
    protected static function getInvoice(): array
    {
        return [
            'name' => 'get_invoice',
            'description' => 'Get details of a SPECIFIC invoice by invoice number. ONLY use this when user provides a complete invoice number like "INV-001" or "INV-20251229-003". DO NOT use this for questions like "how many invoices" or "show my invoices" - use list_customer_orders instead!',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'invoice_number' => [
                        'type' => 'string',
                        'description' => 'Complete invoice number (e.g., INV-001, INV-20251229-003)',
                    ],
                ],
                'required' => ['invoice_number'],
            ],
        ];
    }

    protected static function getOutstandingInvoices(): array
    {
        return [
            'name' => 'get_outstanding_invoices',
            'description' => 'Get a list of outstanding or unpaid invoices. Use this when the user asks about unpaid invoices, outstanding invoices, invoices pending payment, or how much is owed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of invoices to return (default: 10)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    protected static function getInvoiceDetails(): array
    {
        return [
            'name' => 'get_invoice_details',
            'description' => 'Get full details of a specific invoice by invoice number. Use this when the user asks about a specific invoice, wants to see invoice details, or asks about a particular invoice number.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'invoice_number' => [
                        'type' => 'string',
                        'description' => 'The invoice number to look up (e.g. INV-2026-0001)',
                    ],
                ],
                'required' => ['invoice_number'],
            ],
        ];
    }

    protected static function getOverdueInvoices(): array
    {
        return [
            'name' => 'get_overdue_invoices',
            'description' => 'Get a list of overdue invoices where the due date has passed and there is still an outstanding balance. Use this when the user asks about overdue invoices, late payments, or past due invoices.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of invoices to return (default: 10)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    protected static function getRecentPayments(): array
    {
        return [
            'name' => 'get_recent_payments',
            'description' => 'Get a list of recent payments received. Use this when the user asks about recent payments, payment history, or payments received.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of payments to return (default: 10)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    protected static function getCustomerBalance(): array
    {
        return [
            'name' => 'get_customer_balance',
            'description' => 'Get the outstanding balance for a specific customer. Use this when the user asks about a customer\'s balance, how much a customer owes, or a customer\'s outstanding amount.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => [
                        'type' => 'integer',
                        'description' => 'The ID of the customer',
                    ],
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'The name of the customer to search for',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    protected static function getRevenueSummary(): array
    {
        return [
            'name' => 'get_revenue_summary',
            'description' => 'Get a revenue summary for a given period. Use this when the user asks about revenue, total sales, income summary, or financial performance.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'period' => [
                        'type' => 'string',
                        'description' => 'Period to summarise: this_month (default) or this_year',
                    ],
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'Start date for custom range (YYYY-MM-DD)',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'End date for custom range (YYYY-MM-DD)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    protected static function getJournalEntries(): array
    {
        return [
            'name' => 'get_journal_entries',
            'description' => 'Retrieves recent journal entries from the general ledger. Use this when the user asks about journal entries, GL entries, or posted transactions.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'Filter by journal entry type e.g. payment, manual.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Filter by status e.g. posted, draft.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public static function getSupplierInvoices(): array
    {
        return [
            'name' => 'get_supplier_invoices',
            'description' => 'Get a list of supplier bills/invoices. Use this when the user asks about supplier bills, vendor invoices, bills to pay, or AP (accounts payable). Present results as a numbered list showing bill number, vendor ID, total, balance, and due date.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'description' => 'Optional filter by status, e.g. unpaid, partial, paid',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public static function getFinancialReport(): array
    {
        return [
            'name' => 'get_financial_report',
            'description' => 'Get a P&L summary showing total revenue, expenses and net income for a period. Use when user asks about profit and loss, financial performance, income summary, or revenue vs expenses.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'period' => [
                        'type' => 'string',
                        'description' => 'Reporting period: this_month, last_month, or this_year. Defaults to this_month.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public static function getCustomerLedger(): array
    {
        return [
            'name' => 'get_customer_ledger',
            'description' => 'Get a customer ledger showing their invoice history and outstanding balance. Use when the user asks about a specific customer ledger, customer account, or customer invoice history.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'Name of the customer to look up',
                    ],
                    'customer_id' => [
                        'type' => 'integer',
                        'description' => 'ID of the customer to look up',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public static function getSupplierLedger(): array
    {
        return [
            'name' => 'get_supplier_ledger',
            'description' => 'Get a supplier ledger showing their bill history and total spending. Use when the user asks about a specific supplier or vendor ledger, vendor account, or vendor bill history.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'vendor_name' => [
                        'type' => 'string',
                        'description' => 'Name of the vendor to look up',
                    ],
                    'vendor_id' => [
                        'type' => 'string',
                        'description' => 'ID of the vendor to look up',
                    ],
                ],
                'required' => [],
            ],
        ];
    }
    
    protected static function createTicket(): array
    {
        return [
            'name' => 'create_ticket',
            'description' => 'Create a support ticket when the issue cannot be resolved by AI. Use this when: 1) Cannot find requested information, 2) User explicitly requests human support, 3) Issue is too complex for AI to handle',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'subject' => [
                        'type' => 'string',
                        'description' => 'Brief summary of the issue (max 255 chars)',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Detailed description of the problem',
                    ],
                    'priority' => [
                        'type' => 'string',
                        'enum' => ['low', 'medium', 'high', 'urgent'],
                        'description' => 'Ticket priority level',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Issue category (orders, loyalty, products, invoices, account, other)',
                    ],
                ],
                'required' => ['subject', 'description'],
            ],
        ];
    }
    
    protected static function transferToHuman(): array
    {
        return [
            'name' => 'transfer_to_human',
            'description' => 'Transfer to human agent',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Reason for transfer',
                    ],
                ],
                'required' => ['reason'],
            ],
        ];
    }
    
    // ========================================
    // New Loyalty Functions
    // ========================================
    
    protected static function checkLoyaltyPoints(): array
    {
        return [
            'name' => 'check_loyalty_points',
            'description' => 'Check a customer\'s loyalty points balance, lifetime points, and current tier. Use when asking "how many points do I have?", "what are my points?", "check my loyalty balance"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => [
                        'type' => 'integer',
                        'description' => 'Customer ID to check (required)',
                    ],
                ],
                'required' => ['customer_id'],
            ],
        ];
    }
    
    protected static function getLoyaltyProgramDetails(): array
    {
        return [
            'name' => 'get_loyalty_program_details',
            'description' => 'Get loyalty program information including how to join, earning rates, and expiry rules. Use when asking "how does the loyalty program work?", "how do I join?", "what are the benefits?"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'program_id' => [
                        'type' => 'integer',
                        'description' => 'Program ID (optional, uses active program if not provided)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }
    
    protected static function getCustomerTierInfo(): array
    {
        return [
            'name' => 'get_customer_tier_info',
            'description' => 'Get customer\'s current tier, benefits, multiplier, discount, and next tier information. Use when asking "what tier am I?", "what are my tier benefits?", "when do I reach the next tier?"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => [
                        'type' => 'integer',
                        'description' => 'Customer ID (required)',
                    ],
                ],
                'required' => ['customer_id'],
            ],
        ];
    }
    
    protected static function listAvailableRewards(): array
    {
        return [
            'name' => 'list_available_rewards',
            'description' => 'List rewards that can be redeemed. Optionally filter to show only rewards the customer can afford. Use when asking "what rewards can I get?", "show available rewards", "what can I redeem?"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => [
                        'type' => 'integer',
                        'description' => 'Customer ID (optional, filters to affordable rewards if provided)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max number of rewards to return (default 10)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }
    
    protected static function checkRedemptionHistory(): array
    {
        return [
            'name' => 'check_redemption_history',
            'description' => 'Get customer\'s past redemptions and rewards claimed. Use when asking "what have I redeemed?", "show my redemption history", "what rewards did I get?"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => [
                        'type' => 'integer',
                        'description' => 'Customer ID (required)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max number of redemptions to return (default 5)',
                    ],
                ],
                'required' => ['customer_id'],
            ],
        ];
    }
    
    protected static function calculatePointsForPurchase(): array
    {
        return [
            'name' => 'calculate_points_for_purchase',
            'description' => 'Calculate how many points a customer would earn from a purchase amount. Applies tier multiplier. Use when asking "how many points will I earn?", "if I spend $100 how many points?"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'amount' => [
                        'type' => 'number',
                        'description' => 'Purchase amount (required)',
                    ],
                    'customer_id' => [
                        'type' => 'integer',
                        'description' => 'Customer ID (optional, to apply tier multiplier)',
                    ],
                ],
                'required' => ['amount'],
            ],
        ];
    }
    
    protected static function getTierBenefits(): array
    {
        return [
            'name' => 'get_tier_benefits',
            'description' => 'List all loyalty tiers (Bronze, Silver, Gold) with their point thresholds, multipliers, discounts, and benefits. Use when asking "what are the tiers?", "what do I get at each level?", "show tier benefits"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'program_id' => [
                        'type' => 'integer',
                        'description' => 'Program ID (optional, uses active program if not provided)',
                    ],
                ],
                'required' => [],
            ],
        ];
    }
    
    protected static function checkNextTierProgress(): array
    {
        return [
            'name' => 'check_next_tier_progress',
            'description' => 'Check customer\'s progress toward the next tier level. Shows points needed and percentage complete. Use when asking "how close am I to the next tier?", "how many points until Silver?"',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => [
                        'type' => 'integer',
                        'description' => 'Customer ID (required)',
                    ],
                ],
                'required' => ['customer_id'],
            ],
        ];
    }
}
