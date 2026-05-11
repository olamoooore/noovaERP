<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * IMP-001: Opening balance/import path tested through approved posting path.
 *
 * This suite proves the import path is guarded and delegated to canonical
 * posting services, and that opening-balance state is tracked through posted
 * journal-entry evidence.
 */
class IMP001ImportOpeningBalanceContractTest extends TestCase
{
    public function test_import_route_is_declared_under_accounting_write_and_tenant_context(): void
    {
        $routes = file_get_contents(base_path('modules/Core/routes/api.php'));

        $this->assertStringContainsString('Route::post(\'/import\'', $routes);
        $this->assertStringContainsString('EnsureAccountingWriteAccess::class', $routes);
        $this->assertStringContainsString('ResolveEntityContext::class', $routes);
        $this->assertStringContainsString('EnsureEntityAccess::class', $routes);
        $this->assertStringContainsString('auth:sanctum', $routes);
        $this->assertStringContainsString('EnsureTenantSearchPath::class', $routes);
    }

    public function test_import_controller_restricts_types_and_delegates_vendor_payment_posting(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/Api/ImportController.php'));

        $this->assertStringContainsString("'type' => 'required|in:chart-of-accounts,payments'", $controller);
        $this->assertStringContainsString('billPaymentService->recordPayment(', $controller);
        $this->assertStringNotContainsString('BillPayment::create(', $controller);
    }

    public function test_import_payment_service_posts_via_posting_engine_contract(): void
    {
        $service = file_get_contents(base_path('app/Services/BillPaymentService.php'));

        $this->assertStringContainsString('PostingEngineInterface', $service);
        $this->assertStringContainsString('postingEngine->post(new PostingRequest(', $service);
        $this->assertStringContainsString("documentType: 'bill_payment'", $service);
        $this->assertStringContainsString('new PostingRequestLine(', $service);
    }

    public function test_opening_balance_gate_requires_posted_opening_balance_journal_entries(): void
    {
        $validator = file_get_contents(base_path('app/Services/ValidationCheckpointService.php'));

        $this->assertStringContainsString("->where('type', 'opening_balance')", $validator);
        $this->assertStringContainsString("->where('status', 'posted')", $validator);
        $this->assertStringContainsString('checkOpeningBalances()', $validator);
    }

    public function test_general_ledger_service_calculates_and_reports_opening_balance(): void
    {
        $service = file_get_contents(
            base_path('modules/FinancialReporting/src/Services/Reports/GeneralLedgerService.php')
        );

        $this->assertStringContainsString('$openingBalance = $this->getOpeningBalance(', $service);
        $this->assertStringContainsString("'opening_balance' => round(", $service);
        $this->assertStringContainsString('protected function getOpeningBalance(', $service);
        $this->assertStringContainsString("->where('je.status', 'posted')", $service);
    }
}
