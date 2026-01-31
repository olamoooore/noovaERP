import { useState, useCallback } from 'react';
import reportApi from '../lib/reportApi';
import type { ReportFilters } from '../types/reports';

/**
 * Custom hook for managing report API calls with loading and error states
 */
export function useReportApi<T>() {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const execute = useCallback(
    async (apiCall: () => Promise<T>) => {
      setLoading(true);
      setError(null);
      
      try {
        const result = await apiCall();
        setData(result);
        return result;
      } catch (err: any) {
        const errorMessage = err.response?.data?.message || err.message || 'An error occurred';
        setError(errorMessage);
        throw err;
      } finally {
        setLoading(false);
      }
    },
    []
  );

  const reset = useCallback(() => {
    setData(null);
    setError(null);
    setLoading(false);
  }, []);

  return {
    data,
    loading,
    error,
    execute,
    reset,
  };
}

/**
 * Hook for Profit & Loss report
 */
export function useProfitLoss() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchProfitLoss = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getProfitLoss(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchProfitLoss,
    reset,
  };
}

/**
 * Hook for Balance Sheet report
 */
export function useBalanceSheet() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchBalanceSheet = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getBalanceSheet(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchBalanceSheet,
    reset,
  };
}

/**
 * Hook for Cash Flow report
 */
export function useCashFlow() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchCashFlow = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getCashFlow(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchCashFlow,
    reset,
  };
}

/**
 * Hook for Trial Balance report
 */
export function useTrialBalance() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchTrialBalance = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getTrialBalance(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchTrialBalance,
    reset,
  };
}

/**
 * Hook for General Ledger report
 */
export function useGeneralLedger() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchGeneralLedger = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getGeneralLedger(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchGeneralLedger,
    reset,
  };
}

/**
 * Hook for Stock Valuation report
 */
export function useStockValuation() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchStockValuation = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getStockValuation(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchStockValuation,
    reset,
  };
}

/**
 * Hook for Stock Movement report
 */
export function useStockMovement() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchStockMovement = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getStockMovement(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchStockMovement,
    reset,
  };
}

/**
 * Hook for Reorder report
 */
export function useReorderReport() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchReorderReport = useCallback(
    (filters?: ReportFilters) => {
      return execute(() => reportApi.getReorderReport(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchReorderReport,
    reset,
  };
}

/**
 * Hook for Inventory Aging report
 */
export function useInventoryAging() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchInventoryAging = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getInventoryAging(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchInventoryAging,
    reset,
  };
}

/**
 * Hook for Inventory Turnover report
 */
export function useInventoryTurnover() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchInventoryTurnover = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getInventoryTurnover(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchInventoryTurnover,
    reset,
  };
}

/**
 * Hook for Sales by Product analytics
 */
export function useSalesByProduct() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchSalesByProduct = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getSalesByProduct(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchSalesByProduct,
    reset,
  };
}

/**
 * Hook for Sales by Customer analytics
 */
export function useSalesByCustomer() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchSalesByCustomer = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getSalesByCustomer(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchSalesByCustomer,
    reset,
  };
}

/**
 * Hook for Sales Trends analytics
 */
export function useSalesTrends() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchSalesTrends = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getSalesTrends(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchSalesTrends,
    reset,
  };
}

/**
 * Hook for Sales by Category analytics
 */
export function useSalesByCategory() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchSalesByCategory = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getSalesByCategory(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchSalesByCategory,
    reset,
  };
}

/**
 * Hook for Expenses by Category analytics
 */
export function useExpensesByCategory() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchExpensesByCategory = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getExpensesByCategory(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchExpensesByCategory,
    reset,
  };
}

/**
 * Hook for Expenses by Vendor analytics
 */
export function useExpensesByVendor() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchExpensesByVendor = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getExpensesByVendor(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchExpensesByVendor,
    reset,
  };
}

/**
 * Hook for Expenses by Department analytics
 */
export function useExpensesByDepartment() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchExpensesByDepartment = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getExpensesByDepartment(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchExpensesByDepartment,
    reset,
  };
}

/**
 * Hook for Customer Lifetime Value analytics
 */
export function useCustomerLifetimeValue() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchCustomerLifetimeValue = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getCustomerLifetimeValue(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchCustomerLifetimeValue,
    reset,
  };
}

/**
 * Hook for Customer Purchase Patterns analytics
 */
export function useCustomerPurchasePatterns() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchCustomerPurchasePatterns = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getCustomerPurchasePatterns(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchCustomerPurchasePatterns,
    reset,
  };
}

/**
 * Hook for Customer Segmentation analytics
 */
export function useCustomerSegmentation() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchCustomerSegmentation = useCallback(
    (filters: ReportFilters) => {
      return execute(() => reportApi.getCustomerSegmentation(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchCustomerSegmentation,
    reset,
  };
}

/**
 * Hook for Executive Dashboard
 */
export function useExecutiveDashboard() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchExecutiveDashboard = useCallback(
    (filters?: ReportFilters) => {
      return execute(() => reportApi.getExecutiveDashboard(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchExecutiveDashboard,
    reset,
  };
}

/**
 * Hook for Sales Dashboard
 */
export function useSalesDashboard() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchSalesDashboard = useCallback(
    (filters?: ReportFilters) => {
      return execute(() => reportApi.getSalesDashboard(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchSalesDashboard,
    reset,
  };
}

/**
 * Hook for Inventory Dashboard
 */
export function useInventoryDashboard() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchInventoryDashboard = useCallback(
    (filters?: ReportFilters) => {
      return execute(() => reportApi.getInventoryDashboard(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchInventoryDashboard,
    reset,
  };
}

/**
 * Hook for Financial Dashboard
 */
export function useFinancialDashboard() {
  const { data, loading, error, execute, reset } = useReportApi();

  const fetchFinancialDashboard = useCallback(
    (filters?: ReportFilters) => {
      return execute(() => reportApi.getFinancialDashboard(filters));
    },
    [execute]
  );

  return {
    data,
    loading,
    error,
    fetchFinancialDashboard,
    reset,
  };
}

/**
 * Hook for Report Export
 */
export function useReportExport() {
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const exportToPdf = useCallback(async (reportType: string, reportData: any, filters: ReportFilters) => {
    setLoading(true);
    setError(null);
    
    try {
      const result = await reportApi.exportToPdf({ report_type: reportType, report_data: reportData, filters });
      return result;
    } catch (err: any) {
      const errorMessage = err.response?.data?.message || err.message || 'Export failed';
      setError(errorMessage);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const exportToExcel = useCallback(async (reportType: string, reportData: any, filters: ReportFilters) => {
    setLoading(true);
    setError(null);
    
    try {
      const result = await reportApi.exportToExcel({ report_type: reportType, report_data: reportData, filters });
      return result;
    } catch (err: any) {
      const errorMessage = err.response?.data?.message || err.message || 'Export failed';
      setError(errorMessage);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  const exportToCsv = useCallback(async (reportType: string, reportData: any, filters: ReportFilters) => {
    setLoading(true);
    setError(null);
    
    try {
      const result = await reportApi.exportToCsv({ report_type: reportType, report_data: reportData, filters });
      return result;
    } catch (err: any) {
      const errorMessage = err.response?.data?.message || err.message || 'Export failed';
      setError(errorMessage);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  return {
    loading,
    error,
    exportToPdf,
    exportToExcel,
    exportToCsv,
  };
}
