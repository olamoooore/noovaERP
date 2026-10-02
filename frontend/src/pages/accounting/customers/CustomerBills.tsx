import React, { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { DocumentUpload } from '@/components/ocr/DocumentUpload';
import { useToast } from '@/hooks/use-toast';
import { useTags } from '@/hooks/use-tags';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';
import { INVOICE_EXPORT_COLUMNS, SalesDocumentExportMenu } from '@/components/sales/SalesDocumentExportMenu';
import { SalesLineColumnMenu, SalesLineColumnVisibility, listingColumns } from '@/components/sales/DeliveryOrderColumnVisibility';
import { useBulkSelection } from '@/hooks/use-bulk-selection';
import { BulkActionBar } from '@/components/ui/bulk-action-bar';
import { StatCard } from '@/components/ui/stat-card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { useAuthStore } from '@/store/authStore'
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import CreateCreditNoteDialog from '../components/CreateCreditNoteDialog';
import { EInvoiceStatusChip } from '../components/EInvoiceStatusChip';
import { useEInvoiceStatusMap } from '../components/useEInvoice';
import { Pagination } from '@/components/ui/Pagination';
import { SortableHead } from '@/components/ui/SortableHead';
import { useSort } from '@/hooks/useSort';

/**
 * Customer Invoices Page
 * Manage all customer invoices with OCR integration
 */
const CUSTOMER_BILL_COLUMNS = listingColumns([
  'Select', 'Invoice #', 'Customer', 'Date', 'Created Date', 'Due Date',
  'Total', 'Balance', 'Status', 'Source', 'Actions',
]);

export const CustomerBills: React.FC = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const [showUpload, setShowUpload] = useState(false);

  // No columns hidden by default here.
  const [hiddenBillColumns, setHiddenBillColumns] = useState<number[]>([]);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [tagFilter, setTagFilter] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [dateFilter, setDateFilter] = useState<'all' | 'yearly' | 'monthly' | 'custom'>('all');
  const [selectedYear, setSelectedYear] = useState(String(new Date().getFullYear()));
  const [selectedMonth, setSelectedMonth] = useState(new Date().toISOString().slice(0, 7));
  const [customStartDate, setCustomStartDate] = useState('');
  const [customEndDate, setCustomEndDate] = useState('');
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
  const [bulkDeleteCount, setBulkDeleteCount] = useState(0);
  const [bulkPostOpen, setBulkPostOpen] = useState(false);
  const [creditNoteInvoiceId, setCreditNoteInvoiceId] = useState<number | null>(null);
  // #670: filter by e-invoice status (server-side; see InvoiceController).
  const [einvoiceStatusFilter, setEinvoiceStatusFilter] = useState('all');
  const { toast } = useToast();
  const { data: allTags = [] } = useTags();
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
      tag: tagFilter || undefined,
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
    queryKey: ['customer-bills', statusFilter, einvoiceStatusFilter, searchQuery, customerFilter, tagFilter, agingBucketFilter, dateFilter, selectedYear, selectedMonth, customStartDate, customEndDate, page, perPage],
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
      if (einvoiceStatusFilter !== 'all') params.append('einvoice_status', einvoiceStatusFilter);
      if (searchQuery) params.append('search', searchQuery);
      if (customerFilter) params.append('customer_id', customerFilter);
      if (tagFilter) params.append('tag', tagFilter);
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

  // #670: one request per page for the e-invoice status of every row.
  const einvoiceStatusMap = useEInvoiceStatusMap('invoice', invoices.map((invoice: any) => Number(invoice.id)));
  const { sorted: sortedInvoices, sortKey, sortDir, toggle: toggleSort } = useSort<any>(invoices, 'created_at', 'desc');
  const lastPage = invoicesData?.last_page || 1;
  const totalRecords = invoicesData?.total || 0;
  // Summary metrics for the current view. Page-scoped money metrics are
  // labelled as such — the API only paginates, it does not aggregate.
  const viewInvoices = invoicesData?.data || [];
  const pageBalance = viewInvoices.reduce((sum: number, invoice: any) => sum + Number(invoice.balance || 0), 0);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const overdueCount = viewInvoices.filter(
    (invoice: any) => Number(invoice.balance || 0) > 0 && invoice.due_date && new Date(invoice.due_date) < today,
  ).length;

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
  const { hasPermission } = useAuthStore()
  // Posting/confirming sell-side documents is accounting-only (server enforces accounting.post).
  const canPost = hasPermission('accounting.post')
  // #524: drafting a credit note requires accounting.write (server enforces it since #515).
  const canWriteCreditNotes = hasPermission('accounting.write')


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

  const bulkPostMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const response = await api.post('/invoices/bulk-post', { ids });
      return response.data;
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['customer-bills'] });
      clearSelection();
      const postedCount = data?.posted?.length || 0;
      const skippedCount = data?.skipped?.length || 0;
      if (skippedCount > 0) {
        toast({
          title: `Bulk post complete: ${postedCount} posted, ${skippedCount} skipped`,
          description: (data?.skipped || [])
            .slice(0, 3)
            .map((skip: any) => `#${skip.id}: ${skip.reason}`)
            .join('; '),
        });
      } else {
        toast({ title: `${postedCount} invoice(s) posted successfully` });
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Bulk post failed',
        description: error.response?.data?.message || 'An error occurred',
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
      <div className="flex flex-wrap items-center justify-between gap-3">
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

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2">
        <select
          value={statusFilter}
          onChange={(e) => handleStatusFilterChange(e.target.value)}
          className="h-8 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="all">All Status</option>
          <option value="draft">Draft</option>
          <option value="posted">Posted</option>
          <option value="paid">Paid</option>
          <option value="partial">Partially Paid</option>
          <option value="overdue">Overdue</option>
        </select>
        <select
          value={einvoiceStatusFilter}
          onChange={(e) => { setEinvoiceStatusFilter(e.target.value); setPage(1); }}
          className="h-8 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Filter by e-invoice status"
        >
          <option value="all">All E-Invoice Statuses</option>
          <option value="not_submitted">Not submitted</option>
          <option value="queued">Queued</option>
          <option value="submitted">Submitted</option>
          <option value="valid">Valid</option>
          <option value="invalid">Invalid</option>
          <option value="cancelled">Cancelled</option>
          <option value="buyer_rejection_requested">Buyer rejection requested</option>
        </select>
        <input
          type="text"
          placeholder="Search by customer, invoice number..."
          value={searchQuery}
          onChange={(e) => { setSearchQuery(e.target.value); setPage(1); }}
          className="h-8 w-64 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
        />
        <div className="w-48 max-w-full">
          <CustomerSearchCombobox
            value={customerFilter || ''}
            onValueChange={handleCustomerFilterChange}
            placeholder="All Customers"
            compact
          />
        </div>
        <select
          value={tagFilter}
          onChange={(e) => { setTagFilter(e.target.value); setPage(1); }}
          aria-label="Filter by tag"
          className="h-8 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All Tags</option>
          {allTags.map((t) => (
            <option key={t.id} value={t.slug}>{t.name}</option>
          ))}
        </select>
        <select
          value={dateFilter}
          onChange={(e) => { setDateFilter(e.target.value as typeof dateFilter); setPage(1); }}
          className="h-8 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
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
            className="h-8 w-24 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
          />
        )}
        {dateFilter === 'monthly' && (
          <input
            type="month"
            value={selectedMonth}
            onChange={(e) => { setSelectedMonth(e.target.value); setPage(1); }}
            aria-label="Invoice month"
            className="h-8 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
          />
        )}
        {dateFilter === 'custom' && (
          <>
            <input
              type="date"
              value={customStartDate}
              onChange={(e) => { setCustomStartDate(e.target.value); setPage(1); }}
              aria-label="Invoice start date"
              className="h-8 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
            />
            <input
              type="date"
              min={customStartDate || undefined}
              value={customEndDate}
              onChange={(e) => { setCustomEndDate(e.target.value); setPage(1); }}
              aria-label="Invoice end date"
              className="h-8 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
            />
          </>
        )}
      </div>

      {/* Summary metrics */}
      {viewInvoices.length > 0 && (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatCard
            label="Total Invoices"
            value={totalRecords}
            hint="Across all pages"
            icon="solar:document-text-bold-duotone"
            tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
          />
          <StatCard
            label="Page Balance"
            value={formatCurrency(pageBalance)}
            hint="Outstanding on this page"
            icon="solar:wallet-money-bold-duotone"
            tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
          />
          <StatCard
            label="Selected"
            value={selectedIds.length}
            hint="Ready for bulk actions"
            icon="solar:check-circle-bold-duotone"
            tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
          />
          <StatCard
            label={overdueCount > 0 ? 'Overdue' : 'No Overdue'}
            value={overdueCount}
            hint="On this page"
            icon="solar:clock-circle-bold-duotone"
            tone={
              overdueCount > 0
                ? 'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400'
                : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'
            }
          />
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
                size="icon"
                onClick={handleClearDrillDownFilters}
                className="h-8 w-8 text-blue-600 hover:text-blue-700 dark:text-blue-400"
                title="Clear filters"
                aria-label="Clear filters"
              >
                <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {/* OCR Upload Section */}
      {showUpload && (
        <Card>
          <CardHeader>
            {/* Cancel lives in the DocumentUpload footer — no duplicate close here. */}
            <CardTitle>Upload Customer Invoices</CardTitle>
          </CardHeader>
          <CardContent>
            <DocumentUpload
              onComplete={handleUploadComplete}
              onCancel={() => setShowUpload(false)}
            />
          </CardContent>
        </Card>
      )}

      {/* Bulk action bar */}
      <BulkActionBar
        selectedCount={selectedIds.length}
        pageSize={invoices.length}
        allPageSelected={allPageSelected}
        allMatchingSelected={allMatchingSelected}
        isSelectingAll={isSelectingAll}
        onSelectAllMatching={selectAllMatching}
        onClearSelection={clearSelection}
        noun="invoices"
        destructiveAction={
          <HeaderActionButton
            variant="destructive"
            icon="solar:trash-bin-trash-bold-duotone"
            disabled={bulkDeleteMutation.isPending}
            onClick={handleBulkDelete}
          >
            Delete ({selectedIds.length})
          </HeaderActionButton>
        }
      >
        {canPost && (
          <HeaderActionButton
            icon="solar:check-circle-bold"
            loading={bulkPostMutation.isPending}
            disabled={bulkPostMutation.isPending}
            onClick={() => setBulkPostOpen(true)}
          >
            Bulk Post ({selectedIds.length})
          </HeaderActionButton>
        )}
      </BulkActionBar>

      {/* Bills List */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Customer Invoices</span>
            <span className="flex items-center gap-3">
              {totalRecords > 0 && (
                <span className="text-sm font-normal text-muted-foreground">
                  {totalRecords} invoice(s)
                </span>
              )}
              <SalesLineColumnMenu
                columns={CUSTOMER_BILL_COLUMNS}
                hidden={hiddenBillColumns}
                setHidden={setHiddenBillColumns}
              />
            </span>
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
              <SalesLineColumnVisibility
                tableClass="customer-bills-listing-columns"
                columns={CUSTOMER_BILL_COLUMNS}
                hiddenColumns={hiddenBillColumns}
                onHiddenColumnsChange={setHiddenBillColumns}
                showMenu={false}
              >
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
                    <SortableHead label="Invoice #" sortKey="invoice_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Customer" sortKey="customer.name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Date" sortKey="invoice_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Created Date" sortKey="created_at" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Due Date" sortKey="due_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Total" sortKey="total" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                    <SortableHead label="Balance" sortKey="balance" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                    <SortableHead label="Status" sortKey="status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Source" sortKey="ocr_document_id" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sortedInvoices.map((invoice: any) => (
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
                      <TableCell>{formatDate(invoice.created_at || invoice.invoice_date)}</TableCell>
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
                        {einvoiceStatusMap.get(Number(invoice.id)) && (
                          <div className="mt-1">
                            <EInvoiceStatusChip status={einvoiceStatusMap.get(Number(invoice.id))!.status} />
                          </div>
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
                          {canWriteCreditNotes && ['posted', 'paid', 'partial', 'overdue'].includes(invoice.status) && (
                            <Button
                              size="sm"
                              variant="outline"
                              className="h-7 w-7 p-0"
                              title="Credit note"
                              onClick={(e) => {
                                e.stopPropagation();
                                setCreditNoteInvoiceId(invoice.id);
                              }}
                            >
                              <Icon icon="solar:document-add-bold-duotone" className="h-3.5 w-3.5" />
                            </Button>
                          )}
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
                              {canPost && (
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
                              )}
                            </>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              </SalesLineColumnVisibility>

            <Pagination
              page={page}
              totalPages={lastPage}
              onPageChange={setPage}
              from={(page - 1) * perPage + 1}
              to={Math.min(page * perPage, totalRecords)}
              total={totalRecords}
              label="invoices"
              pageSize={perPage}
              onPageSizeChange={(size) => { setPerPage(size); setPage(1); }}
            />
            </>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={bulkPostOpen}
        onOpenChange={setBulkPostOpen}
        title={`Post ${selectedIds.length} invoice(s)?`}
        description="Each invoice is posted to the general ledger and becomes non-editable. Invoices requiring approval are skipped unless already approved."
        confirmLabel="Post invoices"
        tone="warning"
        icon="solar:check-circle-bold-duotone"
        isPending={bulkPostMutation.isPending}
        onConfirm={() => {
          setBulkPostOpen(false);
          bulkPostMutation.mutate(selectedIds);
        }}
      />

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

      {/* #524: credit note straight from the invoice row, invoice preselected. */}
      {creditNoteInvoiceId !== null && (
        <CreateCreditNoteDialog
          invoiceId={creditNoteInvoiceId}
          onClose={() => setCreditNoteInvoiceId(null)}
          onSuccess={() => {
            setCreditNoteInvoiceId(null);
            queryClient.invalidateQueries({ queryKey: ['invoices'] });
            queryClient.invalidateQueries({ queryKey: ['customer-credit-notes'] });
          }}
        />
      )}
    </div>
  );
};

export default CustomerBills;
