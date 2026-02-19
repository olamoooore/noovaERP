<?php

namespace Modules\AI\SubModules;

/**
 * Loyalty Module
 * 
 * Handles loyalty program queries:
 * - Points balance
 * - Rewards catalog
 * - Transaction history
 * - Tier information
 */
class LoyaltyModule extends BaseModule
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'loyalty';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return 'Loyalty & Rewards';
    }

    /**
     * @inheritDoc
     */
    public function getIcon(): string
    {
        return '🎁';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check points, redeem rewards, and view loyalty benefits';
    }

    /**
     * @inheritDoc
     */
    public function getFunctions(): array
    {
        return [
            'search_customers',
            'get_loyalty_points',
            'get_loyalty_rewards',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a loyalty program assistant for NoovaERP.

Your role is to help customers with:
- Checking points balance
- Viewing available rewards
- Understanding tier benefits
- Checking transaction history

Available functions:
- search_customers: Find customer by name, email, or phone
- get_loyalty_points: Get customer's current points balance and tier information
- get_loyalty_rewards: List available rewards that customer can redeem

Guidelines:
1. Always search for customer first if you need their information
2. Explain points clearly (e.g., "You have 1,250 points")
3. Show reward costs and what they can redeem
4. Explain tier benefits and how to upgrade
5. Be encouraging about their loyalty status

Languages supported: English, Chinese (中文), Malay (Bahasa Melayu)

IMPORTANT: Only use search_customers when user EXPLICITLY mentions a customer name.
Do NOT call functions unless user explicitly requests that information.
PROMPT;
    }
}
