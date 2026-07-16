<?php

namespace Modules\AI\SubModules;

/**
 * Account Module
 *
 * Handles account information and profile queries
 */
class AccountModule extends BaseModule
{
    /**
     * Get module name
     */
    public function getName(): string
    {
        return 'account';
    }

    /**
     * Get display name
     */
    public function getDisplayName(): string
    {
        return 'My Account';
    }

    /**
     * Get module description
     */
    public function getDescription(): string
    {
        return 'View account details, update profile, and manage settings';
    }

    /**
     * Get module icon
     */
    public function getIcon(): string
    {
        return '👤';
    }

    /**
     * Get available functions for this module
     */
    public function getFunctions(): array
    {
        return [
            'search_customers',
        ];
    }

    /**
     * Get module-specific system prompt
     */
    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
**ACCOUNT MODULE ACTIVE**

You are now in Account mode. Focus on:
- Providing account information
- Answering profile-related questions
- Helping with account settings
- Customer information queries

Available functions:
- search_customers: Search for customer information

Keep responses focused on account and profile information.
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
