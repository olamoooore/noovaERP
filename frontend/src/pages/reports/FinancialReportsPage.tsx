import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { ReportViewer } from '@/components/reports/ReportViewer';
import { ReportFilters } from '@/components/reports/ReportFilters';
import { ReportTable, Column } from '@/components/reports/ReportTable';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';

export function FinancialReportsPage() {
  const [activeTab, setActiveTab] = useState('profit-loss');
  const [filters, setFilters] = useState<ReportFiltersType>({
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1)
      .toISOString()
      .split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
  });

  // Profit & Loss Query
  const {
    data: profitLossData,
    isLoading: isPLLoading,
    error: plError,
    refetch: refetchPL,
  } = useQuery({
    queryKey: ['profit-loss', filters],
    queryFn: () => reportApi.getProfitLoss(filters),
    enabled: activeTab === 'profit-loss',
  });

  // Balance Sheet Query
  const {
    data: balanceSheetData,
    isLoading: isBSLoading,
    error: bsError,
    refetch: refetchBS,
  } = useQuery({
    queryKey: ['balance-sheet', filters],
    queryFn: () => reportApi.getBalanceSheet(filters),
    enabled: activeTab === 'balance-sheet',
  });

  // Cash Flow Query
  const {
    data: cashFlowData,
    isLoading: isCFLoading,
    error: cfError,
    refetch: refetchCF,
  } = useQuery({
    queryKey: ['cash-flow', filters],
    queryFn: () => reportApi.getCashFlow(filters),
    enabled: activeTab === 'cash-flow',
  });

  // Trial Balance Query
  const {
    data: trialBalanceData,
    isLoading: isTBLoading,
    error: tbError,
    refetch: refetchTB,
  } = useQuery({
    queryKey: ['trial-balance', filters],
    queryFn: () => reportApi.getTrialBalance(filters),
    enabled: activeTab === 'trial-balance',
  });

  // General Ledger Query
  const {
    data: generalLedgerData,
    isLoading: isGLLoading,
    error: glError,
    refetch: refetchGL,
  } = useQuery({
    queryKey: ['general-ledger', filters],
    queryFn: () => reportApi.getGeneralLedger(filters),
    enabled: activeTab === 'general-ledger',
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

  // Profit & Loss Columns
  const plColumns: Column[] = [
    {
      key: 'name',
      label: 'Account',
      sortable: true,
      filterable: true,
    },
    {
      key: 'amount',
      label: 'Amount',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
  ];

  // Balance Sheet Columns
  const bsColumns: Column[] = [
    {
      key: 'code',
      label: 'Code',
      sortable: true,
    },
    {
      key: 'name',
      label: 'Account',
      sortable: true,
      filterable: true,
    },
    {
      key: 'balance',
      label: 'Balance',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
  ];

  // Cash Flow Columns
  const cfColumns: Column[] = [
    {
      key: 'description',
      label: 'Description',
      sortable: true,
      filterable: true,
    },
    {
      key: 'amount',
      label: 'Amount',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
  ];

  // Trial Balance Columns
  const tbColumns: Column[] = [
    {
      key: 'code',
      label: 'Code',
      sortable: true,
    },
    {
      key: 'name',
      label: 'Account',
      sortable: true,
      filterable: true,
    },
    {
      key: 'account_type',
      label: 'Type',
      sortable: true,
      filterable: true,
    },
    {
      key: 'debit',
      label: 'Debit',
      sortable: true,
      align: 'right',
      format: (value) => (value > 0 ? formatCurrency(value) : '-'),
    },
    {
      key: 'credit',
      label: 'Credit',
      sortable: true,
      align: 'right',
      format: (value) => (value > 0 ? formatCurrency(value) : '-'),
    },
  ];

  // General Ledger Columns
  const glColumns: Column[] = [
    {
      key: 'date',
      label: 'Date',
      sortable: true,
      format: (value) => formatDate(value),
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
      format: (value) => (value > 0 ? formatCurrency(value) : '-'),
    },
    {
      key: 'credit',
      label: 'Credit',
      sortable: true,
      align: 'right',
      format: (value) => (value > 0 ? formatCurrency(value) : '-'),
    },
    {
      key: 'balance',
      label: 'Balance',
      sortable: true,
      align: 'right',
      format: (value) => formatCurrency(value),
    },
  ];

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Financial Reports</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            View comprehensive financial statements and reports
          </p>
        </div>
      </div>

      {/* Filters */}
      <ReportFilters
        onFilterChange={handleFilterChange}
        initialFilters={filters}
        showDateRange
        showComparePeriod
        showDepartment
      />

      {/* Reports Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-5">
          <TabsTrigger value="profit-loss">
            <Icon icon="solar:chart-bold" className="mr-2 h-4 w-4" />
            P&L
          </TabsTrigger>
          <TabsTrigger value="balance-sheet">
            <Icon icon="solar:balance-bold" className="mr-2 h-4 w-4" />
            Balance Sheet
          </TabsTrigger>
          <TabsTrigger value="cash-flow">
            <Icon icon="solar:dollar-minimalistic-bold" className="mr-2 h-4 w-4" />
            Cash Flow
          </TabsTrigger>
          <TabsTrigger value="trial-balance">
            <Icon icon="solar:calculator-bold" className="mr-2 h-4 w-4" />
            Trial Balance
          </TabsTrigger>
          <TabsTrigger value="general-ledger">
            <Icon icon="solar:book-bold" className="mr-2 h-4 w-4" />
            General Ledger
          </TabsTrigger>
        </TabsList>

        {/* Profit & Loss Tab */}
        <TabsContent value="profit-loss">
          <ReportViewer
            title="Profit & Loss Statement"
            description={`For the period ${formatDate(filters.start_date || '')} to ${formatDate(filters.end_date || '')}`}
            isLoading={isPLLoading}
            error={plError?.message || null}
            onRefresh={refetchPL}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {profitLossData && (
              <div className="space-y-6">
                {/* Revenue Section */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">Revenue</h3>
                  <ReportTable
                    columns={plColumns}
                    data={profitLossData?.revenue?.accounts ?? []}
                    showPagination={false}
                    showSearch={false}
                  />
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Total Revenue: </span>
                      <span className="text-lg font-bold text-green-600">
                        {formatCurrency(profitLossData?.revenue?.total ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Cost of Goods Sold Section */}
                {(profitLossData?.cost_of_goods_sold?.accounts?.length ?? 0) > 0 && (
                  <div>
                    <h3 className="text-lg font-semibold mb-4 text-primary">Cost of Goods Sold</h3>
                    <ReportTable
                      columns={plColumns}
                      data={profitLossData?.cost_of_goods_sold?.accounts ?? []}
                      showPagination={false}
                      showSearch={false}
                    />
                    <div className="flex justify-end mt-2 pr-4">
                      <div className="text-right">
                        <span className="font-semibold">Total COGS: </span>
                        <span className="text-lg font-bold text-red-600">
                          {formatCurrency(profitLossData?.cost_of_goods_sold?.total ?? 0)}
                        </span>
                      </div>
                    </div>
                  </div>
                )}

                {/* Operating Expenses Section */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">Operating Expenses</h3>
                  <ReportTable
                    columns={plColumns}
                    data={profitLossData?.operating_expenses?.accounts ?? profitLossData?.expenses?.accounts ?? []}
                    showPagination={false}
                    showSearch={false}
                  />
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Total Operating Expenses: </span>
                      <span className="text-lg font-bold text-red-600">
                        {formatCurrency(profitLossData?.operating_expenses?.total ?? profitLossData?.expenses?.total ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Other Income Section */}
                {(profitLossData?.other_income?.accounts?.length ?? 0) > 0 && (
                  <div>
                    <h3 className="text-lg font-semibold mb-4 text-primary">Other Income</h3>
                    <ReportTable
                      columns={plColumns}
                      data={profitLossData?.other_income?.accounts ?? []}
                      showPagination={false}
                      showSearch={false}
                    />
                    <div className="flex justify-end mt-2 pr-4">
                      <div className="text-right">
                        <span className="font-semibold">Total Other Income: </span>
                        <span className="text-lg font-bold text-green-600">
                          {formatCurrency(profitLossData?.other_income?.total ?? 0)}
                        </span>
                      </div>
                    </div>
                  </div>
                )}

                {/* Other Expenses Section */}
                {(profitLossData?.other_expenses?.accounts?.length ?? 0) > 0 && (
                  <div>
                    <h3 className="text-lg font-semibold mb-4 text-primary">Other Expenses</h3>
                    <ReportTable
                      columns={plColumns}
                      data={profitLossData?.other_expenses?.accounts ?? []}
                      showPagination={false}
                      showSearch={false}
                    />
                    <div className="flex justify-end mt-2 pr-4">
                      <div className="text-right">
                        <span className="font-semibold">Total Other Expenses: </span>
                        <span className="text-lg font-bold text-red-600">
                          {formatCurrency(profitLossData?.other_expenses?.total ?? 0)}
                        </span>
                      </div>
                    </div>
                  </div>
                )}

                {/* Summary Section */}
                <Card className="p-6 bg-gray-50 dark:bg-gray-800">
                  <div className="space-y-3">
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Gross Profit:</span>
                      <span className="text-lg font-bold">
                        {formatCurrency(typeof profitLossData?.gross_profit === 'object' ? profitLossData?.gross_profit?.amount ?? 0 : profitLossData?.gross_profit ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Operating Profit:</span>
                      <span className="text-lg font-bold">
                        {formatCurrency(typeof profitLossData?.operating_profit === 'object' ? profitLossData?.operating_profit?.amount ?? 0 : profitLossData?.operating_profit ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center pt-3 border-t">
                      <span className="text-xl font-bold">Net Profit:</span>
                      {(() => {
                        const netVal = typeof profitLossData?.net_profit === 'object' ? profitLossData?.net_profit?.amount ?? 0 : profitLossData?.net_profit ?? 0;
                        return (
                          <span className={`text-2xl font-bold ${netVal >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                            {formatCurrency(netVal)}
                          </span>
                        );
                      })()}
                    </div>
                  </div>
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Balance Sheet Tab */}
        <TabsContent value="balance-sheet">
          <ReportViewer
            title="Balance Sheet"
            description={`As of ${formatDate(filters.end_date || '')}`}
            isLoading={isBSLoading}
            error={bsError?.message || null}
            onRefresh={refetchBS}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {balanceSheetData && (
              <div className="space-y-6">
                {/* Assets Section */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">Assets</h3>
                  <div className="space-y-4">
                    <div>
                      <h4 className="font-medium mb-2">Current Assets</h4>
                      <ReportTable
                        columns={bsColumns}
                        data={balanceSheetData?.assets?.current ?? []}
                        showPagination={false}
                        showSearch={false}
                      />
                    </div>
                    <div>
                      <h4 className="font-medium mb-2">Non-Current Assets</h4>
                      <ReportTable
                        columns={bsColumns}
                        data={balanceSheetData?.assets?.non_current ?? []}
                        showPagination={false}
                        showSearch={false}
                      />
                    </div>
                  </div>
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Total Assets: </span>
                      <span className="text-lg font-bold text-blue-600">
                        {formatCurrency(balanceSheetData?.total_assets ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Liabilities Section */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">Liabilities</h3>
                  <div className="space-y-4">
                    <div>
                      <h4 className="font-medium mb-2">Current Liabilities</h4>
                      <ReportTable
                        columns={bsColumns}
                        data={balanceSheetData?.liabilities?.current ?? []}
                        showPagination={false}
                        showSearch={false}
                      />
                    </div>
                    <div>
                      <h4 className="font-medium mb-2">Non-Current Liabilities</h4>
                      <ReportTable
                        columns={bsColumns}
                        data={balanceSheetData?.liabilities?.non_current ?? []}
                        showPagination={false}
                        showSearch={false}
                      />
                    </div>
                  </div>
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Total Liabilities: </span>
                      <span className="text-lg font-bold text-orange-600">
                        {formatCurrency(balanceSheetData?.total_liabilities ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Equity Section */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">Equity</h3>
                  <ReportTable
                    columns={bsColumns}
                    data={balanceSheetData?.equity?.accounts ?? []}
                    showPagination={false}
                    showSearch={false}
                  />
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Total Equity: </span>
                      <span className="text-lg font-bold text-purple-600">
                        {formatCurrency(balanceSheetData?.total_equity ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Summary Section */}
                <Card className="p-6 bg-gray-50 dark:bg-gray-800">
                  <div className="space-y-3">
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Working Capital:</span>
                      <span className="text-lg font-bold">
                        {formatCurrency(balanceSheetData?.working_capital ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center pt-3 border-t">
                      <span className="text-xl font-bold">
                        Total Liabilities + Equity:
                      </span>
                      <span className="text-2xl font-bold">
                        {formatCurrency(
                          (balanceSheetData?.total_liabilities ?? 0) +
                            (balanceSheetData?.total_equity ?? 0)
                        )}
                      </span>
                    </div>
                    {(balanceSheetData?.balance_check ?? 0) !== 0 && (
                      <div className="flex justify-between items-center text-red-600">
                        <span className="font-semibold">Balance Check:</span>
                        <span className="font-bold">
                          {formatCurrency(balanceSheetData?.balance_check ?? 0)}
                        </span>
                      </div>
                    )}
                  </div>
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Cash Flow Tab */}
        <TabsContent value="cash-flow">
          <ReportViewer
            title="Cash Flow Statement"
            description={`For the period ${formatDate(filters.start_date || '')} to ${formatDate(filters.end_date || '')}`}
            isLoading={isCFLoading}
            error={cfError?.message || null}
            onRefresh={refetchCF}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {cashFlowData && (
              <div className="space-y-6">
                {/* Operating Activities */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">
                    Operating Activities
                  </h3>
                  <ReportTable
                    columns={cfColumns}
                    data={cashFlowData?.operating_activities?.items ?? []}
                    showPagination={false}
                    showSearch={false}
                  />
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Net Cash from Operations: </span>
                      <span className="text-lg font-bold">
                        {formatCurrency(cashFlowData?.operating_activities?.total ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Investing Activities */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">
                    Investing Activities
                  </h3>
                  <ReportTable
                    columns={cfColumns}
                    data={cashFlowData?.investing_activities?.items ?? []}
                    showPagination={false}
                    showSearch={false}
                  />
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Net Cash from Investing: </span>
                      <span className="text-lg font-bold">
                        {formatCurrency(cashFlowData?.investing_activities?.total ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Financing Activities */}
                <div>
                  <h3 className="text-lg font-semibold mb-4 text-primary">
                    Financing Activities
                  </h3>
                  <ReportTable
                    columns={cfColumns}
                    data={cashFlowData?.financing_activities?.items ?? []}
                    showPagination={false}
                    showSearch={false}
                  />
                  <div className="flex justify-end mt-2 pr-4">
                    <div className="text-right">
                      <span className="font-semibold">Net Cash from Financing: </span>
                      <span className="text-lg font-bold">
                        {formatCurrency(cashFlowData?.financing_activities?.total ?? 0)}
                      </span>
                    </div>
                  </div>
                </div>

                {/* Summary Section */}
                <Card className="p-6 bg-gray-50 dark:bg-gray-800">
                  <div className="space-y-3">
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Opening Cash:</span>
                      <span className="text-lg font-bold">
                        {formatCurrency(cashFlowData?.opening_cash ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Net Cash Flow:</span>
                      <span
                        className={`text-lg font-bold ${
                          (cashFlowData?.net_cash_flow ?? 0) >= 0
                            ? 'text-green-600'
                            : 'text-red-600'
                        }`}
                      >
                        {formatCurrency(cashFlowData?.net_cash_flow ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center pt-3 border-t">
                      <span className="text-xl font-bold">Closing Cash:</span>
                      <span className="text-2xl font-bold text-blue-600">
                        {formatCurrency(cashFlowData?.closing_cash ?? 0)}
                      </span>
                    </div>
                  </div>
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* Trial Balance Tab */}
        <TabsContent value="trial-balance">
          <ReportViewer
            title="Trial Balance"
            description={`As of ${formatDate(filters.end_date || '')}`}
            isLoading={isTBLoading}
            error={tbError?.message || null}
            onRefresh={refetchTB}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {trialBalanceData && (
              <div className="space-y-6">
                <ReportTable
                  columns={tbColumns}
                  data={trialBalanceData?.accounts ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />

                {/* Totals Section */}
                <Card className="p-6 bg-gray-50 dark:bg-gray-800">
                  <div className="space-y-3">
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Total Debits:</span>
                      <span className="text-lg font-bold">
                        {formatCurrency(trialBalanceData?.total_debits ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="font-semibold">Total Credits:</span>
                      <span className="text-lg font-bold">
                        {formatCurrency(trialBalanceData?.total_credits ?? 0)}
                      </span>
                    </div>
                    <div className="flex justify-between items-center pt-3 border-t">
                      <span className="text-xl font-bold">Balance Status:</span>
                      <span
                        className={`text-xl font-bold ${
                          trialBalanceData?.is_balanced
                            ? 'text-green-600'
                            : 'text-red-600'
                        }`}
                      >
                        {trialBalanceData?.is_balanced ? 'Balanced ✓' : 'Not Balanced ✗'}
                      </span>
                    </div>
                  </div>
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>

        {/* General Ledger Tab */}
        <TabsContent value="general-ledger">
          <ReportViewer
            title="General Ledger"
            description={`For the period ${formatDate(filters.start_date || '')} to ${formatDate(filters.end_date || '')}`}
            isLoading={isGLLoading}
            error={glError?.message || null}
            onRefresh={refetchGL}
            onExport={handleExport}
            onPrint={handlePrint}
          >
            {generalLedgerData && (
              <div className="space-y-6">
                {/* Account Info */}
                {generalLedgerData?.account && (
                  <Card className="p-4 bg-blue-50 dark:bg-blue-900/20">
                    <div className="flex items-center justify-between">
                      <div>
                        <h3 className="font-semibold text-lg">
                          {generalLedgerData.account.name}
                        </h3>
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                          Account Code: {generalLedgerData.account.code}
                        </p>
                      </div>
                      <div className="text-right">
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                          Opening Balance
                        </p>
                        <p className="text-lg font-bold">
                          {formatCurrency(generalLedgerData?.opening_balance ?? 0)}
                        </p>
                      </div>
                    </div>
                  </Card>
                )}

                {/* Transactions */}
                <ReportTable
                  columns={glColumns}
                  data={generalLedgerData?.transactions ?? []}
                  showPagination
                  showSearch
                  pageSize={20}
                />

                {/* Closing Balance */}
                <Card className="p-6 bg-gray-50 dark:bg-gray-800">
                  <div className="flex justify-between items-center">
                    <span className="text-xl font-bold">Closing Balance:</span>
                    <span className="text-2xl font-bold text-blue-600">
                      {formatCurrency(generalLedgerData?.closing_balance ?? 0)}
                    </span>
                  </div>
                </Card>
              </div>
            )}
          </ReportViewer>
        </TabsContent>
      </Tabs>
    </div>
  );
}
