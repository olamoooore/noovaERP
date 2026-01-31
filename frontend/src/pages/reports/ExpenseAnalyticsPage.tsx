import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ReportViewer } from '@/components/reports/ReportViewer';
import { ReportFilters } from '@/components/reports/ReportFilters';
import { ReportTable, Column } from '@/components/reports/ReportTable';
import { BarChart } from '@/components/charts/BarChart';
import { PieChart } from '@/components/charts/PieChart';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatNumber } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';

export function ExpenseAnalyticsPage() {
  const [activeTab, setActiveTab] = useState('by-category');
  const [filters, setFilters] = useState<ReportFiltersType>({
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1)
      .toISOString()
      .split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
  });

  // Expenses by Category Query
  const {
    data: categoryData,
    isLoading: isCategoryLoading,
    error: categoryError,
    refetch: refetchCategory,
  } = useQuery({
    queryKey: ['expenses-by-category', filters],
    queryFn: () => reportApi.getExpensesByCategory(filters),
    enabled: activeTab === 'by-category',
  });

  // Expenses by Vendor Query
  const {
    data: vendorData,
    isLoading: isVendorLoading,
    error: vendorError,
    refetch: refetchVendor,
  } = useQuery({
    queryKey: ['expenses-by-vendor', filters],
    queryFn: () => reportApi.getExpensesByVendor(filters),
    enabled: activeTab === 'by-vendor',
  });

  // Expenses by Department Query
  const {
    data: departmentData,
    isLoading: isDepartmentLoading,
    error: departmentError,
    refetch: refetchDepartment,
  } = useQuery({
    queryKey: ['expenses-by-department', filters],
    queryFn: () => reportApi.getExpensesByDepartment(filters),
    enabled: activeTab === 'by-department',
  });

  const handleFilterChange = (newFilters: ReportFiltersType) => {
    setFilters(newFilters);
  };

  const handleExport = () => {
    // TODO: Implement export functionality
    console.log('Export report:', activeTab);
  };

  const handlePrint = () => {
    window.print();
  };

  // Expenses by Category Columns
  const categoryColumns: Column[] = [
    {
      key: 'account_name',
      label: 'Account',
      sortable: true,
      filterable: true,
    },
    {
      key: 'account_type',
      label: 'Type',
      sortable: true,
      filterable: true,
      format: (value) => (
        <span className="px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200">
          {value}
        </span>
      ),
    },
    {
      key: 'total_amount',
      label: 'Amount',
      sortable: true,
      align: 'right',
      format: (value) => (
        <span className="font-semibold text-red-600">
          {formatCurrency(value)}
        </span>
      ),
    },
    {
      key: 'percentage',
      label: 'Percentage',
      sortable: true,
      align: 'right',
      format: (value) => (
        <div className="flex items-center justify-end gap-2">
          <div className="w-24 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
            <div
              className="bg-red-600 h-2 rounded-full"
              style={{ width: `${value}%` }}
            />
          </div>
          <span className="font-semibold">{value.toFixed(1)}%</span>
        </div>
      ),
    },
  ];

  // Expenses by Vendor Columns
  const vendorColumns: Column[] = [
    {
      key: 'vendor_name',
      label: 'Vendor',
      sortable: true,
      filterable: true,
    },
    {
      key: 'invoice_count',
      label: 'Invoices',
      sortable: true,
      align: 'right',
    },
    {
      key: 'total_spending',
      label: 'Total Spending',
      sortable: true,
      align: 'right',
      format: (value) => (
        <span className="font-semibold text-red-600">
          {formatCurrency(value)}
        </span>
      ),
    },
    {
      key: 'average_invoice',
      label: 'Avg Invoice',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
  ];

  // Expenses by Department Columns
  const departmentColumns: Column[] = [
    {
      key: 'department_name',
      label: 'Department',
      sortable: true,
      filterable: true,
    },
    {
      key: 'total_expenses',
      label: 'Total Expenses',
      sortable: true,
      align: 'right',
      format: (value) => (
        <span className="font-semibold text-red-600">
          {formatCurrency(value)}
        </span>
      ),
    },
    {
      key: 'budget',
      label: 'Budget',
      sortable: true,
      align: 'right',
      format: (value) => (value ? formatCurrency(value) : '-'),
    },
    {
      key: 'variance',
      label: 'Variance',
      sortable: true,
      align: 'right',
      format: (value, row) => {
        if (!row.budget) return '-';
        const isOverBudget = value < 0;
        return (
          <span className={isOverBudget ? 'text-red-600 font-semibold' : 'text-green-600 font-semibold'}>
            {isOverBudget ? '' : '+'}{formatCurrency(value)}
          </span>
        );
      },
    },
  ];

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Expense Analytics</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            Analyze expenses by category, vendor, and department
          </p>
        </div>
      </div>

      {/* Filters */}
      <ReportFilters
        onFilterChange={handleFilterChange}
        initialFilters={filters}
        showDateRange
      />

      {/* Analytics Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="by-category">
            <Icon icon="solar:folder-bold" className="mr-2 h-4 w-4" />
            By Category
          </TabsTrigger>
          <TabsTrigger value="by-vendor">
            <Icon icon="solar:shop-bold" className="mr-2 h-4 w-4" />
            By Vendor
          </TabsTrigger>
          <TabsTrigger value="by-department">
            <Icon icon="solar:buildings-2-bold" className="mr-2 h-4 w-4" />
            By Department
          </TabsTrigger>
        </TabsList>

        {/* Expenses by Category Tab */}
        <TabsContent value="by-category">
          <ReportViewer
            title="Expenses by Category"
            description={`For the period ${filters.start_date} to ${filters.end_date}`}
            isLoading={isCategoryLoading}
            error={categoryError?.message || null}
            onRefresh={refetchCategory}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {categoryData && (
              <div className="space-y-6">
                {/* Expense Distribution Charts */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <Card className="p-6">
                    <h3 className="text-lg font-semibold mb-4">Expense Distribution</h3>
                    <PieChart
                      data={(categoryData?.categories ?? []).map((c) => ({
                        name: c.account_name,
                        value: c.total_amount,
                      }))}
                      height={350}
                      formatValue="currency"
                    />
                  </Card>

                  <Card className="p-6">
                    <h3 className="text-lg font-semibold mb-4">Top 10 Expense Categories</h3>
                    <BarChart
                      data={(categoryData?.categories ?? []).slice(0, 10).map((c) => ({
                        name: c.account_name,
                        Amount: c.total_amount,
                      }))}
                      xKey="name"
                      yKeys={['Amount']}
                      height={350}
                      formatYAxis="currency"
                      horizontal
                    />
                  </Card>
                </div>

                {/* Summary Card */}
                <Card className="p-6 bg-red-50 dark:bg-red-900/20">
                  <div className="flex justify-between items-center">
                    <div>
                      <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                        Total Expenses
                      </div>
                      <div className="text-3xl font-bold text-red-600">
                        {formatCurrency(
                          (categoryData?.categories ?? []).reduce((sum, c) => sum + c.total_amount, 0)
                        )}
                      </div>
                    </div>
                    <div className="text-right">
                      <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                        Categories
                      </div>
                      <div className="text-3xl font-bold">
                        {(categoryData?.categories ?? []).length}
                      </div>
                    </div>
                  </div>
                </Card>

                {/* Categories Table */}
                <ReportTable
                  columns={categoryColumns}
                  data={categoryData?.categories ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Expenses by Vendor Tab */}
        <TabsContent value="by-vendor">
          <ReportViewer
            title="Expenses by Vendor"
            description={`For the period ${filters.start_date} to ${filters.end_date}`}
            isLoading={isVendorLoading}
            error={vendorError?.message || null}
            onRefresh={refetchVendor}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {vendorData && (
              <div className="space-y-6">
                {/* Top Vendors Chart */}
                <Card className="p-6">
                  <h3 className="text-lg font-semibold mb-4">Top 10 Vendors by Spending</h3>
                  <BarChart
                    data={(vendorData?.vendors ?? []).slice(0, 10).map((v) => ({
                      name: v.vendor_name,
                      Spending: v.total_spending,
                    }))}
                    xKey="name"
                    yKeys={['Spending']}
                    height={350}
                    formatYAxis="currency"
                    horizontal
                  />
                </Card>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                  <Card className="p-4">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Vendors
                    </div>
                    <div className="text-2xl font-bold">
                      {(vendorData?.vendors ?? []).length}
                    </div>
                  </Card>
                  <Card className="p-4 bg-red-50 dark:bg-red-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Spending
                    </div>
                    <div className="text-2xl font-bold text-red-600">
                      {formatCurrency(
                        (vendorData?.vendors ?? []).reduce((sum, v) => sum + v.total_spending, 0)
                      )}
                    </div>
                  </Card>
                  <Card className="p-4 bg-orange-50 dark:bg-orange-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Invoices
                    </div>
                    <div className="text-2xl font-bold text-orange-600">
                      {(vendorData?.vendors ?? []).reduce((sum, v) => sum + v.invoice_count, 0)}
                    </div>
                  </Card>
                  <Card className="p-4 bg-purple-50 dark:bg-purple-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Avg Invoice
                    </div>
                    <div className="text-2xl font-bold text-purple-600">
                      {formatCurrency(
                        (vendorData?.vendors ?? []).length > 0
                          ? (vendorData?.vendors ?? []).reduce((sum, v) => sum + v.average_invoice, 0) /
                              (vendorData?.vendors ?? []).length
                          : 0
                      )}
                    </div>
                  </Card>
                </div>

                {/* Vendors Table */}
                <ReportTable
                  columns={vendorColumns}
                  data={vendorData?.vendors ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Expenses by Department Tab */}
        <TabsContent value="by-department">
          <ReportViewer
            title="Expenses by Department"
            description={`For the period ${filters.start_date} to ${filters.end_date}`}
            isLoading={isDepartmentLoading}
            error={departmentError?.message || null}
            onRefresh={refetchDepartment}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {departmentData && (
              <div className="space-y-6">
                {/* Department Expenses Chart */}
                <Card className="p-6">
                  <h3 className="text-lg font-semibold mb-4">Department Expenses</h3>
                  <BarChart
                    data={(departmentData?.departments ?? []).map((d) => ({
                      name: d.department_name,
                      Expenses: d.total_expenses,
                      Budget: d.budget || 0,
                    }))}
                    xKey="name"
                    yKeys={['Expenses', 'Budget']}
                    height={350}
                    formatYAxis="currency"
                  />
                </Card>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <Card className="p-4">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Departments
                    </div>
                    <div className="text-2xl font-bold">
                      {(departmentData?.departments ?? []).length}
                    </div>
                  </Card>
                  <Card className="p-4 bg-red-50 dark:bg-red-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Expenses
                    </div>
                    <div className="text-2xl font-bold text-red-600">
                      {formatCurrency(
                        (departmentData?.departments ?? []).reduce((sum, d) => sum + d.total_expenses, 0)
                      )}
                    </div>
                  </Card>
                  <Card className="p-4 bg-blue-50 dark:bg-blue-900/20">
                    <div className="text-sm text-gray-600 dark:text-gray-400 mb-1">
                      Total Budget
                    </div>
                    <div className="text-2xl font-bold text-blue-600">
                      {formatCurrency(
                        (departmentData?.departments ?? []).reduce((sum, d) => sum + (d.budget || 0), 0)
                      )}
                    </div>
                  </Card>
                </div>

                {/* Budget Variance Alert */}
                {(departmentData?.departments ?? []).some((d) => d.variance && d.variance < 0) && (
                  <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:danger-triangle-bold" className="h-5 w-5 text-red-600" />
                      <span className="font-semibold text-red-800 dark:text-red-200">
                        {(departmentData?.departments ?? []).filter((d) => d.variance && d.variance < 0).length}{' '}
                        department(s) over budget
                      </span>
                    </div>
                  </div>
                )}

                {/* Departments Table */}
                <ReportTable
                  columns={departmentColumns}
                  data={departmentData?.departments ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />
              </div>
            )}
          </ReportViewer>
        </TabsContent>
      </Tabs>
    </div>
  );
}
