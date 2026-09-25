import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import reportApi from '@/lib/reportApi';
import { TagFilterSelect } from '@/components/tags/TagFilterSelect';
import { formatCurrency } from '@/lib/utils';

interface AgedReceivablesFilters {
  search?: string;
  min_amount?: number;
  aging_bucket?: string;
  tag?: string;
}

export function AgedReceivablesPage() {
  const navigate = useNavigate();
  const reportRef = useRef<HTMLDivElement>(null);
  const [filters, setFilters] = useState<AgedReceivablesFilters>({});

  // Fetch aged receivables data
  const {
    data: receivablesData,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['aged-receivables', filters],
    queryFn: () => reportApi.getAgedReceivables(filters),
  });


  // Handle filter changes
  const handleSearchChange = (value: string) => {
    setFilters((prev) => ({ ...prev, search: value || undefined }));
  };

  const handleMinAmountChange = (value: string) => {
    const amount = value ? parseFloat(value) : undefined;
    setFilters((prev) => ({ ...prev, min_amount: amount }));
  };

  const handleAgingBucketChange = (value: string) => {
    setFilters((prev) => ({
      ...prev,
      aging_bucket: value === 'all' ? undefined : value,
    }));
  };

  const handleClearFilters = () => {
    setFilters({});
  };

  // Handle customer row click
  const handleCustomerClick = (customerId: number) => {
    navigate(`/accounting/customers/bills?customer_id=${customerId}`);
  };

  // Handle aging bucket amount click
  const handleAmountClick = (customerId: number, bucket: string) => {
    navigate(
      `/accounting/customers/bills?customer_id=${customerId}&aging_bucket=${bucket}`
    );
  };

  // Calculate filtered summary totals
  const filteredSummary = receivablesData?.summary || {
    total_customers: 0,
    total_outstanding: 0,
    current: 0,
    days_1_30: 0,
    days_31_60: 0,
    days_61_90: 0,
    days_90_plus: 0,
  };

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Aged Receivables</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            View outstanding customer balances by aging period
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <ReportExportToolbar
            targetRef={reportRef}
            title="Aged Receivables"
            subtitle="Outstanding customer balances by aging period"
            filename={`aged-receivables-${new Date().toISOString().split('T')[0]}`}
          />
          <HeaderActionButton icon="solar:refresh-bold" variant="outline" onClick={() => refetch()}>
            Refresh
          </HeaderActionButton>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Search by customer name..."
          value={filters.search || ''}
          onChange={(e) => handleSearchChange(e.target.value)}
          className="h-8 w-64 rounded-full px-3 text-xs"
        />
        <Input
          type="number"
          placeholder="0.00"
          value={filters.min_amount || ''}
          onChange={(e) => handleMinAmountChange(e.target.value)}
          className="h-8 w-28 rounded-full px-3 text-xs"
        />
        <Select
          value={filters.aging_bucket || 'all'}
          onValueChange={handleAgingBucketChange}
        >
          <SelectTrigger className="h-8 w-40 rounded-full text-xs">
            <SelectValue placeholder="All Buckets" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Buckets</SelectItem>
            <SelectItem value="current">Current</SelectItem>
            <SelectItem value="days_1_30">1-30 Days</SelectItem>
            <SelectItem value="days_31_60">31-60 Days</SelectItem>
            <SelectItem value="days_61_90">61-90 Days</SelectItem>
            <SelectItem value="days_90_plus">90+ Days</SelectItem>
          </SelectContent>
        </Select>
        <TagFilterSelect
          className="h-8 w-40 rounded-full text-xs"
          value={filters.tag || ''}
          onChange={(value) =>
            setFilters((prev) => ({ ...prev, tag: value || undefined }))
          }
        />
        <Button
          variant="outline"
          size="icon"
          onClick={handleClearFilters}
          className="h-8 w-8 rounded-full"
          title="Clear filters"
          aria-label="Clear filters"
        >
          <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
        </Button>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-6 gap-4">
        <Card className="p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600 dark:text-gray-400">
                Total Outstanding
              </p>
              <p className="text-2xl font-bold mt-1">
                {formatCurrency(filteredSummary.total_outstanding)}
              </p>
            </div>
            <Icon
              icon="solar:dollar-bold-duotone"
              className="h-8 w-8 text-blue-500"
            />
          </div>
        </Card>

        <Card className="p-4">
          <div>
            <p className="text-sm text-gray-600 dark:text-gray-400">Current</p>
            <p className="text-xl font-bold mt-1 text-green-600">
              {formatCurrency(filteredSummary.current)}
            </p>
            <p className="text-xs text-gray-500 mt-1">
              {filteredSummary.total_outstanding > 0
                ? (
                    (filteredSummary.current / filteredSummary.total_outstanding) *
                    100
                  ).toFixed(1)
                : 0}
              %
            </p>
          </div>
        </Card>

        <Card className="p-4">
          <div>
            <p className="text-sm text-gray-600 dark:text-gray-400">1-30 Days</p>
            <p className="text-xl font-bold mt-1 text-yellow-600">
              {formatCurrency(filteredSummary.days_1_30)}
            </p>
            <p className="text-xs text-gray-500 mt-1">
              {filteredSummary.total_outstanding > 0
                ? (
                    (filteredSummary.days_1_30 / filteredSummary.total_outstanding) *
                    100
                  ).toFixed(1)
                : 0}
              %
            </p>
          </div>
        </Card>

        <Card className="p-4">
          <div>
            <p className="text-sm text-gray-600 dark:text-gray-400">31-60 Days</p>
            <p className="text-xl font-bold mt-1 text-orange-600">
              {formatCurrency(filteredSummary.days_31_60)}
            </p>
            <p className="text-xs text-gray-500 mt-1">
              {filteredSummary.total_outstanding > 0
                ? (
                    (filteredSummary.days_31_60 / filteredSummary.total_outstanding) *
                    100
                  ).toFixed(1)
                : 0}
              %
            </p>
          </div>
        </Card>

        <Card className="p-4">
          <div>
            <p className="text-sm text-gray-600 dark:text-gray-400">61-90 Days</p>
            <p className="text-xl font-bold mt-1 text-red-600">
              {formatCurrency(filteredSummary.days_61_90)}
            </p>
            <p className="text-xs text-gray-500 mt-1">
              {filteredSummary.total_outstanding > 0
                ? (
                    (filteredSummary.days_61_90 / filteredSummary.total_outstanding) *
                    100
                  ).toFixed(1)
                : 0}
              %
            </p>
          </div>
        </Card>

        <Card className="p-4">
          <div>
            <p className="text-sm text-gray-600 dark:text-gray-400">90+ Days</p>
            <p className="text-xl font-bold mt-1 text-red-800">
              {formatCurrency(filteredSummary.days_90_plus)}
            </p>
            <p className="text-xs text-gray-500 mt-1">
              {filteredSummary.total_outstanding > 0
                ? (
                    (filteredSummary.days_90_plus /
                      filteredSummary.total_outstanding) *
                    100
                  ).toFixed(1)
                : 0}
              %
            </p>
          </div>
        </Card>
      </div>

      {/* Data Table */}
      <Card>
        <div className="p-6" ref={reportRef}>
          <h2 className="text-lg font-semibold mb-4">Customer Details</h2>

          {/* Loading State */}
          {isLoading && (
            <div className="flex items-center justify-center py-12">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
            </div>
          )}

          {/* Error State */}
          {error && (
            <div className="flex flex-col items-center justify-center py-12">
              <Icon
                icon="solar:danger-triangle-bold"
                className="h-12 w-12 text-red-500 mb-4"
              />
              <p className="text-red-600 font-medium">Failed to load data</p>
              <p className="text-gray-600 text-sm mt-1">
                {error instanceof Error ? error.message : 'Unknown error'}
              </p>
              <Button onClick={() => refetch()} className="mt-4">
                Try Again
              </Button>
            </div>
          )}

          {/* Data Table */}
          {!isLoading && !error && receivablesData && (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b">
                    <th className="text-left py-3 px-4 font-semibold">
                      Customer
                    </th>
                    <th className="text-right py-3 px-4 font-semibold">
                      Current
                    </th>
                    <th className="text-right py-3 px-4 font-semibold">
                      1-30 Days
                    </th>
                    <th className="text-right py-3 px-4 font-semibold">
                      31-60 Days
                    </th>
                    <th className="text-right py-3 px-4 font-semibold">
                      61-90 Days
                    </th>
                    <th className="text-right py-3 px-4 font-semibold">
                      90+ Days
                    </th>
                    <th className="text-right py-3 px-4 font-semibold">
                      Total
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {receivablesData.data.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="text-center py-12">
                        <Icon
                          icon="solar:inbox-bold-duotone"
                          className="h-12 w-12 text-gray-400 mx-auto mb-4"
                        />
                        <p className="text-gray-600">No data available</p>
                      </td>
                    </tr>
                  ) : (
                    receivablesData.data.map((customer: any) => (
                      <tr
                        key={customer.customer_id}
                        className="border-b hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer"
                        onClick={() => handleCustomerClick(customer.customer_id)}
                      >
                        <td className="py-3 px-4">
                          <div>
                            <p className="font-medium">{customer.customer_name}</p>
                            {customer.contact_email && (
                              <p className="text-sm text-gray-600">
                                {customer.contact_email}
                              </p>
                            )}
                          </div>
                        </td>
                        <td
                          className="text-right py-3 px-4 text-green-600 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (customer.current > 0) {
                              handleAmountClick(customer.customer_id, 'current');
                            }
                          }}
                        >
                          {formatCurrency(customer.current)}
                        </td>
                        <td
                          className="text-right py-3 px-4 text-yellow-600 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (customer.days_1_30 > 0) {
                              handleAmountClick(customer.customer_id, 'days_1_30');
                            }
                          }}
                        >
                          {formatCurrency(customer.days_1_30)}
                        </td>
                        <td
                          className="text-right py-3 px-4 text-orange-600 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (customer.days_31_60 > 0) {
                              handleAmountClick(customer.customer_id, 'days_31_60');
                            }
                          }}
                        >
                          {formatCurrency(customer.days_31_60)}
                        </td>
                        <td
                          className="text-right py-3 px-4 text-red-600 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (customer.days_61_90 > 0) {
                              handleAmountClick(customer.customer_id, 'days_61_90');
                            }
                          }}
                        >
                          {formatCurrency(customer.days_61_90)}
                        </td>
                        <td
                          className="text-right py-3 px-4 text-red-800 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (customer.days_90_plus > 0) {
                              handleAmountClick(
                                customer.customer_id,
                                'days_90_plus'
                              );
                            }
                          }}
                        >
                          {formatCurrency(customer.days_90_plus)}
                        </td>
                        <td className="text-right py-3 px-4 font-bold">
                          {formatCurrency(customer.total)}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </Card>
    </div>
  );
}
