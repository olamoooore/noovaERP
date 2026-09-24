import React, { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { DocumentUpload } from '@/components/ocr/DocumentUpload';
import { useToast } from '@/hooks/use-toast';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '@/lib/api';
import { formatCurrency, formatDate } from '@/lib/utils';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';
import { INVOICE_EXPORT_COLUMNS, SalesDocumentExportMenu } from '@/components/sales/SalesDocumentExportMenu';
import { SalesLineColumnVisibility, listingColumns } from '@/components/sales/DeliveryOrderColumnVisibility';
import { useBulkSelection } from '@/hooks/use-bulk-selection';
import { BulkSelectionNotice } from '@/components/ui/bulk-selection-notice';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';

/**
 * Customer Invoices Page
 * Manage all customer invoices with OCR integration
 */
export const CustomerBills: React.FC = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const [showUpload, setShowUpload] = useState(false);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [dateFilter, setDateFilter] = useState<'all' | 'yearly' | 'monthly' | 'custom'>('all');
  const [selectedYear, setSelectedYear] = useState(String(new Date().getFullYear()));
  const [selectedMonth, setSelectedMonth] = useState(new Date().toISOString().slice(0, 7));
  const [customStartDate, setCustomStartDate] = useState('');
  const [customEndDate, setCustomEndDate] = useState('');
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
  const [bulkDeleteCount, setBulkDeleteCount] = useState(0);
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  // Get drill-down parameters from URL
  const customerIdParam = searchParams.get('customer_id');
  const agingBucketParam = searchParams.get('aging_bucket');

  // State for drill-down filters
  const [customerFilter, setCustomerFilter] = useState<string | null>(customerIdParam);
  const [agingBucketFilter, setAgingBucketFilter] = useState<string | null>(agingBucketParam);

  const activeExportFilters = () => {
    const filters: Record<string, string | undefined> = {
      status: statusFilter !== 'all' ? statusFilter : undefined,
      search: searchQuery.trim() || undefined,
      customer_id: customerFilter || undefined,
    };
    if (dateFilter === 'yearly' && selectedYear) {
      filters.start_date = `${selectedYear}-01-01`;
      filters.end_date = `${selectedYear}-12-31`;
    } else if (dateFilter === 'monthly' && selectedMonth) {
      const [year, month] = selectedMonth.split('-').map(Number);
      filters.start_date = `${selectedMonth}-01`;
      filters.end_date = `${selectedMonth}-${String(new Date(year, month, 0).getDate()).padStart(2, '0')}`;
    } else if (dateFilter === 'custom') {
      filters.start_date = customStartDate || undefined;
      filters.end_date = customEndDate || undefined;
    }

    return filters;
  };

  // Update filters when URL params change
  useEffect(() => {
    setCustomerFilter(customerIdParam);
    setAgingBucketFilter(agingBucketParam);
  }, [customerIdParam, agingBucketParam]);

  const isAgingDrilldown = Boolean(customerFilter && agingBucketFilter);

  // Fetch invoices
  const { data: invoicesData, isLoading, refetch } = useQuery({
    queryKey: ['customer-bills', statusFilter, searchQuery, customerFilter, agingBucketFilter, dateFilter, selectedYear, selectedMonth, customStartDate, customEndDate, page],
    queryFn: async () => {
      if (isAgingDrilldown) {
        const response = await api.get('/accounting/reports/aged-receivables/details', {
          params: {
            customer_id: customerFilter,
            aging_bucket: agingBucketFilter,
          },
        });

        const rows = (response.data.data || []).map((invoice: any) => ({
          id: invoice.id,
          invoice_number: invoice.invoice_number,
          invoice_date: invoice.invoice_date,
          due_date: invoice.due_date,
          customer: {
            name: customerFilter,
          },
          total: invoice.total,
          paid_amount: invoice.paid_amount,
          balance: invoice.balance,
          status: invoice.status,
          is_aging_drilldown: true,
        }));
        return { data: rows, current_page: 1, last_page: 1, total: rows.length };
      }

      const params = new URLSearchParams();
      params.set('per_page', String(perPage));
      params.set('page', String(page));
      if (statusFilter !== 'all') params.append('status', statusFilter);
      if (searchQuery) params.append('search', searchQuery);
      if (customerFilter) params.append('customer_id', customerFilter);
      if (dateFilter === 'yearly' && selectedYear) {
        params.append('start_date', `${selectedYear}-01-01`);
        params.append('end_date', `${selectedYear}-12-31`);
      } else if (dateFilter === 'monthly' && selectedMonth) {
        const [year, month] = selectedMonth.split('-').map(Number);
        params.append('start_date', `${selectedMonth}-01`);
        params.append('end_date', `${selectedMonth}-${String(new Date(year, month, 0).getDate()).padStart(2, '0')}`);
      } else if (dateFilter === 'custom') {
        if (customStartDate) params.append('start_date', customStartDate);
        if (customEndDate) params.append('end_date', customEndDate);
      }
      
      const response = await api.get(`/invoices?${params.toString()}`);
      return {
        data: response.data.data || [],
        current_page: response.data.current_page || 1,
        last_page: response.data.last_page || 1,
        total: response.data.total || 0,
      };
    },
  });

  const invoices = invoicesData?.data || [];
  const currentPage = invoicesData?.current_page || 1;
  const lastPage = invoicesData?.last_page || 1;
  const totalRecords = invoicesData?.total || 0;
  const {
    selectedIds, clearSelection, toggleSelect, togglePageSelection,
    selectAllMatching, allPageSelected, allMatchingSelected, isSelectingAll,
  } = useBulkSelection({
    pageIds: invoices.map((invoice: any) => invoice.id),
    scopeKey: JSON.stringify({ ...activeExportFilters(), agingBucketFilter }),
    fetchAllIds: async () => {
      if (isAgingDrilldown) return invoices.map((invoice: any) => invoice.id);
      const response = await api.get('/invoices', { params: { ...activeExportFilters(), ids_only: 1 } });
      return response.data.data;
    },
    onSelectAllError: () => toast({ variant: 'destructive', title: 'Failed to select all invoices' }),
  });
  const selectedIdSet = new Set(selectedIds);

  // Send invoice mutation
  const postInvoiceMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/invoices/${id}/send`);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer-bills'] });
      toast({ title: 'Invoice sent successfully' });
    },
    onError: (error: any) => {
      toast({ 
        variant: 'destructive', 
        title: 'Failed to post invoice',
        description: error.response?.data?.message || 'An error occurred'
      });
    },
  });

  const bulkDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const response = await api.post('/invoices/bulk-delete', { ids });
      return response.data;
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['customer-bills'] });
      clearSelection();
      const skipped = data?.skipped?.length || 0;
      const deleted = data?.deleted?.length || 0;
      toast({
        title: `${deleted} invoice(s) deleted`,
        description: skipped > 0 ? `${skipped} invoice(s) skipped (posted or not found).` : undefined,
      });
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Bulk delete failed',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const handleBulkDelete = () => {
    const count = selectedIds.length;
    if (count === 0) return;
    setBulkDeleteCount(count);
    setBulkDeleteOpen(true);
  };

  const handleStatusFilterChange = (value: string) => {
    setStatusFilter(value);
    setPage(1);
    clearSelection();
  };

  const handleCustomerFilterChange = (value: string) => {
    setCustomerFilter(value || null);
    setAgingBucketFilter(null);
    setPage(1);
    clearSelection();

    const next = new URLSearchParams(searchParams);
    if (value) next.set('customer_id', value);
    else next.delete('customer_id');
    next.delete('aging_bucket');
    setSearchParams(next, { replace: true });
  };

  const handleUploadComplete = () => {
    setShowUpload(false);
    refetch();
    toast({
      title: 'Upload complete',
      description: 'Documents uploaded and OCR processing started',
    });
  };

  const handleClearDrillDownFilters = () => {
    setCustomerFilter(null);
    setAgingBucketFilter(null);
    setSearchParams({});
  };

  const getAgingBucketLabel = (bucket: string) => {
    const labels: Record<string, string> = {
      current: 'Current',
      days_1_30: '1-30 Days',
      days_31_60: '31-60 Days',
      days_61_90: '61-90 Days',
      days_90_plus: '90+ Days',
    };
    return labels[bucket] || bucket;
  };

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { variant: any; label: string }> = {
      draft: { variant: 'secondary', label: 'Draft' },
      posted: { variant: 'default', label: 'Posted' },
      paid: { variant: 'default', label: 'Paid' },
      partial: { variant: 'default', label: 'Partially Paid' },
      overdue: { variant: 'destructive', label: 'Overdue' },
    };

    const config = statusConfig[status] || { variant: 'secondary', label: status };
    return <Badge variant={config.variant}>{config.label}</Badge>;
  };

  const formatDate = (date: string) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Customer Invoices</h1>
          <p className="text-muted-foreground mt-1">
            Manage customer invoices
          </p>
        </div>
        <div className="flex flex-wrap justify-end gap-2">
          <SalesDocumentExportMenu
            endpoint="/invoices/export"
            filenamePrefix="customer_invoices"
            columns={INVOICE_EXPORT_COLUMNS}
            filters={activeExportFilters()}
            selectedIds={selectedIds.length > 0
              ? selectedIds
              : isAgingDrilldown
                ? invoices.map((invoice: any) => invoice.id)
                : []}
          />
          <Button
            variant="outline"
            size="sm"
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => navigate('/accounting/customers/credit-notes')}
          >
            <Icon icon="solar:document-add-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
            Credit Notes
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => setShowUpload(true)}
          >
            <Icon icon="solar:upload-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
            Upload Invoice (OCR)
          </Button>
          <Button
            size="sm"
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => navigate('/accounting/customers/bills/create')}
          >
            <Icon icon="solar:add-circle-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
            New Invoice
          </Button>
        </div>
      </div>

      {/* Quick Stats */}
      {invoices && invoices.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Total Invoices</p>
                  <p className="text-2xl font-bold">{totalRecords}</p>
                </div>
                <Icon icon="solar:document-text-bold-duotone" className="h-8 w-8 text-blue-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Page</p>
                  <p className="text-2xl font-bold">
                    {currentPage} / {lastPage}
                  </p>
                </div>
                <Icon icon="solar:document-bold-duotone" className="h-8 w-8 text-gray-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Selected</p>
                  <p className="text-2xl font-bold">{selectedIds.length}</p>
                </div>
                <Icon icon="solar:check-circle-bold-duotone" className="h-8 w-8 text-orange-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between gap-2">
                <div>
                  <p className="text-sm text-muted-foreground">Per Page</p>
                  <select
                    value={perPage}
                    onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1); }}
                    className="mt-1 w-full rounded-md border border-input bg-background px-2 py-1 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-ring"
                  >
                    {[10, 20, 50, 100].map((n) => (
                      <option key={n} value={n}>{n}</option>
                    ))}
                  </select>
                </div>
                <Icon icon="solar:sort-bold-duotone" className="h-8 w-8 text-green-500" />
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Drill-down Filter Indicator */}
      {(customerFilter || agingBucketFilter) && (
        <Card className="border-blue-200 bg-blue-50 dark:bg-blue-950 dark:border-blue-800">
          <CardContent className="pt-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Icon icon="solar:filter-bold-duotone" className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                <span className="font-medium text-blue-900 dark:text-blue-100">
                  Filtered from Aged Receivables Report
                </span>
                <div className="flex gap-2 ml-4">
                  {customerFilter && (
                    <Badge variant="secondary" className="bg-blue-100 dark:bg-blue-900">
                      Customer ID: {customerFilter}
                    </Badge>
                  )}
                  {agingBucketFilter && (
                    <Badge variant="secondary" className="bg-blue-100 dark:bg-blue-900">
                      Aging: {getAgingBucketLabel(agingBucketFilter)}
                    </Badge>
                  )}
                </div>
              </div>
              <Button
                variant="ghost"
                size="sm"
                onClick={handleClearDrillDownFilters}
                className="text-blue-600 hover:text-blue-700 dark:text-blue-400"
              >
                <Icon icon="solar:close-circle-bold" className="h-4 w-4 mr-1" />
                Clear Filters
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {/* OCR Upload Section */}
      {showUpload && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center justify-between">
              <span>Upload Customer Invoices</span>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setShowUpload(false)}
              >
                <Icon icon="solar:close-circle-bold" className="h-5 w-5" />
              </Button>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <DocumentUpload
              onComplete={handleUploadComplete}
              onCancel={() => setShowUpload(false)}
            />
          </CardContent>
        </Card>
      )}

      {/* Filters */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex flex-wrap gap-3">
            <select
              value={statusFilter}
              onChange={(e) => handleStatusFilterChange(e.target.value)}
              className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="all">All Status</option>
              <option value="draft">Draft</option>
              <option value="posted">Posted</option>
              <option value="paid">Paid</option>
              <option value="partial">Partially Paid</option>
              <option value="overdue">Overdue</option>
            </select>
            <input
              type="text"
              placeholder="Search by customer, invoice number..."
              value={searchQuery}
              onChange={(e) => { setSearchQuery(e.target.value); setPage(1); }}
              className="min-w-56 flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <div className="w-48 max-w-full">
              <CustomerSearchCombobox
                value={customerFilter || ''}
                onValueChange={handleCustomerFilterChange}
                placeholder="All Customers"
              />
            </div>
            <select
              value={dateFilter}
              onChange={(e) => { setDateFilter(e.target.value as typeof dateFilter); setPage(1); }}
              className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
            >
              <option value="all">All Dates</option>
              <option value="yearly">Yearly</option>
              <option value="monthly">Monthly</option>
              <option value="custom">Custom Date</option>
            </select>
            {dateFilter === 'yearly' && (
              <input
                type="number"
                min="2000"
                max="2100"
                value={selectedYear}
                onChange={(e) => { setSelectedYear(e.target.value); setPage(1); }}
                aria-label="Invoice year"
                className="w-28 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
              />
            )}
            {dateFilter === 'monthly' && (
              <input
                type="month"
                value={selectedMonth}
                onChange={(e) => { setSelectedMonth(e.target.value); setPage(1); }}
                aria-label="Invoice month"
                className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
              />
            )}
            {dateFilter === 'custom' && (
              <>
                <input
                  type="date"
                  value={customStartDate}
                  onChange={(e) => { setCustomStartDate(e.target.value); setPage(1); }}
                  aria-label="Invoice start date"
                  className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                />
                <input
                  type="date"
                  min={customStartDate || undefined}
                  value={customEndDate}
                  onChange={(e) => { setCustomEndDate(e.target.value); setPage(1); }}
                  aria-label="Invoice end date"
                  className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                />
              </>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Bulk action bar */}
      {selectedIds.length > 0 && (
        <Card className="border-blue-200 bg-blue-50 dark:bg-blue-950 dark:border-blue-800">
          <CardContent className="pt-4">
            <div className="flex items-center justify-between">
              <BulkSelectionNotice pageSize={invoices.length} allPageSelected={allPageSelected} selectedCount={selectedIds.length} allMatchingSelected={allMatchingSelected} isSelectingAll={isSelectingAll} onSelectAllMatching={selectAllMatching} noun="invoices" />
              <div className="flex gap-2">
                <Button variant="ghost" size="sm" onClick={clearSelection}>
                  Clear selection
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleBulkDelete}
                  disabled={bulkDeleteMutation.isPending}
                >
                  <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4 mr-1" />
                  {bulkDeleteMutation.isPending ? 'Deleting...' : 'Delete Selected'}
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Bills List */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Customer Invoices</span>
            {totalRecords > 0 && (
              <span className="text-sm font-normal text-muted-foreground">
                {totalRecords} invoice(s)
              </span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
              <p className="text-muted-foreground mt-4">Loading invoices...</p>
            </div>
          ) : !invoices || invoices.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:document-text-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p className="text-lg font-medium mb-2">No customer invoices yet</p>
              <p className="text-sm mb-4">Upload an invoice via OCR or create one manually</p>
              <div className="flex gap-2 justify-center">
                <Button variant="outline" onClick={() => setShowUpload(true)}>
                  <Icon icon="solar:upload-bold-duotone" className="h-4 w-4 mr-2" />
                  Upload Invoice
                </Button>
                <Button onClick={() => navigate('/accounting/customers/bills/create')}>
                  <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
                  Create Invoice
                </Button>
              </div>
            </div>
          ) : (
            <>
              <SalesLineColumnVisibility tableClass="customer-bills-listing-columns" columns={listingColumns(['Select', 'Invoice #', 'Customer', 'Date', 'Created Date', 'Due Date', 'Total', 'Balance', 'Status', 'Source', 'Actions'])}>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-10">
                      <input
                        type="checkbox"
                        checked={allPageSelected}
                        onChange={togglePageSelection}
                        title="Select all invoices on this page"
                      />
                    </TableHead>
                    <TableHead>Invoice #</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Created Date</TableHead>
                    <TableHead>Due Date</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead className="text-right">Balance</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Source</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {invoices.map((invoice: any) => (
                    <TableRow
                      key={invoice.id}
                      className="hover:bg-accent/50 cursor-pointer"
                      onClick={() => navigate(`/accounting/customers/bills/${invoice.id}`)}
                    >
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <input
                          type="checkbox"
                          checked={selectedIdSet.has(invoice.id)}
                          onChange={() => toggleSelect(invoice.id)}
                        />
                      </TableCell>
                      <TableCell 
                        className="font-medium cursor-pointer hover:text-primary"
                        onClick={() => navigate(`/accounting/customers/bills/${invoice.id}`)}
                      >
                        {invoice.invoice_number || `INV-${invoice.id}`}
                      </TableCell>
                      <TableCell>{invoice.customer?.name || invoice.customer_name || '-'}</TableCell>
                      <TableCell>{formatDate(invoice.invoice_date)}</TableCell>
                      <TableCell>{formatDate(invoice.created_at)}</TableCell>
                      <TableCell>{formatDate(invoice.due_date)}</TableCell>
                      <TableCell className="text-right font-medium">
                        {formatCurrency(invoice.total || invoice.total_amount)}
                      </TableCell>
                      <TableCell className="text-right">
                        {formatCurrency(invoice.balance || invoice.total)}
                      </TableCell>
                      <TableCell>{getStatusBadge(invoice.status)}</TableCell>
                      <TableCell>
                        {invoice.ocr_document_id ? (
                          <Badge variant="outline" className="gap-1">
                            <Icon icon="solar:document-add-bold-duotone" className="h-3 w-3" />
                            OCR
                          </Badge>
                        ) : (
                          <Badge variant="outline" className="gap-1">
                            <Icon icon="solar:pen-bold-duotone" className="h-3 w-3" />
                            Manual
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                        <div className="flex justify-end gap-1.5">
                          <Button
                            size="sm"
                            variant="outline"
                            className="h-7 w-7 p-0"
                            title="View"
                            onClick={(e) => {
                              e.stopPropagation();
                              navigate(`/accounting/customers/bills/${invoice.id}`);
                            }}
                          >
                            <Icon icon="solar:eye-bold-duotone" className="h-3.5 w-3.5" />
                          </Button>
                          {invoice.status === 'draft' && (
                            <>
                              <Button
                                size="sm"
                                variant="outline"
                                className="h-7 w-7 p-0"
                                title="Edit"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  navigate(`/accounting/customers/bills/${invoice.id}/edit`);
                                }}
                              >
                                <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                              </Button>
                              <Button
                                size="sm"
                                className="h-7 w-7 bg-emerald-600 p-0 text-white hover:bg-emerald-700"
                                title="Post"
                                disabled={postInvoiceMutation.isPending}
                                onClick={(e) => {
                                  e.stopPropagation();
                                  postInvoiceMutation.mutate(invoice.id);
                                }}
                              >
                                <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4" />
                              </Button>
                            </>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              </SalesLineColumnVisibility>

            {lastPage > 1 && (
              <div className="flex items-center justify-between pt-4">
                <span className="text-sm text-muted-foreground">
                  Page {currentPage} of {lastPage}
                </span>
                <div className="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    disabled={currentPage <= 1}
                  >
                    <Icon icon="solar:alt-arrow-left-bold" className="mr-1 h-4 w-4" />
                    Previous
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                    disabled={currentPage >= lastPage}
                  >
                    Next
                    <Icon icon="solar:alt-arrow-right-bold" className="ml-1 h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
            </>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={bulkDeleteOpen}
        onOpenChange={setBulkDeleteOpen}
        title={`Delete ${bulkDeleteCount} selected draft invoice(s)?`}
        description="Posted invoices are skipped automatically; only drafts are removed. This cannot be undone."
        confirmLabel="Delete drafts"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={bulkDeleteMutation.isPending}
        onConfirm={() => {
          setBulkDeleteOpen(false);
          bulkDeleteMutation.mutateAsync(selectedIds);
        }}
      />
    </div>
  );
};

export default CustomerBills;
