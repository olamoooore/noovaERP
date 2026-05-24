<?php

namespace Modules\FinancialClose\Console\Commands;

use Illuminate\Console\Command;
use Modules\FinancialClose\Services\FinancialCloseAiAnalysisService;

class RunAiAnalysisCommand extends Command
{
    protected $signature = 'financial-close:run-ai-analysis
                            {--tenant= : Tenant UUID (defaults to current tenant)}
                            {--entity=entity-001 : Entity ID}
                            {--period= : Period YYYY-MM (defaults to current month)}';

    protected $description = 'Run AI anomaly analysis on accounting data and generate findings for Financial Close';

    public function handle(FinancialCloseAiAnalysisService $analysisService): int
    {
        $tenantId = $this->option('tenant') ?? tenant()?->getTenantKey();
        if (!$tenantId) {
            $this->error('No tenant specified and no active tenant context.');
            return 1;
        }

        $entityId = $this->option('entity');
        $periodId = $this->option('period') ?? now()->format('Y-m');

        $this->info("Running AI analysis for tenant: {$tenantId}, entity: {$entityId}, period: {$periodId}");

        $result = $analysisService->analyze($tenantId, $entityId, $periodId);

        $this->info("Analysis complete.");
        $this->info("  Accounts analyzed: {$result['accounts_analyzed']}");
        $this->info("  Findings created:  {$result['findings_created']}");

        return 0;
    }
}
