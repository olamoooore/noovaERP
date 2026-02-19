<?php

namespace Modules\AI\SubModules;

/**
 * Invoices Module
 * 
 * Handles invoice queries and payment information
 */
class InvoicesModule extends BaseModule
{
    /**
     * Get module name
     */
    public function getName(): string
    {
        return 'invoices';
    }
    
    /**
     * Get display name
     */
    public function getDisplayName(): string
    {
        return 'Invoices & Payments';
    }
    
    /**
     * Get module description
     */
    public function getDescription(): string
    {
        return 'View invoices, check payment status, and get billing details';
    }
    
    /**
     * Get module icon
     */
    public function getIcon(): string
    {
        return '💰';
    }
    
    /**
     * Get available functions for this module
     */
    public function getFunctions(): array
    {
        return [
            'get_invoice',
        ];
    }
    
    /**
     * Get module-specific system prompt
     */
    public function getSystemPrompt(): string
    {
        return <<<PROMPT
**INVOICES MODULE ACTIVE**

You are now in Invoices mode. Focus on:
- Retrieving invoice details
- Checking payment status
- Answering billing questions
- Providing invoice information

Available functions:
- get_invoice: Get invoice details by invoice number

Keep responses focused on invoices and payment information.
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
