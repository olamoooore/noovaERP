/**
 * ReportTable Component - Usage Examples
 * 
 * This file demonstrates various ways to use the ReportTable component
 * for different types of financial reports.
 */

import { ReportTable, Column } from './ReportTable';

// Example 1: Simple Financial Report
export const ProfitLossTableExample = () => {
  const columns: Column[] = [
    {
      key: 'account_name',
      label: 'Account',
      sortable: true,
      filterable: true,
      align: 'left',
    },
    {
      key: 'amount',
      label: 'Amount',
      sortable: true,
      align: 'right',
      format: (value) => `$${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
    },
    {
      key: 'percentage',
      label: '% of Total',
      sortable: true,
      align: 'right',
      format: (value) => `${Number(value).toFixed(2)}%`,
    },
  ];

  const data = [
    { account_name: 'Sales Revenue', amount: 150000, percentage: 75 },
    { account_name: 'Service Revenue', amount: 50000, percentage: 25 },
    { account_name: 'Cost of Goods Sold', amount: -80000, percentage: 40 },
    { account_name: 'Operating Expenses', amount: -30000, percentage: 15 },
  ];

  return (
    <ReportTable
      columns={columns}
      data={data}
      showPagination={false}
      showSearch={true}
      emptyMessage="No financial data available"
    />
  );
};

// Example 2: Inventory Report with Custom Formatting
export const InventoryTableExample = () => {
  const columns: Column[] = [
    {
      key: 'sku',
      label: 'SKU',
      sortable: true,
      filterable: true,
    },
    {
      key: 'product_name',
      label: 'Product',
      sortable: true,
      filterable: true,
    },
    {
      key: 'quantity',
      label: 'Quantity',
      sortable: true,
      align: 'right',
      format: (value) => Number(value).toLocaleString(),
    },
    {
      key: 'cost',
      label: 'Unit Cost',
      sortable: true,
      align: 'right',
      format: (value) => `$${Number(value).toFixed(2)}`,
    },
    {
      key: 'value',
      label: 'Total Value',
      sortable: true,
      align: 'right',
      format: (value) => `$${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
      className: 'font-semibold',
    },
  ];

  const data = [
    { sku: 'PROD-001', product_name: 'Widget A', quantity: 150, cost: 25.50, value: 3825 },
    { sku: 'PROD-002', product_name: 'Widget B', quantity: 200, cost: 15.75, value: 3150 },
    { sku: 'PROD-003', product_name: 'Gadget X', quantity: 75, cost: 45.00, value: 3375 },
    { sku: 'PROD-004', product_name: 'Gadget Y', quantity: 100, cost: 30.25, value: 3025 },
  ];

  return (
    <ReportTable
      columns={columns}
      data={data}
      showPagination={true}
      showSearch={true}
      showFilters={true}
      pageSize={10}
    />
  );
};

// Example 3: Sales Analytics with Row Click Handler
export const SalesAnalyticsTableExample = () => {
  const columns: Column[] = [
    {
      key: 'customer_name',
      label: 'Customer',
      sortable: true,
      filterable: true,
    },
    {
      key: 'total_orders',
      label: 'Orders',
      sortable: true,
      align: 'center',
    },
    {
      key: 'total_revenue',
      label: 'Revenue',
      sortable: true,
      align: 'right',
      format: (value) => `$${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
    },
    {
      key: 'avg_order_value',
      label: 'Avg Order',
      sortable: true,
      align: 'right',
      format: (value) => `$${Number(value).toFixed(2)}`,
    },
    {
      key: 'last_purchase_date',
      label: 'Last Purchase',
      sortable: true,
      format: (value) => new Date(value).toLocaleDateString(),
    },
  ];

  const data = [
    {
      customer_name: 'Acme Corp',
      total_orders: 25,
      total_revenue: 125000,
      avg_order_value: 5000,
      last_purchase_date: '2024-12-15',
    },
    {
      customer_name: 'TechStart Inc',
      total_orders: 18,
      total_revenue: 90000,
      avg_order_value: 5000,
      last_purchase_date: '2024-12-20',
    },
    {
      customer_name: 'Global Solutions',
      total_orders: 32,
      total_revenue: 160000,
      avg_order_value: 5000,
      last_purchase_date: '2024-12-28',
    },
  ];

  const handleRowClick = (row: any) => {
    console.log('Customer clicked:', row.customer_name);
    // Navigate to customer detail page or show modal
  };

  return (
    <ReportTable
      columns={columns}
      data={data}
      showPagination={true}
      showSearch={true}
      pageSize={20}
      onRowClick={handleRowClick}
    />
  );
};

// Example 4: Trial Balance with Conditional Formatting
export const TrialBalanceTableExample = () => {
  const columns: Column[] = [
    {
      key: 'code',
      label: 'Code',
      sortable: true,
      align: 'left',
    },
    {
      key: 'account_name',
      label: 'Account Name',
      sortable: true,
      filterable: true,
      align: 'left',
    },
    {
      key: 'debit',
      label: 'Debit',
      sortable: true,
      align: 'right',
      format: (value) => value > 0 ? `$${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2 })}` : '-',
    },
    {
      key: 'credit',
      label: 'Credit',
      sortable: true,
      align: 'right',
      format: (value) => value > 0 ? `$${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2 })}` : '-',
    },
  ];

  const data = [
    { code: '1000', account_name: 'Cash', debit: 50000, credit: 0 },
    { code: '1100', account_name: 'Accounts Receivable', debit: 25000, credit: 0 },
    { code: '2000', account_name: 'Accounts Payable', debit: 0, credit: 15000 },
    { code: '3000', account_name: 'Owner Equity', debit: 0, credit: 40000 },
    { code: '4000', account_name: 'Sales Revenue', debit: 0, credit: 100000 },
    { code: '5000', account_name: 'Cost of Goods Sold', debit: 60000, credit: 0 },
    { code: '6000', account_name: 'Operating Expenses', debit: 20000, credit: 0 },
  ];

  return (
    <ReportTable
      columns={columns}
      data={data}
      showPagination={false}
      showSearch={true}
      emptyMessage="No trial balance data available"
    />
  );
};

// Example 5: Large Dataset with Pagination
export const LargeDatasetExample = () => {
  const columns: Column[] = [
    {
      key: 'date',
      label: 'Date',
      sortable: true,
      format: (value) => new Date(value).toLocaleDateString(),
    },
    {
      key: 'reference',
      label: 'Reference',
      sortable: true,
      filterable: true,
    },
    {
      key: 'description',
      label: 'Description',
      sortable: true,
      filterable: true,
    },
    {
      key: 'debit',
      label: 'Debit',
      sortable: true,
      align: 'right',
      format: (value) => value > 0 ? `$${Number(value).toFixed(2)}` : '-',
    },
    {
      key: 'credit',
      label: 'Credit',
      sortable: true,
      align: 'right',
      format: (value) => value > 0 ? `$${Number(value).toFixed(2)}` : '-',
    },
    {
      key: 'balance',
      label: 'Balance',
      sortable: true,
      align: 'right',
      format: (value) => `$${Number(value).toFixed(2)}`,
      className: 'font-semibold',
    },
  ];

  // Generate sample data
  const data = Array.from({ length: 150 }, (_, i) => ({
    date: new Date(2024, 0, 1 + i).toISOString(),
    reference: `JE-${String(i + 1).padStart(4, '0')}`,
    description: `Transaction ${i + 1}`,
    debit: i % 2 === 0 ? Math.random() * 1000 : 0,
    credit: i % 2 === 1 ? Math.random() * 1000 : 0,
    balance: Math.random() * 10000,
  }));

  return (
    <ReportTable
      columns={columns}
      data={data}
      showPagination={true}
      showSearch={true}
      showFilters={true}
      pageSize={20}
    />
  );
};
