/**
 * Report API Client - Usage Examples
 * 
 * This file demonstrates how to use the report API client and hooks
 * in React components.
 */

import React, { useEffect } from 'react';
import reportApi from './reportApi';
import { 
  useProfitLoss, 
  useBalanceSheet,
  useSalesByProduct,
  useReportExport 
} from '../hooks/useReportApi';
import type { ReportFilters } from '../types/reports';

// ==================== Example 1: Direct API Usage ====================

export function DirectApiExample() {
  const [data, setData] = React.useState(null);
  const [loading, setLoading] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const fetchReport = async () => {
    setLoading(true);
    setError(null);
    
    try {
      const filters: ReportFilters = {
        start_date: '2025-01-01',
        end_date: '2025-01-31',
      };
      
      const result = await reportApi.getProfitLoss(filters);
      setData(result);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <button onClick={fetchReport}>Fetch P&L Report</button>
      {loading && <p>Loading...</p>}
      {error && <p>Error: {error}</p>}
      {data && <pre>{JSON.stringify(data, null, 2)}</pre>}
    </div>
  );
}

// ==================== Example 2: Using Custom Hooks ====================

export function HookExample() {
  const { data, loading, error, fetchProfitLoss } = useProfitLoss();

  useEffect(() => {
    const filters: ReportFilters = {
      start_date: '2025-01-01',
      end_date: '2025-01-31',
      compare_period: true,
    };
    
    fetchProfitLoss(filters);
  }, [fetchProfitLoss]);

  if (loading) return <div>Loading...</div>;
  if (error) return <div>Error: {error}</div>;
  if (!data) return null;

  return (
    <div>
      <h2>Profit & Loss Statement</h2>
      <div>
        <p>Total Revenue: ${data.revenue.total.toLocaleString()}</p>
        <p>Total Expenses: ${data.expenses.total.toLocaleString()}</p>
        <p>Net Profit: ${data.net_profit.toLocaleString()}</p>
      </div>
    </div>
  );
}

// ==================== Example 3: Multiple Reports ====================

export function MultipleReportsExample() {
  const profitLoss = useProfitLoss();
  const balanceSheet = useBalanceSheet();

  useEffect(() => {
    const filters: ReportFilters = {
      start_date: '2025-01-01',
      end_date: '2025-01-31',
    };
    
    profitLoss.fetchProfitLoss(filters);
    balanceSheet.fetchBalanceSheet({ as_of_date: '2025-01-31' });
  }, []);

  return (
    <div>
      <div>
        <h3>Profit & Loss</h3>
        {profitLoss.loading && <p>Loading...</p>}
        {profitLoss.error && <p>Error: {profitLoss.error}</p>}
        {profitLoss.data && (
          <p>Net Profit: ${profitLoss.data.net_profit.toLocaleString()}</p>
        )}
      </div>

      <div>
        <h3>Balance Sheet</h3>
        {balanceSheet.loading && <p>Loading...</p>}
        {balanceSheet.error && <p>Error: {balanceSheet.error}</p>}
        {balanceSheet.data && (
          <p>Total Assets: ${balanceSheet.data.total_assets.toLocaleString()}</p>
        )}
      </div>
    </div>
  );
}

// ==================== Example 4: Sales Analytics ====================

export function SalesAnalyticsExample() {
  const { data, loading, error, fetchSalesByProduct } = useSalesByProduct();

  const handleFilterChange = (filters: ReportFilters) => {
    fetchSalesByProduct(filters);
  };

  useEffect(() => {
    const filters: ReportFilters = {
      start_date: '2025-01-01',
      end_date: '2025-01-31',
    };
    
    fetchSalesByProduct(filters);
  }, [fetchSalesByProduct]);

  if (loading) return <div>Loading sales data...</div>;
  if (error) return <div>Error: {error}</div>;
  if (!data) return null;

  return (
    <div>
      <h2>Sales by Product</h2>
      <table>
        <thead>
          <tr>
            <th>Product</th>
            <th>Quantity</th>
            <th>Revenue</th>
            <th>Profit</th>
            <th>Margin</th>
          </tr>
        </thead>
        <tbody>
          {data.products.map((product) => (
            <tr key={product.id}>
              <td>{product.name}</td>
              <td>{product.total_quantity}</td>
              <td>${product.total_revenue.toLocaleString()}</td>
              <td>${product.total_profit.toLocaleString()}</td>
              <td>{product.profit_margin.toFixed(2)}%</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ==================== Example 5: Export Functionality ====================

export function ExportExample() {
  const { data, loading, error, fetchProfitLoss } = useProfitLoss();
  const { 
    loading: exportLoading, 
    error: exportError, 
    exportToPdf, 
    exportToExcel 
  } = useReportExport();

  const filters: ReportFilters = {
    start_date: '2025-01-01',
    end_date: '2025-01-31',
  };

  useEffect(() => {
    fetchProfitLoss(filters);
  }, [fetchProfitLoss]);

  const handleExportPdf = async () => {
    if (!data) return;
    
    try {
      const result = await exportToPdf('profit_loss', data, filters);
      if (result.download_url) {
        window.open(result.download_url, '_blank');
      }
    } catch (err) {
      console.error('Export failed:', err);
    }
  };

  const handleExportExcel = async () => {
    if (!data) return;
    
    try {
      const result = await exportToExcel('profit_loss', data, filters);
      if (result.download_url) {
        window.open(result.download_url, '_blank');
      }
    } catch (err) {
      console.error('Export failed:', err);
    }
  };

  return (
    <div>
      <h2>Profit & Loss Statement</h2>
      
      {loading && <p>Loading report...</p>}
      {error && <p>Error: {error}</p>}
      
      {data && (
        <>
          <div>
            <p>Net Profit: ${data.net_profit.toLocaleString()}</p>
          </div>
          
          <div>
            <button onClick={handleExportPdf} disabled={exportLoading}>
              {exportLoading ? 'Exporting...' : 'Export to PDF'}
            </button>
            <button onClick={handleExportExcel} disabled={exportLoading}>
              {exportLoading ? 'Exporting...' : 'Export to Excel'}
            </button>
            {exportError && <p>Export Error: {exportError}</p>}
          </div>
        </>
      )}
    </div>
  );
}

// ==================== Example 6: Date Range Presets ====================

export function DateRangePresetsExample() {
  const { data, loading, error, fetchProfitLoss } = useProfitLoss();
  const presets = reportApi.getDateRangePresets();

  const handlePresetClick = (preset: keyof typeof presets) => {
    const filters = presets[preset];
    fetchProfitLoss(filters);
  };

  return (
    <div>
      <h2>Quick Date Ranges</h2>
      <div>
        <button onClick={() => handlePresetClick('today')}>Today</button>
        <button onClick={() => handlePresetClick('thisWeek')}>This Week</button>
        <button onClick={() => handlePresetClick('thisMonth')}>This Month</button>
        <button onClick={() => handlePresetClick('lastMonth')}>Last Month</button>
        <button onClick={() => handlePresetClick('thisQuarter')}>This Quarter</button>
        <button onClick={() => handlePresetClick('thisYear')}>This Year</button>
        <button onClick={() => handlePresetClick('lastYear')}>Last Year</button>
      </div>

      {loading && <p>Loading...</p>}
      {error && <p>Error: {error}</p>}
      {data && (
        <div>
          <p>Net Profit: ${data.net_profit.toLocaleString()}</p>
        </div>
      )}
    </div>
  );
}

// ==================== Example 7: Error Handling ====================

export function ErrorHandlingExample() {
  const { data, loading, error, fetchProfitLoss, reset } = useProfitLoss();

  const handleFetch = async () => {
    try {
      await fetchProfitLoss({
        start_date: '2025-01-01',
        end_date: '2025-01-31',
      });
    } catch (err) {
      // Error is already captured in the hook
      console.error('Failed to fetch report:', err);
    }
  };

  const handleRetry = () => {
    reset(); // Clear error state
    handleFetch();
  };

  return (
    <div>
      <button onClick={handleFetch} disabled={loading}>
        Fetch Report
      </button>

      {loading && <div className="spinner">Loading...</div>}
      
      {error && (
        <div className="error-message">
          <p>Error: {error}</p>
          <button onClick={handleRetry}>Retry</button>
        </div>
      )}

      {data && (
        <div className="report-data">
          <p>Net Profit: ${data.net_profit.toLocaleString()}</p>
        </div>
      )}
    </div>
  );
}

// ==================== Example 8: Pagination ====================

export function PaginationExample() {
  const { data, loading, error, fetchGeneralLedger } = useGeneralLedger();
  const [currentPage, setCurrentPage] = React.useState(1);

  const handlePageChange = (page: number) => {
    setCurrentPage(page);
    fetchGeneralLedger({
      account_id: 1,
      start_date: '2025-01-01',
      end_date: '2025-01-31',
      page,
    });
  };

  return (
    <div>
      <h2>General Ledger</h2>
      
      {loading && <p>Loading...</p>}
      {error && <p>Error: {error}</p>}
      
      {data && (
        <>
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Description</th>
                <th>Debit</th>
                <th>Credit</th>
                <th>Balance</th>
              </tr>
            </thead>
            <tbody>
              {data.transactions.map((txn, index) => (
                <tr key={index}>
                  <td>{txn.date}</td>
                  <td>{txn.reference}</td>
                  <td>{txn.description}</td>
                  <td>{txn.debit}</td>
                  <td>{txn.credit}</td>
                  <td>{txn.balance}</td>
                </tr>
              ))}
            </tbody>
          </table>

          {data.pagination && (
            <div className="pagination">
              <button 
                onClick={() => handlePageChange(currentPage - 1)}
                disabled={currentPage === 1}
              >
                Previous
              </button>
              <span>
                Page {data.pagination.current_page} of {data.pagination.total_pages}
              </span>
              <button 
                onClick={() => handlePageChange(currentPage + 1)}
                disabled={currentPage === data.pagination.total_pages}
              >
                Next
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
