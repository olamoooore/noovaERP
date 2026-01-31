/**
 * Example usage of ReportFilters component
 * 
 * This file demonstrates how to use the ReportFilters component
 * in different scenarios for various report types.
 */

import React from 'react';
import { ReportFilters } from './ReportFilters';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';

/**
 * Example 1: Financial Report with Date Range and Department
 */
export const FinancialReportExample: React.FC = () => {
  const handleFilterChange = (newFilters: ReportFiltersType) => {
    console.log('Filters changed:', newFilters);
    // Fetch report data with new filters
  };

  return (
    <div className="space-y-4">
      <h2 className="text-2xl font-bold">Profit & Loss Report</h2>
      <ReportFilters
        onFilterChange={handleFilterChange}
        showDateRange={true}
        showDepartment={true}
        showComparePeriod={true}
        initialFilters={{
          start_date: '2025-01-01',
          end_date: '2025-01-31',
        }}
      />
    </div>
  );
};

/**
 * Example 2: Account Statement with Account Selector
 */
export const AccountStatementExample: React.FC = () => {
  const handleFilterChange = (newFilters: ReportFiltersType) => {
    console.log('Filters changed:', newFilters);
    // Fetch account statement with filters
  };

  return (
    <div className="space-y-4">
      <h2 className="text-2xl font-bold">Account Statement</h2>
      <ReportFilters
        onFilterChange={handleFilterChange}
        showDateRange={true}
        showAccount={true}
        accountType="asset"
      />
    </div>
  );
};

/**
 * Example 3: Sales Trends with Interval Selection
 */
export const SalesTrendsExample: React.FC = () => {
  const handleFilterChange = (newFilters: ReportFiltersType) => {
    console.log('Filters changed:', newFilters);
    // Fetch sales trends with interval
  };

  return (
    <div className="space-y-4">
      <h2 className="text-2xl font-bold">Sales Trends</h2>
      <ReportFilters
        onFilterChange={handleFilterChange}
        showDateRange={true}
        showInterval={true}
        autoApply={true}
        initialFilters={{
          interval: 'month',
        }}
      />
    </div>
  );
};

/**
 * Example 4: Trial Balance with Account Type Filter
 */
export const TrialBalanceExample: React.FC = () => {
  const handleFilterChange = (newFilters: ReportFiltersType) => {
    console.log('Filters changed:', newFilters);
    // Fetch trial balance
  };

  return (
    <div className="space-y-4">
      <h2 className="text-2xl font-bold">Trial Balance</h2>
      <ReportFilters
        onFilterChange={handleFilterChange}
        showDateRange={false}
        showAccountType={true}
        initialFilters={{
          as_of_date: new Date().toISOString().split('T')[0],
        }}
      />
    </div>
  );
};

/**
 * Example 5: Minimal Filter with Auto-Apply
 */
export const MinimalFilterExample: React.FC = () => {
  const handleFilterChange = (newFilters: ReportFiltersType) => {
    console.log('Auto-applied filters:', newFilters);
    // Filters are automatically applied on change
  };

  return (
    <div className="space-y-4">
      <h2 className="text-2xl font-bold">Quick Report</h2>
      <ReportFilters
        onFilterChange={handleFilterChange}
        showDateRange={true}
        autoApply={true}
      />
    </div>
  );
};
