<?php

namespace Modules\AI\SubModules;

class AccountingInsightsModule extends BaseModule
{
    public function getName(): string
    {
        return 'accounting_insights';
    }

    public function getDisplayName(): string
    {
        return 'Accounting Insights';
    }

    public function getDescription(): string
    {
        return 'AI-powered accounting analysis';
    }

    public function getIcon(): string
    {
        return '🔍';
    }

    public function getFunctions(): array
    {
        return [
            'detect_close_anomalies',
            'prioritize_collections',
            'suggest_ap_coding',
            'review_revenue',
        ];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
**ACCOUNTING INSIGHTS MODULE ACTIVE**

You are now in Accounting Insights mode. Your job is to:
- Detect anomalies and unusual patterns in journal entries
- Prioritize customers for collections outreach

Available functions:
- detect_close_anomalies: Detect anomalies and unusual balances in journal entries for a given period
- prioritize_collections: Rank customers by collections priority based on outstanding balances and overdue invoices
- suggest_ap_coding: Suggest account codes for AP invoices based on past posted OCR documents
- review_revenue: Review revenue invoices for anomalies and risks such as unusually large amounts, overdue balances, or draft invoices

Keep responses focused on insights and recommendations.
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
        ];
    }
}
