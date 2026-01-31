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
  SalesByProductData,
  SalesByCustomerData,
  SalesTrendsData,
  SalesByCategoryData,
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
  ExportRequest,
  ExportResponse,
  ApiResponse,
} from '../types/reports';

/**
 * Report API Client
 * Provides methods to interact with all financial reporting, inventory, and analytics endpoints
 */
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
      // Balance Sheet requires as_of_date instead of start_date/end_date
      const params = {
        ...filters,
        as_of_date: filters.end_date || filters.start_date,
      };
      // Remove start_date and end_date as they're not used for Balance Sheet
      delete params.start_date;
      delete params.end_date;
      
      const response = await api.get<ApiResponse<BalanceSheetData>>(
        '/reports/financial/balance-sheet',
        { params }
      );
      return response.data.data;
    } catch (error) {
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
      // Trial Balance requires as_of_date instead of start_date/end_date
      const params = {
        ...filters,
        as_of_date: filters.end_date || filters.start_date,
      };
      // Remove start_date and end_date as they're not used for Trial Balance
      delete params.start_date;
      delete params.end_date;
      
      const response = await api.get<ApiResponse<TrialBalanceData>>(
        '/reports/financial/trial-balance',
        { params }
      );
      return response.data.data;
    } catch (error) {
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
   * Get Reorder Report
   */
  async getReorderReport(filters?: ReportFilters): Promise<ReorderData> {
    try {
      const response = await api.get<ApiResponse<ReorderData>>(
        '/reports/inventory/reorder',
        { params: filters }
      );
      return response.data.data;
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
   * Get Sales by Product
   */
  async getSalesByProduct(filters: ReportFilters): Promise<SalesByProductData> {
    try {
      const response = await api.get<ApiResponse<SalesByProductData>>(
        '/analytics/sales/by-product',
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales by Product');
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
        { params: filters }
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
        { params: filters }
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to fetch Sales by Category');
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
   * Export Report to Excel
   */
  async exportToExcel(request: ExportRequest): Promise<ExportResponse> {
    try {
      const response = await api.post<ApiResponse<ExportResponse>>(
        '/reports/export/excel',
        request
      );
      return response.data.data;
    } catch (error) {
      this.handleError(error, 'Failed to export report to Excel');
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
    return date.toISOString().split('T')[0];
  }

  /**
   * Get date range presets
   */
  getDateRangePresets() {
    const today = new Date();
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
        start_date: this.formatDate(
          new Date(today.setDate(today.getDate() - today.getDay()))
        ),
        end_date: this.formatDate(new Date()),
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
