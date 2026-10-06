import React, { useState, useEffect, useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import DepositAccountSelect from '@/components/payment/DepositAccountSelect';
import { rememberDeposit, type DepositAccountOption } from '@/lib/paymentDepositAccount';
import { buildMethodOptions, type PaymentMethodRow } from '@/lib/paymentMethods'; // #486
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Pagination } from '@/components/ui/Pagination';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AuditTab } from '@/components/audit/AuditTab';
import { PrintSendMenu } from '@/components/documents/PrintSendMenu';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { InvoiceSearchCombobox } from '@/components/ui/InvoiceSearchCombobox';
import { RecordPaymentForm } from '@/components/payment/RegisterPaymentDialog'; // #491
import { PaymentAllocationsPanel } from '@/components/payment/PaymentAllocations'; // #488
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useAuthStore } from '@/store/authStore';
import { SalesDocumentExportMenu, type SalesExportColumn } from '@/components/sales/SalesDocumentExportMenu';
import { useBulkSelection } from '@/hooks/use-bulk-selection';
import { BulkActionBar } from '@/components/ui/bulk-action-bar';
import {
  PaymentCorrectionDialog,
  type PaymentCorrectionAction,
  type PaymentCorrectionOutcome,
} from '@/components/payment/PaymentCorrectionDialog';
import { EInvoiceStatusChip } from '../components/EInvoiceStatusChip';
import { useEInvoiceDocument, useEInvoiceStatusMap } from '../components/useEInvoice';
import { SortableHead } from '@/components/ui/SortableHead';
import { useSort } from '@/hooks/useSort';

interface Payment {
  id: number;
  payment_number?: string;
  payment_date: string;
  amount: number;
  method: string;
  reference?: string;
  payment_status: string;
  customer_id?: number;
  invoice_id?: number;
  customer?: { id: number; name: string; code?: string };
  invoice?: { id: number; invoice_number: string };
  journal_entry_id?: number | null;
  received_by?: number | null;
  notes?: string;
  currency?: string;
  created_at?: string;
  correction_type?: string | null;
  correction_reason_code?: string | null;
  correction_reason_text?: string | null;
  original_payment_id?: number | null;
  refund_payment_id?: number | null;
  reversal_journal_entry_id?: number | null;
  unapplied_at?: string | null;
  refunded_at?: string | null;
  // Wide-search hints set by the list endpoint when a search term is present.
  matched_contacts?: string[];
  matched_tags?: string[];
  matched_addresses?: string[];
  bank_account_id?: number | string | null;
  bank_account?: { id: number; account_name: string; gl_account_code?: string | null } | null;
  /** #492: origin of the payment; null for legacy rows recorded before tracking. */
  source?: 'manual' | 'import' | string | null;
}

interface PaginatedPayments {
  data: Payment[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

// Canonical statuses (#73): `recorded` = received but not yet in the ledger;
// `applied` = posted (journal_entry_id set); `reversed`/`refunded` = terminal
// corrections. The legacy `draft` / `posted` labels no longer exist in the
// backend enum — filtering on them silently returned zero rows.
interface PaymentStatusOption {
  value: string;
  /** Full label (filter dropdown). */
  label: string;
  /** Compact label for the status badge so rows stay short. */
  shortLabel: string;
  icon: string;
  variant: 'default' | 'secondary' | 'outline' | 'destructive';
}

const PAYMENT_STATUS_OPTIONS: PaymentStatusOption[] = [
  { value: 'recorded', label: 'Draft (not yet posted)', shortLabel: 'Draft', icon: 'solar:document-bold-duotone', variant: 'secondary' },
  { value: 'applied', label: 'Applied (posted to ledger)', shortLabel: 'Applied', icon: 'solar:check-circle-bold-duotone', variant: 'default' },
  { value: 'unapplied', label: 'Unapplied', shortLabel: 'Unapplied', icon: 'solar:link-broken-bold-duotone', variant: 'outline' },
  { value: 'reversed', label: 'Reversed', shortLabel: 'Reversed', icon: 'solar:undo-left-round-bold', variant: 'destructive' },
  { value: 'refunded', label: 'Refunded', shortLabel: 'Refunded', icon: 'solar:refresh-circle-bold-duotone', variant: 'destructive' },
];

// #492: the draft status is shared by hand-typed and imported payments, so the
// origin is shown as its own badge instead of being baked into the status label.
const PAYMENT_SOURCE_LABELS: Record<string, string> = {
  manual: 'Manual',
  import: 'Imported',
};

const PaymentSourceBadge: React.FC<{ source?: string | null }> = ({ source }) => {
  if (!source) return null;
  const label = PAYMENT_SOURCE_LABELS[source] ?? source;
  return (
    <Badge
      variant="outline"
      title={`Source: ${label}`}
      className="whitespace-nowrap px-1.5 py-0 text-[10px] leading-4"
    >
      {label}
    </Badge>
  );
};

// Statuses that represent a completed terminal state — no further correction is
// allowed once a payment reaches one of these. Mirrors the backend guard in
// PaymentCorrectionService::assertCorrectable().
const TERMINAL_PAYMENT_STATUSES = ['reversed', 'refunded', 'cancelled', 'unapplied'];

// Only POSTED payments (journal_entry_id set) can be corrected. The backend
// rejects unposted rows with "has not been posted. Delete it instead."
const isCorrectable = (payment: Payment): boolean =>
  payment.journal_entry_id != null
  && !TERMINAL_PAYMENT_STATUSES.includes(payment.payment_status)
  && !payment.refund_payment_id;

const PAYMENT_EXPORT_COLUMNS = [
  { key: 'payment_number', label: 'Payment No', group: 'Payment', default: true }, { key: 'payment_date', label: 'Date', group: 'Payment', default: true },
  { key: 'customer_name', label: 'Customer', group: 'Payment', default: true }, { key: 'invoice_number', label: 'Invoice No', group: 'Payment', default: true },
  { key: 'amount', label: 'Amount', group: 'Payment', default: true }, { key: 'currency', label: 'Currency', group: 'Payment' },
  { key: 'method', label: 'Method', group: 'Payment', default: true }, { key: 'reference', label: 'Reference', group: 'Payment' },
  { key: 'payment_status', label: 'Status', group: 'Payment', default: true }, { key: 'journal_entry_number', label: 'Journal Entry', group: 'Accounting' },
  { key: 'notes', label: 'Notes', group: 'Payment' },
] as const satisfies readonly SalesExportColumn[];

const formatDate = (date: string | undefined) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

export const CustomerPayments: React.FC = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const invoiceIdParam = searchParams.get('invoice_id') || '';
  const [showRecordPayment, setShowRecordPayment] = useState(!!invoiceIdParam);

  // Auto-open the Record Payment dialog when arriving with ?invoice_id=X
  // (from invoice detail page). After opening once, strip the param from the URL
  // so closing/reopening or navigating away doesn't keep triggering it.
  useEffect(() => {
    if (invoiceIdParam && showRecordPayment) {
      const next = new URLSearchParams(searchParams);
      next.delete('invoice_id');
      setSearchParams(next, { replace: true });
    }
  }, [invoiceIdParam, showRecordPayment]);
  const [searchQuery, setSearchQuery] = useState(searchParams.get('search') || '');
  // Wide search hits the DB per term; debounce typing like the other lists do.
  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState(searchParams.get('search') || '');
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearchQuery(searchQuery), 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);
  const [statusFilter, setStatusFilter] = useState(searchParams.get('payment_status') || '');
  const [methodFilter, setMethodFilter] = useState(searchParams.get('method') || '');
  const [customerFilter, setCustomerFilter] = useState(searchParams.get('customer_id') || '');
  const [dateFrom, setDateFrom] = useState(searchParams.get('date_from') || '');
  const [dateTo, setDateTo] = useState(searchParams.get('date_to') || '');
  const [page, setPage] = useState(Number(searchParams.get('page') || 1));
  const [perPage, setPerPage] = useState(20);
  const [showBulkDeleteDialog, setShowBulkDeleteDialog] = useState(false);
  const [viewingPayment, setViewingPayment] = useState<Payment | null>(null);
  const [editingPayment, setEditingPayment] = useState<Payment | null>(null);
  const [correctionTarget, setCorrectionTarget] = useState<{
    payment: Payment;
    action: PaymentCorrectionAction;
  } | null>(null);
  const [bulkReverseOpen, setBulkReverseOpen] = useState(false);
  const [bulkReverseCode, setBulkReverseCode] = useState('DUPLICATE_PAYMENT');
  const [bulkReverseReason, setBulkReverseReason] = useState('');
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const { hasPermission } = useAuthStore();

  // #486: method dropdowns (rows, filters, dialogs) read the master.
  const { data: methodRows } = useQuery<PaymentMethodRow[]>({
    queryKey: ['payment-methods'],
    queryFn: async () => {
      const response = await api.get('/accounting/payment-methods');
      return response.data.data as PaymentMethodRow[];
    },
  });
  const methodOptions = buildMethodOptions(methodRows);
  // Correction endpoints (POST /corrections/payments/…) require accounting.post
  const canCorrect = hasPermission('accounting.post');

  // Fetch payments (paginated, filtered)
  const queryKey = useMemo(
    () => ['customer-payments', { debouncedSearchQuery, statusFilter, methodFilter, customerFilter, dateFrom, dateTo, invoiceIdParam, page, perPage }],
    [debouncedSearchQuery, statusFilter, methodFilter, customerFilter, dateFrom, dateTo, invoiceIdParam, page, perPage],
  );

  const { data: payments, isLoading } = useQuery({
    queryKey,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (debouncedSearchQuery) params.append('search', debouncedSearchQuery);
      if (statusFilter) params.append('payment_status', statusFilter);
      if (methodFilter) params.append('method', methodFilter);
      if (customerFilter) params.append('customer_id', customerFilter);
      if (dateFrom) params.append('date_from', dateFrom);
      if (dateTo) params.append('date_to', dateTo);
      if (invoiceIdParam) params.append('invoice_id', invoiceIdParam);
      params.append('page', String(page));
      params.append('per_page', String(perPage));

      const response = await api.get(`/payments?${params.toString()}`);
      return response.data as PaginatedPayments;
    },
  });

  const paymentList = payments?.data || [];
  const { sorted: sortedPayments, sortKey, sortDir, toggle: toggleSort } = useSort<Payment>(paymentList, 'payment_date', 'desc');

  // #825 (EINV-UX8): a refund record (the negative correction payment created
  // when money is returned) carries a refund_note compliance document (#816) —
  // show its LHDN status chip, like #670 does for invoices and credit notes.
  const refundPaymentIds = paymentList
    .filter((p) => p.correction_type === 'refund')
    .map((p) => Number(p.id));
  const refundNoteStatusMap = useEInvoiceStatusMap('refund_note', refundPaymentIds);

  const totalPages = payments?.last_page || 1;
  const totalRecords = payments?.total || 0;
  const paymentFilters = { search: debouncedSearchQuery || undefined, payment_status: statusFilter || undefined, method: methodFilter || undefined, customer_id: customerFilter || undefined, date_from: dateFrom || undefined, date_to: dateTo || undefined, invoice_id: invoiceIdParam || undefined };
  const {
    selectedIds, clearSelection, toggleSelect, togglePageSelection,
    selectAllMatching, allPageSelected, allMatchingSelected, isSelectingAll,
  } = useBulkSelection({
    pageIds: paymentList.map((payment) => payment.id),
    scopeKey: JSON.stringify(paymentFilters),
    fetchAllIds: async () => {
      const response = await api.get('/payments', { params: { ...paymentFilters, ids_only: 1 } });
      return response.data.data;
    },
    onSelectAllError: () => toast({ variant: 'destructive', title: 'Failed to select all payments' }),
  });
  const selectedIdSet = new Set(selectedIds);

  // Fetch unpaid invoices for payment allocation.
  const { data: unpaidInvoices } = useQuery({
    queryKey: ['unpaid-invoices'],
    queryFn: async () => {
      const response = await api.get('/invoices?status=posted,sent,overdue,partial');
      return response.data.data || [];
    },
    enabled: showRecordPayment || editingPayment !== null,
  });

  const getPaymentMethodBadge = (method: string) => {
    const cfg = methodOptions.find((m) => m.value === method);
    return (
      <Badge variant="outline" className="gap-1 whitespace-nowrap px-1.5 py-0 text-[10px] leading-4">
        <Icon icon={cfg?.icon || 'solar:wallet-bold-duotone'} className="h-3 w-3" />
        {cfg?.label || method}
      </Badge>
    );
  };

  const getStatusBadge = (status: string) => {
    const cfg: PaymentStatusOption = PAYMENT_STATUS_OPTIONS.find((s) => s.value === status) || {
      value: status,
      label: status,
      shortLabel: status,
      icon: 'solar:document-bold-duotone',
      variant: 'secondary',
    };
    return (
      <Badge
        variant={cfg.variant}
        title={cfg.label}
        className="gap-1 whitespace-nowrap px-1.5 py-0 text-[10px] leading-4"
      >
        <Icon icon={cfg.icon} className="h-3 w-3" />
        {cfg.shortLabel}
      </Badge>
    );
  };

  // `recorded` is the canonical pre-posting status (Payment::scopeDraft()
  // maps draft → recorded). Unposted rows are editable/deletable; posting
  // flips them to `applied` and sets journal_entry_id.
  // A `recorded` row is postable even when it already carries a journal entry:
  // that is a legacy stuck label, and posting it repairs the status
  // idempotently without creating a second entry. Only rows WITHOUT an entry
  // can be deleted outright — posted ones must be reversed.
  const postableSelected = paymentList.filter(
    (p) => selectedIdSet.has(p.id) && p.payment_status === 'recorded',
  );
  const deletableSelected = paymentList.filter(
    (p) => selectedIdSet.has(p.id) && p.payment_status === 'recorded' && !p.journal_entry_id,
  );
  // Selected rows that cannot be posted at all (already applied/reversed/…).
  const lockedSelected = allMatchingSelected ? 0 : selectedIds.length - postableSelected.length;

  // Posted payments selected on this page that can still be corrected. Bulk
  // reversal is deliberately limited to explicit page selections — never
  // "select all matching", which could reverse hundreds in one click.
  const reversibleSelected = allMatchingSelected
    ? []
    : paymentList.filter((p) => selectedIdSet.has(p.id) && isCorrectable(p));

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['customer-payments'] });

  const bulkReverseMutation = useMutation({
    mutationFn: async ({ ids, reasonCode, reasonText }: { ids: number[]; reasonCode: string; reasonText: string }) => {
      const results = await Promise.allSettled(
        ids.map((id) => api.post(`/corrections/payments/${id}/reverse`, {
          reason_code: reasonCode,
          reason_text: reasonText || null,
        })),
      );
      return results;
    },
    onSuccess: (results) => {
      invalidate();
      clearSelection();
      const reversed = results.filter((result) => result.status === 'fulfilled').length;
      const failures = results.filter((result) => result.status === 'rejected') as PromiseRejectedResult[];
      const firstError = failures[0]?.reason?.response?.data?.message;
      toast({
        title: `${reversed} payment(s) reversed`,
        description: failures.length
          ? `${failures.length} failed${firstError ? `: ${firstError}` : ''}`
          : 'Reversing journal entries posted to the ledger.',
        variant: failures.length ? 'destructive' : 'default',
      });
    },
  });

  const handleCorrectionSuccess = (outcome: PaymentCorrectionOutcome) => {
    invalidate();
    queryClient.invalidateQueries({ queryKey: ['payment-detail'] });
    queryClient.invalidateQueries({ queryKey: ['unpaid-invoices'] });

    const je = outcome.reversal_journal_entry;
    toast({
      title: outcome.message || 'Payment correction completed',
      description: je?.entry_number
        ? `Reversal journal entry ${je.entry_number} (${je.entry_date}) was posted to the ledger.`
        : undefined,
    });
  };

  const bulkPostMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const response = await api.post('/payments/bulk-post', { ids });
      return response.data;
    },
    onSuccess: (data) => {
      invalidate();
      clearSelection();
      toast({
        title: `${data.posted?.length || 0} payment(s) posted`,
        description: data.skipped?.length
          ? `${data.skipped.length} skipped: ${data.skipped[0]?.reason ?? ''}`
          : 'Ledger entries created for all payments.',
      });
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Bulk post failed',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const clearFilters = () => {
    setSearchQuery('');
    setStatusFilter('');
    setMethodFilter('');
    setCustomerFilter('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
    const next = new URLSearchParams(searchParams);
    next.delete('search');
    next.delete('payment_status');
    next.delete('method');
    next.delete('customer_id');
    next.delete('date_from');
    next.delete('date_to');
    next.delete('page');
    setSearchParams(next);
  };

  const updateUrl = (k: string, v: string) => {
    const next = new URLSearchParams(searchParams);
    if (v) next.set(k, v);
    else next.delete(k);
    setSearchParams(next);
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Customer Payments</h1>
          <p className="text-muted-foreground mt-1">Track payments received from customers</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <SalesDocumentExportMenu endpoint="/payments/export" filenamePrefix="payments" columns={PAYMENT_EXPORT_COLUMNS} filters={paymentFilters} selectedIds={selectedIds} />
          <HeaderActionButton icon="solar:upload-bold-duotone" variant="outline" onClick={() => window.location.href = '/accounting/customers/payments/import'}>
            Import
          </HeaderActionButton>
          <Dialog open={showRecordPayment} onOpenChange={setShowRecordPayment}>
            <DialogTrigger asChild>
              <HeaderActionButton icon="solar:add-circle-bold-duotone">
                Record Payment
              </HeaderActionButton>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
              <DialogHeader>
                <DialogTitle>Record Customer Payment</DialogTitle>
              </DialogHeader>
              <RecordPaymentForm
                unpaidInvoices={unpaidInvoices || []}
                initialInvoiceId={invoiceIdParam}
                onSuccess={() => {
                  setShowRecordPayment(false);
                  queryClient.invalidateQueries({ queryKey: ['customer-payments'] });
                  toast({ title: 'Payment recorded successfully' });
                }}
              />
            </DialogContent>
          </Dialog>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2">
        <Input
          id="search"
          placeholder="Search payment, customer, contact…"
          value={searchQuery}
          onChange={(e) => {
            setSearchQuery(e.target.value);
            setPage(1);
            updateUrl('search', e.target.value);
          }}
          className="h-8 w-64 rounded-full px-3 text-xs"
        />
        <Select
          value={statusFilter || 'all'}
          onValueChange={(value) => {
            setStatusFilter(value === 'all' ? '' : value);
            setPage(1);
            updateUrl('payment_status', value === 'all' ? '' : value);
          }}
        >
          <SelectTrigger className="h-8 w-44 rounded-full text-xs"><SelectValue placeholder="All statuses" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            {PAYMENT_STATUS_OPTIONS.map((s) => (
              <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          value={methodFilter || 'all'}
          onValueChange={(value) => {
            setMethodFilter(value === 'all' ? '' : value);
            setPage(1);
            updateUrl('method', value === 'all' ? '' : value);
          }}
        >
          <SelectTrigger className="h-8 w-40 rounded-full text-xs"><SelectValue placeholder="All methods" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All methods</SelectItem>
            {methodOptions.map((m) => (
              <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
        <div className="w-56 max-w-full">
          <CustomerSearchCombobox
            value={customerFilter}
            onValueChange={(value) => {
              setCustomerFilter(value);
              setPage(1);
              updateUrl('customer_id', value);
            }}
            placeholder="All customers (search name or code)..."
            compact
          />
        </div>
        <Input
          type="date"
          value={dateFrom}
          onChange={(e) => {
            setDateFrom(e.target.value);
            setPage(1);
            updateUrl('date_from', e.target.value);
          }}
          className="h-8 w-40 rounded-full px-3 text-xs"
        />
        <Input
          type="date"
          value={dateTo}
          onChange={(e) => {
            setDateTo(e.target.value);
            setPage(1);
            updateUrl('date_to', e.target.value);
          }}
          className="h-8 w-40 rounded-full px-3 text-xs"
        />
        <Button
          variant="outline"
          size="icon"
          className="h-8 w-8 rounded-full"
          onClick={clearFilters}
          title="Clear filters"
          aria-label="Clear filters"
        >
          <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
        </Button>
      </div>

      {/* Bulk actions bar */}
      <BulkActionBar
        selectedCount={selectedIds.length}
        pageSize={paymentList.length}
        allPageSelected={allPageSelected}
        allMatchingSelected={allMatchingSelected}
        isSelectingAll={isSelectingAll}
        onSelectAllMatching={selectAllMatching}
        onClearSelection={clearSelection}
        noun="payments"
        hint={
          lockedSelected > 0
            ? `${lockedSelected} already in the ledger — posted payments are reversed, not deleted`
            : allMatchingSelected
              ? 'Posted payments will be skipped by bulk actions'
              : undefined
        }
        destructiveAction={
          <HeaderActionButton
            variant="destructive"
            icon="solar:trash-bin-trash-bold"
            onClick={() => setShowBulkDeleteDialog(true)}
            disabled={!allMatchingSelected && deletableSelected.length === 0}
          >
            {allMatchingSelected ? 'Delete Selected' : `Delete (${deletableSelected.length})`}
          </HeaderActionButton>
        }
      >
        {canCorrect && reversibleSelected.length > 0 && (
          <HeaderActionButton
            variant="outline"
            icon="solar:undo-left-round-bold"
            className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
            onClick={() => {
              setBulkReverseCode('DUPLICATE_PAYMENT');
              setBulkReverseReason('');
              setBulkReverseOpen(true);
            }}
          >
            Reverse ({reversibleSelected.length})
          </HeaderActionButton>
        )}
        <HeaderActionButton
          icon="solar:check-circle-bold"
          loading={bulkPostMutation.isPending}
          onClick={() => bulkPostMutation.mutate(allMatchingSelected ? selectedIds : postableSelected.map((p) => p.id))}
          disabled={(!allMatchingSelected && postableSelected.length === 0) || bulkPostMutation.isPending}
        >
          {allMatchingSelected ? 'Post Selected' : `Post (${postableSelected.length})`}
        </HeaderActionButton>
      </BulkActionBar>

      {/* Payments List */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Payment History</span>
            {totalRecords > 0 && (
              <span className="text-sm font-normal text-muted-foreground">
                Showing {paymentList.length} of {totalRecords} payment(s)
              </span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
              <p className="text-muted-foreground mt-4">Loading payments...</p>
            </div>
          ) : paymentList.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:wallet-money-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p className="text-lg font-medium mb-2">No customer payments found</p>
              <p className="text-sm mb-4">
                {searchQuery || statusFilter || methodFilter || dateFrom || dateTo
                  ? 'Try adjusting your filters.'
                  : 'Record your first payment to start tracking.'}
              </p>
              {!searchQuery && !statusFilter && !methodFilter && !dateFrom && !dateTo && (
                <div className="flex justify-center gap-2">
                  <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => setShowRecordPayment(true)}>
                    Record Payment
                  </HeaderActionButton>
                  <HeaderActionButton icon="solar:upload-bold-duotone" variant="outline" onClick={() => window.location.href = '/accounting/customers/payments/import'}>
                    Import from CSV
                  </HeaderActionButton>
                </div>
              )}
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <Table className="[&_th]:px-[13px] [&_td]:px-[13px]">
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-10">
                        <Checkbox
                          checked={allPageSelected}
                          onCheckedChange={togglePageSelection}
                          aria-label="Select payments on this page"
                        />
                      </TableHead>
                      <SortableHead label="Payment #" sortKey="payment_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Date" sortKey="payment_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Status" sortKey="payment_status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Customer" sortKey="customer.name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Method" sortKey="method" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Reference" sortKey="reference" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Amount" sortKey="amount" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                      <SortableHead label="Allocated To" sortKey="invoice.invoice_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} className="w-[6.5rem]" />
                      <SortableHead label="Created" sortKey="created_at" activeKey={sortKey} direction={sortDir} onSort={toggleSort} className="w-[7.5rem] whitespace-nowrap" />
                      <TableHead className="w-[8.5rem] whitespace-nowrap text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {sortedPayments.map((payment) => {
                      const editable = payment.payment_status === 'recorded' && !payment.journal_entry_id;
                      // Stuck legacy rows (recorded + JE) remain postable: the
                      // backend repairs their status without double-posting.
                      const postable = payment.payment_status === 'recorded';
                      const correctable = canCorrect && isCorrectable(payment);
                      return (
                        <TableRow key={payment.id} className="hover:bg-accent/50">
                          <TableCell>
                            <Checkbox
                              checked={selectedIdSet.has(payment.id)}
                              onCheckedChange={() => toggleSelect(payment.id)}
                              aria-label={`Select ${payment.payment_number}`}
                            />
                          </TableCell>
                          <TableCell className="font-medium">
                            {payment.payment_number || `PAY-${payment.id}`}
                          </TableCell>
                          <TableCell>{formatDate(payment.payment_date)}</TableCell>
                          <TableCell className="w-[1%] whitespace-nowrap">
                            {getStatusBadge(payment.payment_status)}
                            {payment.source && (
                              <div className="mt-1">
                                <PaymentSourceBadge source={payment.source} />
                              </div>
                            )}
                            {refundNoteStatusMap.get(Number(payment.id)) && (
                              <div className="mt-1">
                                <EInvoiceStatusChip status={refundNoteStatusMap.get(Number(payment.id))!.status} />
                              </div>
                            )}
                          </TableCell>
                          <TableCell className="max-w-[9.5rem]">
                            <div className="break-words leading-snug" title={payment.customer?.name || undefined}>
                              {payment.customer?.name || '-'}
                            </div>
                            {/* Wide search can match a customer contact/address or the paid invoice. */}
                            {(payment.matched_contacts?.length ?? 0) > 0 && (
                              <div className="truncate text-xs text-primary" title={payment.matched_contacts!.join(', ')}>
                                Contact: {payment.matched_contacts!.join(', ')}
                              </div>
                            )}
                            {(payment.matched_tags?.length ?? 0) > 0 && (
                              <div className="truncate text-xs text-amber-600" title={payment.matched_tags!.join(', ')}>
                                Tag: {payment.matched_tags!.join(', ')}
                              </div>
                            )}
                            {(payment.matched_addresses?.length ?? 0) > 0 && (
                              <div className="truncate text-xs text-muted-foreground" title={payment.matched_addresses!.join(' · ')}>
                                Address: {payment.matched_addresses!.join(' · ')}
                              </div>
                            )}
                          </TableCell>
                          <TableCell>{getPaymentMethodBadge(payment.method)}</TableCell>
                          <TableCell className="max-w-[8.5rem] text-sm text-muted-foreground">
                            <div className="break-words leading-snug" title={payment.reference || undefined}>
                              {payment.reference || '-'}
                            </div>
                          </TableCell>
                          <TableCell className="text-right font-medium">
                            {formatCurrency(payment.amount || 0)}
                          </TableCell>
                          <TableCell className="max-w-[6.5rem]">
                            {payment.invoice ? (
                              <button
                                type="button"
                                onClick={() => navigate(`/invoices/${payment.invoice!.id}`)}
                                className="block max-w-full break-words rounded px-1 text-left text-sm leading-snug text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-primary/20"
                                title={payment.invoice.invoice_number}
                              >
                                {payment.invoice.invoice_number}
                              </button>
                            ) : (
                              <Badge variant="outline" className="font-normal">Unallocated</Badge>
                            )}
                          </TableCell>
                          <TableCell className="w-[7.5rem] whitespace-nowrap text-sm text-muted-foreground">
                            {payment.created_at ? formatDate(payment.created_at) : '-'}
                          </TableCell>
                          <TableCell className="w-[8.5rem] whitespace-nowrap text-right">
                            <div className="flex justify-end gap-1">
                              <Button
                                size="icon"
                                className="h-7 w-7"
                                variant="ghost"
                                onClick={() => setViewingPayment(payment)}
                                aria-label="View payment"
                              >
                                <Icon icon="solar:eye-bold-duotone" className="h-4 w-4" />
                              </Button>
                              {editable && (
                                <Button
                                  size="icon"
                                  className="h-7 w-7"
                                  variant="ghost"
                                  onClick={() => setEditingPayment(payment)}
                                  aria-label="Edit unposted payment"
                                  title="Edit unposted payment"
                                >
                                  <Icon icon="solar:pen-bold-duotone" className="h-4 w-4" />
                                </Button>
                              )}
                              {postable && (
                                <Button
                                  size="icon"
                                  className="h-7 w-7"
                                  variant="ghost"
                                  disabled={bulkPostMutation.isPending}
                                  onClick={() => bulkPostMutation.mutate([payment.id])}
                                  aria-label="Post payment to ledger"
                                  title="Post payment to ledger"
                                >
                                  <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 text-primary" />
                                </Button>
                              )}
                              {correctable && (
                                <>
                                  <Button
                                    size="icon"
                                  className="h-7 w-7"
                                    variant="ghost"
                                    onClick={() => setCorrectionTarget({ payment, action: 'reverse' })}
                                    aria-label="Reverse payment"
                                    title="Reverse payment (creates a reversing journal entry)"
                                  >
                                    <Icon icon="solar:undo-left-round-bold" className="h-4 w-4 text-destructive" />
                                  </Button>
                                  <Button
                                    size="icon"
                                  className="h-7 w-7"
                                    variant="ghost"
                                    onClick={() => setCorrectionTarget({ payment, action: 'refund' })}
                                    aria-label="Refund payment"
                                    title="Refund payment to customer"
                                  >
                                    <Icon icon="solar:refresh-circle-bold-duotone" className="h-4 w-4 text-amber-600" />
                                  </Button>
                                  {payment.invoice && (
                                    <Button
                                      size="icon"
                                  className="h-7 w-7"
                                      variant="ghost"
                                      onClick={() => setCorrectionTarget({ payment, action: 'unapply' })}
                                      aria-label="Unapply payment from invoice"
                                      title="Unapply from invoice (keep payment on account)"
                                    >
                                      <Icon icon="solar:link-broken-bold-duotone" className="h-4 w-4" />
                                    </Button>
                                  )}
                                </>
                              )}
                              {!editable && !correctable && !postable && (
                                <span className="text-xs text-muted-foreground self-center px-1">Locked</span>
                              )}
                            </div>
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </div>

              {/* Pagination */}
              <Pagination
                page={page}
                totalPages={totalPages}
                onPageChange={(next) => { setPage(next); updateUrl('page', String(next)); }}
                from={(page - 1) * perPage + 1}
                to={Math.min(page * perPage, totalRecords)}
                total={totalRecords}
                label="payments"
                pageSize={perPage}
                onPageSizeChange={(size) => { setPerPage(size); setPage(1); updateUrl('page', '1'); }}
              />
            </>
          )}
        </CardContent>
      </Card>

      {/* View Payment Dialog (§7) */}
      {viewingPayment && (
        <ViewPaymentDialog
          payment={viewingPayment}
          onClose={() => setViewingPayment(null)}
        />
      )}

      {editingPayment && (
        <Dialog open onOpenChange={(open) => { if (!open) setEditingPayment(null); }}>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>Edit Draft Payment</DialogTitle>
            </DialogHeader>
            <EditPaymentForm
              payment={editingPayment}
              unpaidInvoices={unpaidInvoices || []}
              onSuccess={() => {
                setEditingPayment(null);
                invalidate();
                queryClient.invalidateQueries({ queryKey: ['unpaid-invoices'] });
                toast({ title: 'Draft payment updated successfully' });
              }}
            />
          </DialogContent>
        </Dialog>
      )}

      {/* Payment Correction Dialog (reverse / refund / unapply) */}
      {correctionTarget && (
        <PaymentCorrectionDialog
          payment={correctionTarget.payment}
          action={correctionTarget.action}
          open={true}
          onOpenChange={(open) => { if (!open) setCorrectionTarget(null); }}
          onSuccess={handleCorrectionSuccess}
        />
      )}

      {/* Bulk reverse confirmation */}
      <Dialog open={bulkReverseOpen} onOpenChange={(open) => !bulkReverseMutation.isPending && setBulkReverseOpen(open)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reverse {reversibleSelected.length} payment(s)?</DialogTitle>
            <DialogDescription>
              Each payment gets a reversing journal entry and moves to Reversed. This cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-2">
              <Label htmlFor="bulk-reverse-code">Reason</Label>
              <Select value={bulkReverseCode} onValueChange={setBulkReverseCode} disabled={bulkReverseMutation.isPending}>
                <SelectTrigger id="bulk-reverse-code"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {[
                    { value: 'DATA_ENTRY_ERROR', label: 'Data entry error' },
                    { value: 'DUPLICATE_PAYMENT', label: 'Duplicate payment' },
                    { value: 'CUSTOMER_REQUEST', label: 'Customer request' },
                    { value: 'BANK_ERROR', label: 'Bank error' },
                    { value: 'OVERPAYMENT', label: 'Overpayment' },
                    { value: 'OTHER', label: 'Other' },
                  ].map((option) => (
                    <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="bulk-reverse-text">Notes (optional)</Label>
              <Input
                id="bulk-reverse-text"
                value={bulkReverseReason}
                onChange={(event) => setBulkReverseReason(event.target.value)}
                placeholder="e.g. Duplicate of PAY/2026/09/xxx"
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBulkReverseOpen(false)} disabled={bulkReverseMutation.isPending}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={bulkReverseMutation.isPending || reversibleSelected.length === 0}
              onClick={() => {
                bulkReverseMutation.mutate({
                  ids: reversibleSelected.map((payment) => payment.id),
                  reasonCode: bulkReverseCode,
                  reasonText: bulkReverseReason,
                });
                setBulkReverseOpen(false);
              }}
            >
              {bulkReverseMutation.isPending ? 'Reversing…' : 'Reverse payments'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Bulk Delete Confirmation */}
      <AlertDialog open={showBulkDeleteDialog} onOpenChange={setShowBulkDeleteDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete selected unposted payments?</AlertDialogTitle>
            <AlertDialogDescription>
              {lockedSelected > 0 && (
                <p className="mb-2 text-amber-600">
                  {lockedSelected} selected payment(s) are already in the ledger and will be skipped.
                  Use refund or reversal to correct posted payments.
                </p>
              )}
              This action permanently removes the selected unposted payments and restores
              the linked invoice balances. This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                const ids = allMatchingSelected ? selectedIds : deletableSelected.map((p) => p.id);
                if (ids.length === 0) return;
                api.post('/payments/bulk-delete', { ids })
                  .then((res) => {
                    const data = res.data;
                    queryClient.invalidateQueries({ queryKey: ['customer-payments'] });
                    clearSelection();
                    setShowBulkDeleteDialog(false);
                    toast({
                      title: `${data.deleted?.length || 0} payment(s) deleted`,
                      description: data.skipped?.length ? `${data.skipped.length} skipped.` : undefined,
                    });
                  })
                  .catch((err) => {
                    toast({
                      variant: 'destructive',
                      title: 'Bulk delete failed',
                      description: err.response?.data?.message || 'An error occurred',
                    });
                  });
              }}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

// View Payment Dialog (§7 — View payment action)
// Fetches the full record from the API so the details are always complete
// and current, even if list-row data is stale or trimmed.
const ViewPaymentDialog: React.FC<{
  payment: Payment;
  onClose: () => void;
}> = ({ payment, onClose }) => {
  const navigate = useNavigate();
  const { data: full, isLoading, isError } = useQuery({
    queryKey: ['payment-detail', payment.id],
    queryFn: async () => {
      const response = await api.get(`/payments/${payment.id}`);
      return response.data as Payment;
    },
    retry: 1,
  });

  const detail: Payment | null = full ?? payment;

  // #825 (EINV-UX8): a refund record shows its LHDN refund-note status.
  const refundNote = useEInvoiceDocument('refund_note', detail.correction_type === 'refund' ? detail.id : undefined);

  return (
    <Dialog open onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <div className="flex items-center justify-between gap-3 pr-6">
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:wallet-money-bold-duotone" className="h-5 w-5 text-primary" />
              Payment {detail.payment_number || `PAY-${detail.id}`}
            </DialogTitle>
            {/* #618: the receipt print/send menu. Payments have no customer
                send endpoint, so Email/WhatsApp are hidden. */}
            <PrintSendMenu
              documentType="receipt"
              pdfPath={`/payments/${detail.id}/pdf`}
              filename={`receipt-${detail.payment_number || detail.id}.pdf`}
              title="Payment Receipt"
              resolutionPath={`/documents/receipt/resolution?document_id=${detail.id}`}
            />
          </div>
        </DialogHeader>
        {isLoading ? (
          <div className="py-8 text-center text-muted-foreground">Loading payment details…</div>
        ) : isError && !full ? (
          <div className="py-8 text-center text-muted-foreground">
            Could not load full details. Showing cached row data.
          </div>
        ) : (
        <Tabs defaultValue="details" className="space-y-4">
          <TabsList className="h-9 w-fit rounded-full">
            <TabsTrigger value="details" className="rounded-full px-4 text-xs">
              Details
            </TabsTrigger>
            <TabsTrigger value="audit" className="gap-1.5 rounded-full px-4 text-xs">
              <Icon icon="solar:shield-check-bold-duotone" className="h-3.5 w-3.5" />
              Audit
            </TabsTrigger>
          </TabsList>

          <TabsContent value="details" className="space-y-4 mt-4">
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted-foreground">Status</span>
            <div className="flex flex-wrap items-center justify-end gap-2">
              {/* #825 (EINV-UX8): the refund note's LHDN status, when issued. */}
              {detail.correction_type === 'refund' && refundNote.document && (
                <EInvoiceStatusChip
                  status={refundNote.status}
                  uuid={refundNote.latestSubmission?.external_document_uuid}
                  validationLink={refundNote.latestSubmission?.validation_link}
                  reason={refundNote.latestSubmission?.rejection_reason ?? refundNote.latestSubmission?.error_message}
                />
              )}
              {PAYMENT_STATUS_OPTIONS.find((s) => s.value === detail.payment_status) ? (
                <Badge variant={PAYMENT_STATUS_OPTIONS.find((s) => s.value === detail.payment_status)?.variant || 'secondary'} className="gap-1">
                  <Icon icon={PAYMENT_STATUS_OPTIONS.find((s) => s.value === detail.payment_status)?.icon || 'solar:document-bold-duotone'} className="h-3 w-3" />
                  {PAYMENT_STATUS_OPTIONS.find((s) => s.value === detail.payment_status)?.label || detail.payment_status}
                </Badge>
              ) : (
                <Badge variant="secondary">{detail.payment_status}</Badge>
              )}
              <PaymentSourceBadge source={detail.source} />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4 border-t pt-4">
            <div>
              <p className="text-xs text-muted-foreground">Payment Date</p>
              <p className="font-medium">{formatDate(detail.payment_date)}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Amount</p>
              <p className="font-medium text-lg">
                {formatCurrency(detail.amount || 0, detail.currency || undefined)}
              </p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Customer</p>
              <p className="font-medium">{detail.customer?.name || '-'}</p>
              {detail.customer?.code && (
                <p className="text-xs font-mono text-muted-foreground">{detail.customer.code}</p>
              )}
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Method</p>
              <p className="font-medium capitalize">{detail.method?.replace(/_/g, ' ')}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Deposit To</p>
              <p className="font-medium">
                {detail.bank_account
                  ? `${detail.bank_account.account_name}${detail.bank_account.gl_account_code ? ` · ${detail.bank_account.gl_account_code}` : ''}`
                  : detail.bank_account_id
                    ? `Account #${detail.bank_account_id}`
                    : 'Default bank mapping'}
              </p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Reference</p>
              <p className="font-medium">{detail.reference || '-'}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Allocated To Invoice</p>
              {detail.invoice ? (
                <button
                  type="button"
                  onClick={() => {
                    onClose();
                    navigate(`/invoices/${detail.invoice!.id}`);
                  }}
                  className="text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-primary/20 rounded font-medium text-left"
                >
                  {detail.invoice.invoice_number}
                </button>
              ) : (
                <Badge variant="outline" className="font-normal">Unallocated</Badge>
              )}
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Ledger Entry</p>
              {detail.journal_entry_id ? (
                <p className="font-medium font-mono text-sm">
                  <button
                    type="button"
                    onClick={() => navigate(`/accounting/core/journal-entries?entry_id=${detail.journal_entry_id}`)}
                    className="font-medium text-primary underline-offset-2 hover:underline"
                  >
                    {(detail as any).journal_entry?.entry_number ?? `JE #${detail.journal_entry_id}`}
                  </button>
                </p>
              ) : (
                <span className="text-sm text-muted-foreground">Not posted</span>
              )}
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Reversal Entry</p>
              {detail.reversal_journal_entry_id ? (
                <p className="font-medium font-mono text-sm">JE #{detail.reversal_journal_entry_id}</p>
              ) : (
                <span className="text-sm text-muted-foreground">-</span>
              )}
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Created</p>
              <p className="font-medium">{detail.created_at ? formatDate(detail.created_at) : '-'}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">ID</p>
              <p className="font-mono text-xs">#{detail.id}</p>
            </div>
          </div>

          {/* #488 (PAY-UX2): the #155 applications, unapplied amount and Allocate. */}
          <PaymentAllocationsPanel
            paymentId={detail.id}
            paymentLabel={detail.payment_number || `PAY-${detail.id}`}
            customerId={detail.customer_id ?? detail.customer?.id ?? null}
            paymentStatus={detail.payment_status}
            currency={detail.currency}
            onNavigate={onClose}
          />

          {detail.notes && (
            <div className="border-t pt-4">
              <p className="text-xs text-muted-foreground mb-1">Notes</p>
              <p className="text-sm whitespace-pre-wrap">{detail.notes}</p>
            </div>
          )}

          {detail.correction_type && (
            <div className="border-t pt-4 space-y-1 rounded-md bg-destructive/5 p-3">
              <p className="text-xs font-medium flex items-center gap-1">
                <Icon icon="solar:pen-bold-duotone" className="h-3 w-3" />
                Correction recorded
              </p>
              <p className="text-xs text-muted-foreground">
                Type: <span className="font-mono">{detail.correction_type}</span>
                {detail.correction_reason_code && (
                  <> · Reason: <span className="font-mono">{detail.correction_reason_code}</span></>
                )}
              </p>
              {detail.correction_reason_text && (
                <p className="text-xs text-muted-foreground whitespace-pre-wrap">{detail.correction_reason_text}</p>
              )}
            </div>
          )}

          {detail.journal_entry_id != null && (
            <div className="border-t pt-4 flex items-start gap-2 rounded-md bg-muted/50 p-3">
              <Icon icon="solar:lock-keyhole-bold-duotone" className="h-4 w-4 text-muted-foreground mt-0.5" />
              <p className="text-xs text-muted-foreground">
                This payment is posted and cannot be edited or deleted. To correct it, use the
                Reverse, Refund, or Unapply actions in the payments list — they create proper
                reversing journal entries instead of altering historical accounting values.
              </p>
            </div>
          )}
        </div>
          </TabsContent>

          <TabsContent value="audit" className="mt-4">
            <AuditTab endpoint={`/payments/${detail.id}/audit`} permission="accounting.view" />
          </TabsContent>
        </Tabs>
        )}
      </DialogContent>
    </Dialog>
  );
};


// Record Payment Form Component
const EditPaymentForm: React.FC<{
  payment: Payment;
  unpaidInvoices: any[];
  onSuccess: () => void;
}> = ({ payment, unpaidInvoices, onSuccess }) => {
  const [formData, setFormData] = useState({
    invoice_id: payment.invoice_id ? String(payment.invoice_id) : '',
    amount: String(payment.amount),
    payment_date: payment.payment_date.slice(0, 10),
    method: payment.method,
    reference: payment.reference || '',
    notes: payment.notes || '',
    // #485: prefill from the stored deposit account; no silent auto-fill for
    // legacy drafts (saving must not change the posting target unasked).
    bank_account_id: payment.bank_account_id ? String(payment.bank_account_id) : '',
  });
  const { user: authUser } = useAuthStore();
  const { data: depositOptions, isLoading: depositLoading } = useQuery<DepositAccountOption[]>({
    queryKey: ['bank-accounts'],
    queryFn: async () => {
      const response = await api.get('/accounting/bank-accounts');
      return response.data.data as DepositAccountOption[];
    },
  });
  const { data: methodRows } = useQuery<PaymentMethodRow[]>({
    queryKey: ['payment-methods'],
    queryFn: async () => {
      const response = await api.get('/accounting/payment-methods');
      return response.data.data as PaymentMethodRow[];
    },
  });
  const methodOptions = buildMethodOptions(methodRows);
  const { toast } = useToast();

  const updatePaymentMutation = useMutation({
    mutationFn: async () => {
      const response = await api.put(`/payments/${payment.id}`, {
        ...formData,
        invoice_id: formData.invoice_id ? Number(formData.invoice_id) : null,
        amount: Number(formData.amount),
        bank_account_id: formData.bank_account_id ? Number(formData.bank_account_id) : null,
      });
      return response.data;
    },
    onSuccess,
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update payment',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const selectedInvoice = unpaidInvoices.find((invoice) => invoice.id === Number(formData.invoice_id));
  const availableBalance = selectedInvoice
    ? Number(selectedInvoice.balance || 0) + (selectedInvoice.id === payment.invoice_id ? Number(payment.amount) : 0)
    : null;

  return (
    <form onSubmit={(event) => { event.preventDefault(); updatePaymentMutation.mutate(); }} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="edit_invoice_id">Invoice</Label>
          <InvoiceSearchCombobox
            value={formData.invoice_id}
            onValueChange={(value) => setFormData((current) => ({ ...current, invoice_id: value }))}
            placeholder="Search invoice number, customer code, or name..."
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="edit_amount">Amount</Label>
          <Input id="edit_amount" type="number" min="0.01" step="0.01" required value={formData.amount}
            onChange={(event) => setFormData((current) => ({ ...current, amount: event.target.value }))} />
          {availableBalance !== null && (
            <p className="text-xs text-muted-foreground">Available invoice balance: {formatCurrency(availableBalance)}</p>
          )}
        </div>
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="edit_payment_date">Payment Date</Label>
          <Input id="edit_payment_date" type="date" required value={formData.payment_date}
            onChange={(event) => setFormData((current) => ({ ...current, payment_date: event.target.value }))} />
        </div>
        <div className="space-y-2">
          <Label>Payment Method</Label>
          <Select value={formData.method} onValueChange={(value) => setFormData((current) => ({ ...current, method: value }))}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              {methodOptions.map((method) => <SelectItem key={method.value} value={method.value}>{method.label}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
      </div>
      <div className="space-y-2">
        <Label htmlFor="edit_reference">Reference Number</Label>
        <Input id="edit_reference" value={formData.reference}
          onChange={(event) => setFormData((current) => ({ ...current, reference: event.target.value }))} />
      </div>
      <div className="space-y-2">
        <Label htmlFor="edit_notes">Notes</Label>
        <Input id="edit_notes" value={formData.notes}
          onChange={(event) => setFormData((current) => ({ ...current, notes: event.target.value }))} />
      </div>
      <DepositAccountSelect
        id="edit_deposit_account_id"
        value={formData.bank_account_id}
        onChange={(value) => {
          if (authUser && value) rememberDeposit(authUser.id, formData.method, Number(value));
          setFormData((current) => ({ ...current, bank_account_id: value }));
        }}
        options={depositOptions}
        isLoading={depositLoading}
      />

      <div className="flex justify-end pt-4">
        <Button type="submit" disabled={updatePaymentMutation.isPending}>
          {updatePaymentMutation.isPending ? 'Saving...' : 'Save Changes'}
        </Button>
      </div>
    </form>
  );
};

export default CustomerPayments;
