import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
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
import { useToast } from '@/hooks/use-toast';

interface AgedPayablesFilters {
  search?: string;
  min_amount?: number;
  aging_bucket?: string;
  tag?: string;
}

export function AgedPayablesPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const [filters, setFilters] = useState<AgedPayablesFilters>({});

  // Fetch aged payables data
  const {
    data: payablesData,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['aged-payables', filters],
    queryFn: () => reportApi.getAgedPayables(filters),
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

  // Handle vendor row click
  const handleVendorClick = (vendorId: number) => {
    navigate(`/accounting/vendors/bills?vendor_id=${vendorId}`);
  };

  // Handle aging bucket amount click
  const handleAmountClick = (vendorId: number, bucket: string) => {
    navigate(
      `/accounting/vendors/bills?vendor_id=${vendorId}&aging_bucket=${bucket}`
    );
  };

  // Handle export
  const handleExport = async (format: 'pdf' | 'excel') => {
    try {
      // Validate that we have data to export
      if (!payablesData || !payablesData.data || payablesData.data.length === 0) {
        toast({
          title: 'No Data to Export',
          description: 'There is no data available to export.',
          variant: 'destructive',
        });
        return;
      }

      toast({
        title: 'Export Started',
        description: `Exporting report to ${format.toUpperCase()}...`,
      });

      // Prepare export data with proper structure
      const exportData = {
        report_type: 'aged_payables',
        filters: {} as Record<string, unknown>,
        report_data: {
          title: 'Aged Payables Report',
          generated_at: new Date().toISOString(),
          filters: filters,
          summary: payablesData.summary,
          data: payablesData.data,
          columns: [
            { key: 'vendor_name', label: 'Vendor', format: 'text' },
            { key: 'current', label: 'Current', format: 'currency' },
            { key: 'days_1_30', label: '1-30 Days', format: 'currency' },
            { key: 'days_31_60', label: '31-60 Days', format: 'currency' },
            { key: 'days_61_90', label: '61-90 Days', format: 'currency' },
            { key: 'days_90_plus', label: '90+ Days', format: 'currency' },
            { key: 'total', label: 'Total', format: 'currency' },
          ],
        },
        filename: `aged-payables-${new Date().toISOString().split('T')[0]}`,
        orientation: 'landscape',
      };

      // Call appropriate export API
      let response;
      if (format === 'pdf') {
        response = await reportApi.exportToPdf(exportData);
      } else {
        response = await reportApi.exportToExcel(exportData);
      }

      // Handle successful export
      if (response.success && response.download_url) {
        // Trigger file download
        const link = document.createElement('a');
        link.href = response.download_url;
        link.download = response.filename || `aged-payables.${format}`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        toast({
          title: 'Export Complete',
          description: `Report exported successfully to ${format.toUpperCase()}`,
        });
      } else {
        throw new Error('Export failed: No download URL received');
      }
    } catch (error) {
      console.error('Export error:', error);
      toast({
        title: 'Export Failed',
        description: error instanceof Error ? error.message : 'Failed to export report. Please try again.',
        variant: 'destructive',
      });
    }
  };

  // Calculate filtered summary totals
  const filteredSummary = payablesData?.summary || {
    total_vendors: 0,
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
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Aged Payables</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            View outstanding vendor balances by aging period
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => handleExport('pdf')}>
            <Icon icon="solar:file-download-bold" className="mr-2 h-4 w-4" />
            Export PDF
          </Button>
          <Button variant="outline" onClick={() => handleExport('excel')}>
            <Icon icon="solar:file-download-bold" className="mr-2 h-4 w-4" />
            Export Excel
          </Button>
          <Button variant="outline" onClick={() => refetch()}>
            <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
            Refresh
          </Button>
        </div>
      </div>

      {/* Filters */}
      <Card className="p-4">
        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
          <div>
            <label className="text-sm font-medium mb-2 block">
              Search Vendor
            </label>
            <Input
              placeholder="Search by vendor name..."
              value={filters.search || ''}
              onChange={(e) => handleSearchChange(e.target.value)}
            />
          </div>
          <div>
            <label className="text-sm font-medium mb-2 block">
              Minimum Amount
            </label>
            <Input
              type="number"
              placeholder="0.00"
              value={filters.min_amount || ''}
              onChange={(e) => handleMinAmountChange(e.target.value)}
            />
          </div>
          <div>
            <label className="text-sm font-medium mb-2 block">
              Aging Bucket
            </label>
            <Select
              value={filters.aging_bucket || 'all'}
              onValueChange={handleAgingBucketChange}
            >
              <SelectTrigger>
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
          </div>
          <div>
            <label className="text-sm font-medium mb-2 block">
              Tag
            </label>
            <TagFilterSelect
              className="h-10 w-full"
              value={filters.tag || ''}
              onChange={(value) =>
                setFilters((prev) => ({ ...prev, tag: value || undefined }))
              }
            />
          </div>
          <div className="flex items-end">
            <Button
              variant="outline"
              onClick={handleClearFilters}
              className="w-full"
            >
              <Icon icon="solar:close-circle-bold" className="mr-2 h-4 w-4" />
              Clear Filters
            </Button>
          </div>
        </div>
      </Card>

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
        <div className="p-6">
          <h2 className="text-lg font-semibold mb-4">Vendor Details</h2>

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
          {!isLoading && !error && payablesData && (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b">
                    <th className="text-left py-3 px-4 font-semibold">
                      Vendor
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
                  {payablesData.data.length === 0 ? (
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
                    payablesData.data.map((vendor: any) => (
                      <tr
                        key={vendor.vendor_id}
                        className="border-b hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer"
                        onClick={() => handleVendorClick(vendor.vendor_id)}
                      >
                        <td className="py-3 px-4">
                          <div>
                            <p className="font-medium">{vendor.vendor_name}</p>
                            {vendor.contact_email && (
                              <p className="text-sm text-gray-600">
                                {vendor.contact_email}
                              </p>
                            )}
                          </div>
                        </td>
                        <td
                          className="text-right py-3 px-4 text-green-600 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (vendor.current > 0) {
                              handleAmountClick(vendor.vendor_id, 'current');
                            }
                          }}
                        >
                          {formatCurrency(vendor.current)}
                        </td>
                        <td
                          className="text-right py-3 px-4 text-yellow-600 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (vendor.days_1_30 > 0) {
                              handleAmountClick(vendor.vendor_id, 'days_1_30');
                            }
                          }}
                        >
                          {formatCurrency(vendor.days_1_30)}
                        </td>
                        <td
                          className="text-right py-3 px-4 text-orange-600 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (vendor.days_31_60 > 0) {
                              handleAmountClick(vendor.vendor_id, 'days_31_60');
                            }
                          }}
                        >
                          {formatCurrency(vendor.days_31_60)}
                        </td>
                        <td
                          className="text-right py-3 px-4 text-red-600 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (vendor.days_61_90 > 0) {
                              handleAmountClick(vendor.vendor_id, 'days_61_90');
                            }
                          }}
                        >
                          {formatCurrency(vendor.days_61_90)}
                        </td>
                        <td
                          className="text-right py-3 px-4 text-red-800 hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (vendor.days_90_plus > 0) {
                              handleAmountClick(
                                vendor.vendor_id,
                                'days_90_plus'
                              );
                            }
                          }}
                        >
                          {formatCurrency(vendor.days_90_plus)}
                        </td>
                        <td className="text-right py-3 px-4 font-bold">
                          {formatCurrency(vendor.total)}
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
