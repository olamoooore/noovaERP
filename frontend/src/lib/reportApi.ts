import api from './api';
import type {
  ReportFilters,
  ProfitLossData,
  BalanceSheetData,
  CashFlowData,
  TrialBalanceData,
  GeneralLedgerData,
  StockValuationData,
  StockMovementData,
  ReorderData,
  InventoryAgingData,
  InventoryTurnoverData,
  SalesRecordsData,
  SalesRecordsFilters,
  SalesByProductData,
  SalesByCustomerData,
  SalesTrendsData,
  SalesByCategoryData,
  SalesReconciliationData,
  SalesPivotData,
  UnbilledDeliveriesData,
  SalesDrilldownData,
  SalesDrilldownTarget,
  CreditNoteAnalysisData,
  CreditNoteGrouping,
  MedicalCasePerformanceData,
  MedicalCaseDateField,
  PivotDimension,
  PivotMeasure,
  ExpensesByCategoryData,
  ExpensesByVendorData,
  ExpensesByDepartmentData,
  CustomerLifetimeValueData,
  CustomerPurchasePatternsData,
  CustomerSegmentationData,
  ExecutiveDashboardData,
  SalesDashboardData,
  InventoryDashboardData,
  FinancialDashboardData,
  AgedReceivablesData,
  AgedPayablesData,
  BankAccountsData,
  ReconciliationTransactionsData,
  ReconciliationCompleteRequest,
  AddTransactionRequest,
  ReconciliationTransaction,
  ExportRequest,
  ExportResponse,
  ApiResponse,
} from '../types/reports';

/**
 * Report API Client
 * Provides methods to interact with all financial reporting, inventory, and analytics endpoints
 */
/**
 * #352 (S4): format the LOCAL calendar date as YYYY-MM-DD.
 *
 * `toISOString()` converts to UTC first, so a date built at local midnight in
 * a UTC+ timezone (every MY/SG tenant) was serialised as the previous day —
 * "this month" ran 2026-09-30 → 2026-10-30, including September's last day and
 * dropping 31 October. Reading the local calendar fields is timezone-safe.
 */
export function formatLocalDate(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

class ReportApiClient {
  // ==================== Financial Reports ====================

  /**
   * Get Profit & Loss Statement
   */
  async getProfitLoss(filters: ReportFilters): Promise<ProfitLossData> {
    try {
      const response = await api.get<ApiResponse<ProfitLossData>>(
        '/reports/financial/profit-loss',
        { params: filters }
      );
      console.log('P&L API Response:', response.data);
      console.log('P&L Data:', response.data.data);
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Profit & Loss report');
      throw error;
    }
  }

  /**
   * Get Balance Sheet
   */
  async getBalanceSheet(filters: ReportFilters): Promise<BalanceSheetData> {
    try {
      // Balance Sheet requires as_of_date
      // If as_of_date is provided directly, use it
      // Otherwise, use end_date or start_date as fallback
      const params: any = {
        ...filters,
      };
      
      // Ensure as_of_date is set
      if (!params.as_of_date) {
        params.as_of_date = params.end_date || params.start_date;
      }
      
      // Remove start_date and end_date as they're not used for Balance Sheet
      delete params.start_date;
      delete params.end_date;
      
      console.log('Balance Sheet API Request:', {
        url: '/reports/financial/balance-sheet',
        params,
      });
      
      const response = await api.get<ApiResponse<BalanceSheetData>>(
        '/reports/financial/balance-sheet',
        { params }
      );
      
      console.log('Balance Sheet API Response:', {
        status: response.status,
        data: response.data,
      });
      
      return response.data.data;
    } catch (error) {
      console.error('Balance Sheet API Error:', error);
      this.handleError(error, 'Failed to fetch Balance Sheet report');
      throw error;
    }
  }

  /**
   * Get Cash Flow Statement
   */
  async getCashFlow(filters: ReportFilters): Promise<CashFlowData> {
    try {
      const response = await api.get<ApiResponse<CashFlowData>>(
        '/reports/financial/cash-flow',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Cash Flow report');
      throw error;
    }
  }

  /**
   * Get Trial Balance
   */
  async getTrialBalance(filters: ReportFilters): Promise<TrialBalanceData> {
    try {
      // Trial Balance requires as_of_date
      // If as_of_date is provided directly, use it
      // Otherwise, use end_date or start_date as fallback
      const params: any = {
        ...filters,
      };
      
      // Ensure as_of_date is set
      if (!params.as_of_date) {
        params.as_of_date = params.end_date || params.start_date;
      }
      
      // Remove start_date and end_date as they're not used for Trial Balance
      delete params.start_date;
      delete params.end_date;
      
      console.log('Trial Balance API Request:', {
        url: '/reports/financial/trial-balance',
        params,
      });
      
      const response = await api.get<ApiResponse<TrialBalanceData>>(
        '/reports/financial/trial-balance',
        { params }
      );
      
      console.log('Trial Balance API Response:', {
        status: response.status,
        data: response.data,
      });
      
      return response.data.data;
    } catch (error) {
      console.error('Trial Balance API Error:', error);
      this.handleError(error, 'Failed to fetch Trial Balance report');
      throw error;
    }
  }

  /**
   * Get General Ledger
   */
  async getGeneralLedger(filters: ReportFilters): Promise<GeneralLedgerData> {
    try {
      const response = await api.get<ApiResponse<GeneralLedgerData>>(
        '/reports/financial/general-ledger',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch General Ledger report');
      throw error;
    }
  }

  /**
   * Get Account Statement
   */
  async getAccountStatement(
    accountId: number,
    filters: ReportFilters
  ): Promise<GeneralLedgerData> {
    try {
      const response = await api.get<ApiResponse<GeneralLedgerData>>(
        `/reports/financial/account-statement/${accountId}`,
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Account Statement');
      throw error;
    }
  }

  /**
   * Get Aged Receivables Report
   */
  async getAgedReceivables(filters?: ReportFilters): Promise<AgedReceivablesData> {
    try {
      const response = await api.get<ApiResponse<AgedReceivablesData>>(
        '/accounting/reports/aged-receivables',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Aged Receivables report');
      throw error;
    }
  }

  /**
   * Get Aged Payables Report
   */
  async getAgedPayables(filters?: ReportFilters): Promise<AgedPayablesData> {
    try {
      const response = await api.get<ApiResponse<AgedPayablesData>>(
        '/accounting/reports/aged-payables',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Aged Payables report');
      throw error;
    }
  }

  // ==================== Bank Reconciliation ====================

  /**
   * Get Bank Accounts for Reconciliation
   */
  async getBankAccounts(): Promise<BankAccountsData> {
    try {
      const response = await api.get<{ data: any[] }>(
        '/accounting/reconciliation/bank-accounts'
      );
      // Backend returns { data: [...] }, wrap it in BankAccountsData format
      return { data: response.data.data || [] };
    } catch (error) {
      this.handleError(error, 'Failed to fetch bank accounts');
      throw error;
    }
  }

  /**
   * Get Unreconciled Transactions for a Bank Account
   */
  async getReconciliationTransactions(
    accountId: number,
    filters?: { status?: string; search?: string }
  ): Promise<ReconciliationTransactionsData> {
    try {
      const response = await api.get(
        `/accounting/reconciliation/transactions/${accountId}`,
        { params: filters }
      );
      // Backend returns { data: [...], opening_balance, bank_account_id, gl_account_id } directly
      return response.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch reconciliation transactions');
      throw error;
    }
  }

  /**
   * Complete Bank Reconciliation
   */
  async completeReconciliation(
    request: ReconciliationCompleteRequest
  ): Promise<{ success: boolean; message: string }> {
    try {
      const response = await api.post<ApiResponse<{ success: boolean; message: string }>>(
        '/accounting/reconciliation/complete',
        request
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to complete reconciliation');
      throw error;
    }
  }

  /**
   * Add Adjusting Entry Transaction
   */
  async addAdjustingEntry(
    request: AddTransactionRequest
  ): Promise<ReconciliationTransaction> {
    try {
      const response = await api.post<ApiResponse<ReconciliationTransaction>>(
        '/accounting/reconciliation/add-transaction',
        request
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to add adjusting entry');
      throw error;
    }
  }

  async getReconciliationHistory(bankAccountId: number): Promise<{ data: any[] }> {
    try {
      const response = await api.get<{ data: any[] }>(
        `/accounting/reconciliation/history/${bankAccountId}`
      );
      return { data: response.data.data || [] };
    } catch (error) {
      this.handleError(error, 'Failed to fetch reconciliation history');
      throw error;
    }
  }

  async reverseStatementReconciliation(reconciliationId: number, reason: string): Promise<any> {
    const response = await api.post(`/accounting/reconciliation/statement-reconciliations/${reconciliationId}/reverse`, { reason });
    return response.data.data;
  }

  async approveStatementReconciliationReversal(reversalId: string): Promise<any> {
    const response = await api.post(`/accounting/reconciliation/statement-reconciliation-reversals/${reversalId}/approve`);
    return response.data.data;
  }

  async previewBankStatement(file: File, bankAccountId: number, headerRow = 1): Promise<any> {
    const body = new FormData();
    body.append('file', file);
    body.append('bank_account_id', String(bankAccountId));
    body.append('header_row', String(headerRow));
    const response = await api.post('/accounting/reconciliation/statement-imports/preview', body, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data.data;
  }

  async confirmBankStatementImport(request: {
    preview_token: string;
    mapping: Record<string, string>;
    currency_code: string;
    statement_start_date?: string;
    statement_end_date: string;
    opening_balance?: number;
    closing_balance: number;
    date_format?: string;
    import_duplicates?: boolean;
  }): Promise<any> {
    const response = await api.post('/accounting/reconciliation/statement-imports', request);
    return response.data.data;
  }

  async getBankStatementImports(bankAccountId: number): Promise<any> {
    const response = await api.get('/accounting/reconciliation/statement-imports', { params: { bank_account_id: bankAccountId } });
    return response.data;
  }

  async getBankStatementTransactions(bankAccountId: number, importId?: string, page = 1, perPage = 20, status?: string, search?: string, transactionIds?: string[]): Promise<any> {
    const response = await api.get('/accounting/reconciliation/statement-transactions', {
      params: { bank_account_id: bankAccountId, import_id: importId, page, per_page: perPage, status, search, transaction_ids: transactionIds },
    });
    return response.data;
  }

  async createBankStatementMatch(request: {
    bank_statement_transaction_id: string;
    journal_entry_line_id: number;
    matched_amount: number;
    idempotency_key: string;
    review_reason: string;
  }): Promise<any> {
    const response = await api.post('/accounting/reconciliation/statement-matches', request);
    return response.data.data;
  }

  async removeBankStatementMatch(matchId: string): Promise<void> {
    await api.delete(`/accounting/reconciliation/statement-matches/${matchId}`);
  }

  async suggestBankStatementMatches(importId: string): Promise<any> {
    const response = await api.post(`/accounting/reconciliation/statement-imports/${importId}/suggest-matches`);
    return response.data.data;
  }

  async excludeBankStatementTransaction(transactionId: string, request: { type: 'duplicate' | 'non_transaction'; reason: string; evidence: Record<string, string> }): Promise<any> {
    const response = await api.post(`/accounting/reconciliation/statement-transactions/${transactionId}/exclude`, request);
    return response.data.data;
  }

  async restoreBankStatementTransaction(transactionId: string, reason: string): Promise<any> {
    const response = await api.post(`/accounting/reconciliation/statement-transactions/${transactionId}/restore`, { reason });
    return response.data.data;
  }

  async downloadBankStatementSource(importId: string): Promise<Blob> {
    const response = await api.get(`/accounting/reconciliation/statement-imports/${importId}/source`, { responseType: 'blob' });
    return response.data;
  }

  async reverseBankStatementImport(importId: string, reason: string): Promise<any> {
    const response = await api.post(`/accounting/reconciliation/statement-imports/${importId}/reverse`, { reason });
    return response.data.data;
  }

  async validateBankStatementReconciliation(bankAccountId: number, importIds: string[]): Promise<any> {
    const response = await api.post('/accounting/reconciliation/statement-reconciliations/validate', {
      bank_account_id: bankAccountId,
      import_ids: importIds,
    });
    return response.data.data;
  }

  async completeBankStatementReconciliation(bankAccountId: number, importIds: string[]): Promise<any> {
    const response = await api.post('/accounting/reconciliation/statement-reconciliations/complete', {
      bank_account_id: bankAccountId,
      import_ids: importIds,
      idempotency_key: crypto.randomUUID(),
    });
    return response.data.data;
  }

  async bulkAcceptBankStatementMatches(importId: string): Promise<any> {
    const response = await api.post('/accounting/reconciliation/statement-matches/bulk-accept', { import_id: importId, idempotency_key: crypto.randomUUID() });
    return response.data.data;
  }

  async proposeBankStatementAdjustment(transactionId: string, request: { contra_account_id: number; amount: number; description: string; reason: string }): Promise<any> {
    const response = await api.post(`/accounting/reconciliation/statement-transactions/${transactionId}/adjustment`, { ...request, idempotency_key: crypto.randomUUID() });
    return response.data.data;
  }

  async approveBankStatementAdjustment(adjustmentId: string): Promise<any> {
    const response = await api.post(`/accounting/reconciliation/statement-adjustments/${adjustmentId}/approve`);
    return response.data.data;
  }

  async createGroupedBankStatementMatches(allocations: Array<{ bank_statement_transaction_id: string; journal_entry_line_id: number; matched_amount: number; review_reason: string }>): Promise<any> {
    const response = await api.post('/accounting/reconciliation/statement-matches/group', { allocations, idempotency_key: crypto.randomUUID() });
    return response.data.data;
  }

  // ==================== Inventory Reports ====================

  /**
   * Get Stock Valuation Report
   */
  async getStockValuation(filters: ReportFilters): Promise<StockValuationData> {
    try {
      const response = await api.get<ApiResponse<StockValuationData>>(
        '/reports/inventory/valuation',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Stock Valuation report');
      throw error;
    }
  }

  /**
   * Get Stock Movement Report
   */
  async getStockMovement(filters: ReportFilters): Promise<StockMovementData> {
    try {
      const response = await api.get<ApiResponse<StockMovementData>>(
        '/reports/inventory/movement',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Stock Movement report');
      throw error;
    }
  }

  /**
   * #1020: the movement endpoint is server-paginated (50 per page, max 100);
   * the report screen and its exports need every movement in the range, so
   * walk all pages and concatenate them. The summary is computed server-side
   * over the whole range and is taken from the first page.
   */
  async getAllStockMovements(filters: ReportFilters): Promise<StockMovementData> {
    const perPage = 100;
    const first = await this.getStockMovement({ ...filters, page: 1, per_page: perPage });
    const lastPage = Number(first?.pagination?.last_page ?? first?.pagination?.total_pages ?? 1);
    const movements = [...(first?.movements ?? [])];
    for (let page = 2; page <= lastPage; page += 1) {
      const next = await this.getStockMovement({ ...filters, page, per_page: perPage });
      movements.push(...(next?.movements ?? []));
    }
    return { ...first, movements };
  }

  /**
   * Get Reorder Report
   */
  async getReorderReport(filters?: ReportFilters): Promise<ReorderData> {
    try {
      const response = await api.get<ApiResponse<ReorderData>>(
        '/reports/inventory/reorder',
        { params: filters }
      );
      // The endpoint returns a ReportData envelope ({ data: rows }); map the
      // service rows to the table shape (#1020 product name + short description).
      const payload = response.data.data as unknown as { data?: any[]; items?: any[] };
      const rows = Array.isArray(payload?.data) ? payload.data : (payload?.items ?? []);
      return {
        items: rows.map((row: any) => ({
          product_id: row.product_id ?? row.id,
          product_name: row.product_name ?? row.name,
          short_description: row.short_description ?? null,
          sku: row.sku,
          current_stock: row.current_stock,
          reorder_level: row.reorder_level,
          effective_threshold: row.effective_threshold ?? null,
          suggested_quantity: row.suggested_quantity ?? row.suggested_order_quantity,
          supplier_name: row.supplier_name ?? row.last_supplier ?? undefined,
          last_purchase_price: row.last_purchase_price,
        })),
      };
    } catch (error) {
      this.handleError(error, 'Failed to fetch Reorder report');
      throw error;
    }
  }

  /**
   * Get Inventory Aging Report
   */
  async getInventoryAging(filters: ReportFilters): Promise<InventoryAgingData> {
    try {
      const response = await api.get<ApiResponse<InventoryAgingData>>(
        '/reports/inventory/aging',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Inventory Aging report');
      throw error;
    }
  }

  /**
   * Get Inventory Turnover Report
   */
  async getInventoryTurnover(filters: ReportFilters): Promise<InventoryTurnoverData> {
    try {
      const response = await api.get<ApiResponse<InventoryTurnoverData>>(
        '/reports/inventory/turnover',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Inventory Turnover report');
      throw error;
    }
  }

  // ==================== Sales Analytics ====================

  /**
   * Issue #593: the sales endpoints answer 422 for query keys they don't
   * take, and only `/sales/trends` accepts `interval`. Every caller shares
   * one filters object, so drop the key this endpoint doesn't understand
   * instead of letting the report 422 on an unrelated filter.
   */
  private salesParams(filters: ReportFilters): ReportFilters {
    const rest: ReportFilters = { ...filters };
    delete rest.interval;
    return rest;
  }

  /**
   * Get Sales by Product
   */
  async getSalesByProduct(filters: ReportFilters): Promise<SalesByProductData> {
    try {
      const response = await api.get<ApiResponse<SalesByProductData>>(
        '/analytics/sales/by-product',
        { params: this.salesParams(filters) }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales by Product');
      throw error;
    }
  }

  /**
   * #1020: Sales Records — the client's sheet layout (invoice rows with
   * Qty/S$ column pairs for item code/brand/supplier/customer/category).
   */
  async getSalesRecords(filters: SalesRecordsFilters): Promise<SalesRecordsData> {
    try {
      const response = await api.get<ApiResponse<SalesRecordsData>>(
        '/reports/sales-records',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales Records');
      throw error;
    }
  }

  /**
   * Get Sales by Customer
   */
  async getSalesByCustomer(filters: ReportFilters): Promise<SalesByCustomerData> {
    try {
      const response = await api.get<ApiResponse<SalesByCustomerData>>(
        '/analytics/sales/by-customer',
        { params: this.salesParams(filters) }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales by Customer');
      throw error;
    }
  }

  /**
   * Get Sales Trends
   */
  /**
   * MRD FR-8.1 — revenue split by document currency (snapshot-rate base).
   */
  async getSalesByCurrency(filters: ReportFilters): Promise<{ currencies: Array<{ currency_code: string; total_revenue: number; base_revenue: number; order_count: number }>; summary: { total_base_revenue: number } }> {
    try {
      const response = await api.get<ApiResponse<{ currencies: Array<{ currency_code: string; total_revenue: number; base_revenue: number; order_count: number }>; summary: { total_base_revenue: number } }>>(
        '/analytics/sales/by-currency',
        { params: this.salesParams(filters) }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales by Currency');
      throw error;
    }
  }

  async getSalesTrends(filters: ReportFilters): Promise<SalesTrendsData> {
    try {
      const response = await api.get<ApiResponse<SalesTrendsData>>(
        '/analytics/sales/trends',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales Trends');
      throw error;
    }
  }

  /**
   * Get Sales by Category
   */
  async getSalesByCategory(filters: ReportFilters): Promise<SalesByCategoryData> {
    try {
      const response = await api.get<ApiResponse<SalesByCategoryData>>(
        '/analytics/sales/by-category',
        { params: this.salesParams(filters) }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales by Category');
      throw error;
    }
  }

  /**
   * Issue #604: ties-to-GL reconciliation for the sales-report footer.
   * Same filters as the report itself (the endpoint shares the #593 filter
   * contract and rejects unknown keys with a 422).
   */
  async getSalesReconciliation(filters: ReportFilters): Promise<SalesReconciliationData> {
    try {
      const response = await api.get<ApiResponse<SalesReconciliationData>>(
        '/analytics/sales/reconciliation',
        { params: this.salesParams(filters) }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales reconciliation');
      throw error;
    }
  }

  /**
   * Issue #601: pivot-style sales analysis — whitelisted row/column group-bys
   * over the shared sales read model, with the chosen measures per cell.
   */
  async getSalesPivot(
    filters: ReportFilters,
    params: { row_dims: PivotDimension[]; col_dims: PivotDimension[]; measure: PivotMeasure[] }
  ): Promise<SalesPivotData> {
    try {
      const response = await api.get<ApiResponse<SalesPivotData>>(
        '/analytics/sales/pivot',
        { params: { ...this.salesParams(filters), ...params } }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales Pivot');
      throw error;
    }
  }

  /**
   * Issue #596: the source documents behind one report row, read with the
   * same #593 filters as the row. The endpoint takes no comparison window
   * (422), and `interval` only for a period row.
   */
  async getSalesDrilldown(filters: ReportFilters, target: SalesDrilldownTarget): Promise<SalesDrilldownData> {
    const params: ReportFilters & Record<string, unknown> = { ...this.salesParams(filters) };
    delete params.comparison;
    delete params.compare_start_date;
    delete params.compare_end_date;
    params.dimension = target.dimension;
    params.key = target.key;
    if (target.dimension === 'period') {
      params.interval = target.interval ?? 'day';
    }
    if (target.document_type) {
      params.document_type = target.document_type;
    }
    try {
      const response = await api.get<ApiResponse<SalesDrilldownData>>(
        '/analytics/sales/drilldown',
        { params }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales drill-down');
      throw error;
    }
  }

  /**
   * Issue #532: credit note analysis — credit rate (credited ÷ invoiced) by
   * reason, product, customer or salesperson, with the same #593 filters.
   */
  async getCreditNoteAnalysis(filters: ReportFilters, groupBy: CreditNoteGrouping): Promise<CreditNoteAnalysisData> {
    const params: ReportFilters & Record<string, unknown> = { ...this.salesParams(filters), group_by: groupBy };
    delete params.comparison;
    delete params.compare_start_date;
    delete params.compare_end_date;
    try {
      const response = await api.get<ApiResponse<CreditNoteAnalysisData>>(
        '/analytics/sales/credit-notes',
        { params }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Credit Note Analysis');
      throw error;
    }
  }

  /**
   * Issue #602: the "unbilled deliveries" view — completed delivery-order
   * lines whose delivered quantity still exceeds what has been invoiced,
   * scoped by the same #593 sales filters.
   */
  async getUnbilledDeliveries(filters: ReportFilters): Promise<UnbilledDeliveriesData> {
    try {
      const response = await api.get<ApiResponse<UnbilledDeliveriesData>>(
        '/analytics/sales/unbilled-deliveries',
        { params: this.salesParams(filters) }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Unbilled Deliveries');
      throw error;
    }
  }

  /**
   * Issue #609: hospital/doctor performance of medical cases for a period —
   * units used (not allocated), case counts and invoiced value.
   */
  async getMedicalCasePerformance(
    filters: Pick<ReportFilters, 'start_date' | 'end_date'>,
    dateField: MedicalCaseDateField = 'procedure_date'
  ): Promise<MedicalCasePerformanceData> {
    try {
      const response = await api.get<ApiResponse<MedicalCasePerformanceData>>(
        '/medical-cases/reports/performance',
        {
          params: {
            from_date: filters.start_date,
            to_date: filters.end_date,
            date_field: dateField,
          },
        }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Product Usage');
      throw error;
    }
  }

  // ==================== Expense Analytics ====================

  /**
   * Get Expenses by Category
   */
  async getExpensesByCategory(filters: ReportFilters): Promise<ExpensesByCategoryData> {
    try {
      const response = await api.get<ApiResponse<ExpensesByCategoryData>>(
        '/analytics/expenses/by-category',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Expenses by Category');
      throw error;
    }
  }

  /**
   * Get Expenses by Vendor
   */
  async getExpensesByVendor(filters: ReportFilters): Promise<ExpensesByVendorData> {
    try {
      const response = await api.get<ApiResponse<ExpensesByVendorData>>(
        '/analytics/expenses/by-vendor',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Expenses by Vendor');
      throw error;
    }
  }

  /**
   * Get Expenses by Department
   */
  async getExpensesByDepartment(filters: ReportFilters): Promise<ExpensesByDepartmentData> {
    try {
      const response = await api.get<ApiResponse<ExpensesByDepartmentData>>(
        '/analytics/expenses/by-department',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Expenses by Department');
      throw error;
    }
  }

  // ==================== Customer Analytics ====================

  /**
   * Get Customer Lifetime Value
   */
  async getCustomerLifetimeValue(filters: ReportFilters): Promise<CustomerLifetimeValueData> {
    try {
      const response = await api.get<ApiResponse<CustomerLifetimeValueData>>(
        '/analytics/customers/lifetime-value',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Customer Lifetime Value');
      throw error;
    }
  }

  /**
   * Get Customer Purchase Patterns
   */
  async getCustomerPurchasePatterns(
    filters: ReportFilters
  ): Promise<CustomerPurchasePatternsData> {
    try {
      const response = await api.get<ApiResponse<CustomerPurchasePatternsData>>(
        '/analytics/customers/purchase-patterns',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Customer Purchase Patterns');
      throw error;
    }
  }

  /**
   * Get Customer Segmentation
   */
  async getCustomerSegmentation(filters: ReportFilters): Promise<CustomerSegmentationData> {
    try {
      const response = await api.get<ApiResponse<CustomerSegmentationData>>(
        '/analytics/customers/segmentation',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Customer Segmentation');
      throw error;
    }
  }

  // ==================== Dashboards ====================

  /**
   * Get Executive Dashboard Data
   */
  async getExecutiveDashboard(filters?: ReportFilters): Promise<ExecutiveDashboardData> {
    try {
      const response = await api.get<ApiResponse<ExecutiveDashboardData>>(
        '/dashboards/executive',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Executive Dashboard');
      throw error;
    }
  }

  /**
   * Get Sales Dashboard Data
   */
  async getSalesDashboard(filters?: ReportFilters): Promise<SalesDashboardData> {
    try {
      const response = await api.get<ApiResponse<SalesDashboardData>>(
        '/dashboards/sales',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales Dashboard');
      throw error;
    }
  }

  /**
   * Get Inventory Dashboard Data
   */
  async getInventoryDashboard(filters?: ReportFilters): Promise<InventoryDashboardData> {
    try {
      const response = await api.get<ApiResponse<InventoryDashboardData>>(
        '/dashboards/inventory',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Inventory Dashboard');
      throw error;
    }
  }

  /**
   * Get Financial Dashboard Data
   */
  async getFinancialDashboard(filters?: ReportFilters): Promise<FinancialDashboardData> {
    try {
      const response = await api.get<ApiResponse<FinancialDashboardData>>(
        '/dashboards/financial',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Financial Dashboard');
      throw error;
    }
  }

  // ==================== Export Services ====================

  /**
   * Export Report to PDF
   */
  async exportToPdf(request: ExportRequest): Promise<ExportResponse> {
    try {
      const response = await api.post<ApiResponse<ExportResponse>>(
        '/reports/export/pdf',
        request
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to export report to PDF');
      throw error;
    }
  }

  /**
   * Export Report to CSV
   */
  async exportToCsv(request: ExportRequest): Promise<ExportResponse> {
    try {
      const response = await api.post<ApiResponse<ExportResponse>>(
        '/reports/export/csv',
        request
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to export report to CSV');
      throw error;
    }
  }

  // ==================== Helper Methods ====================

  /**
   * Handle API errors with consistent error messages
   */
  private handleError(error: any, defaultMessage: string): void {
    if (error.response) {
      // Server responded with error status
      const message = error.response.data?.message || defaultMessage;
      console.error(`API Error: ${message}`, error.response.data);
    } else if (error.request) {
      // Request made but no response received
      console.error('Network Error: No response received', error.request);
    } else {
      // Error in request setup
      console.error('Request Error:', error.message);
    }
  }

  /**
   * Build query string from filters object
   */
  buildQueryString(filters: ReportFilters): string {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        params.append(key, String(value));
      }
    });
    return params.toString();
  }

  /**
   * Format date for API requests (YYYY-MM-DD)
   */
  formatDate(date: Date): string {
    return formatLocalDate(date);
  }

  /**
   * Get date range presets
   */
  getDateRangePresets(today: Date = new Date()) {
    const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    const endOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    const startOfLastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const endOfLastMonth = new Date(today.getFullYear(), today.getMonth(), 0);
    const startOfYear = new Date(today.getFullYear(), 0, 1);
    const startOfLastYear = new Date(today.getFullYear() - 1, 0, 1);
    const endOfLastYear = new Date(today.getFullYear() - 1, 11, 31);

    return {
      today: {
        start_date: this.formatDate(today),
        end_date: this.formatDate(today),
      },
      thisWeek: {
        // #353 (S5): build a new Date for the week start — today.setDate()
        // mutated the shared `today`, so every later preset read the moved
        // date (thisYear ended at the start of the current week, and
        // thisQuarter could fall into the previous month/quarter).
        start_date: this.formatDate(
          new Date(today.getFullYear(), today.getMonth(), today.getDate() - today.getDay())
        ),
        end_date: this.formatDate(today),
      },
      thisMonth: {
        start_date: this.formatDate(startOfMonth),
        end_date: this.formatDate(endOfMonth),
      },
      lastMonth: {
        start_date: this.formatDate(startOfLastMonth),
        end_date: this.formatDate(endOfLastMonth),
      },
      thisQuarter: {
        start_date: this.formatDate(
          new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1)
        ),
        end_date: this.formatDate(
          new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3 + 3, 0)
        ),
      },
      thisYear: {
        start_date: this.formatDate(startOfYear),
        end_date: this.formatDate(today),
      },
      lastYear: {
        start_date: this.formatDate(startOfLastYear),
        end_date: this.formatDate(endOfLastYear),
      },
    };
  }
}

// Export singleton instance
const reportApi = new ReportApiClient();
export default reportApi;

// Also export the class for testing purposes
export { ReportApiClient };
