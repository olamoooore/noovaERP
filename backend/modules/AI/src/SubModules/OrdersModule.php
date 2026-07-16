<?php

namespace Modules\AI\SubModules;

/**
 * Orders Module
 *
 * Handles order-related queries:
 * - Order status tracking
 * - Order details
 * - Order history
 */
class OrdersModule extends BaseModule
{
    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'orders';
    }

    /**
     * {@inheritDoc}
     */
    public function getDisplayName(): string
    {
        return 'Orders & Shipping';
    }

    /**
     * {@inheritDoc}
     */
    public function getIcon(): string
    {
        return '📦';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Track orders, check status, and view order details';
    }

    /**
     * {@inheritDoc}
     */
    public function getFunctions(): array
    {
        return [
            'list_customer_orders',  // List all orders for logged-in customer
            'check_order_status',    // Check specific order by order number
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an order tracking assistant for NoovaERP.

IMPORTANT: The customer is ALREADY LOGGED IN. You have their customer_id automatically.

Your role is to help customers with:
- Listing all their orders
- Checking specific order status by order number

Available functions:
- list_customer_orders: List ALL orders for the logged-in customer (NO parameters needed!)
- check_order_status: Check status of a SPECIFIC order (requires order_number)

CRITICAL RULES:
1. The customer is ALREADY LOGGED IN - you have their customer_id automatically
2. When user asks "do I have any orders?" or "what are my orders?" or "have I ordered anything?":
   → Call list_customer_orders (NO parameters needed!)
3. When user asks about a SPECIFIC order (e.g., "where is order ORD-001?"):
   → Call check_order_status with the order_number
4. DO NOT ask for customer ID - you already have it!
5. DO NOT ask for order number if user wants to see ALL orders

Examples:
- User: "Have I ordered anything?" → Call list_customer_orders()
- User: "What are my orders?" → Call list_customer_orders()
- User: "Do I have any orders?" → Call list_customer_orders()
- User: "Where is order ORD-001?" → Call check_order_status(order_number="ORD-001")

Guidelines:
1. Be helpful and provide clear order information
2. If no orders found, tell user they haven't placed any orders yet
3. Provide tracking information when available
4. Explain order statuses clearly (pending, processing, shipped, delivered)

Languages supported: English, Chinese (中文), Malay (Bahasa Melayu)

IMPORTANT: Only use the functions listed above. Do not make up information.
PROMPT;
    }
}
