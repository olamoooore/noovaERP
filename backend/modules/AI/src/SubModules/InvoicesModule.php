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
            'get_outstanding_invoices',
            'get_invoice_details',
            'get_overdue_invoices',
            'get_recent_payments',
            'get_customer_balance',
            'get_revenue_summary',
            'get_journal_entries',
            'get_supplier_invoices',
            'get_financial_report',
            'get_customer_ledger',
            'get_supplier_ledger',
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
- get_outstanding_invoices: Get a list of outstanding or unpaid invoices
- get_invoice_details: Get full details of a specific invoice by invoice number
- get_overdue_invoices: Get a list of overdue invoices where the due date has passed and balance is still outstanding
- get_recent_payments: Get a list of recent payments received
- get_customer_balance: Get the outstanding balance for a specific customer by name or ID
- get_revenue_summary: Get a revenue summary for this month, this year, or a custom date range
- get_journal_entries: Retrieve recent journal entries from the general ledger, can filter by type and status
- get_supplier_invoices: Get a list of supplier bills or vendor invoices
- get_financial_report: Get a P&L summary with revenue, expenses and net income for a given period
- get_customer_ledger: Get a customer ledger showing invoice history and outstanding balance for a specific customer
- get_supplier_ledger: Get a supplier ledger showing bill history and total spending for a specific vendor

When displaying invoice results:
- Always list invoices in ascending order by invoice number
- Show each invoice individually with its number, total, paid amount, and balance
- Do not calculate or provide a total summary unless the user explicitly asks for one

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
