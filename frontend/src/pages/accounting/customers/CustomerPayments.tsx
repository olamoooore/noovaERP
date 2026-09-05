import React, { useState, useEffect, useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { InvoiceSearchCombobox, type InvoiceSearchItem } from '@/components/ui/InvoiceSearchCombobox';
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
import {
  PaymentCorrectionDialog,
  type PaymentCorrectionAction,
  type PaymentCorrectionOutcome,
} from '@/components/payment/PaymentCorrectionDialog';

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
}

interface PaginatedPayments {
  data: Payment[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const PAYMENT_STATUS_OPTIONS = [
  { value: 'draft', label: 'Draft', icon: 'solar:document-bold-duotone', variant: 'secondary' as const },
  { value: 'posted', label: 'Posted', icon: 'solar:check-circle-bold-duotone', variant: 'default' as const },
  { value: 'recorded', label: 'Recorded', icon: 'solar:check-circle-bold', variant: 'default' as const },
  { value: 'applied', label: 'Applied', icon: 'solar:wallet-bold-duotone', variant: 'default' as const },
  { value: 'unapplied', label: 'Unapplied', icon: 'solar:link-broken-bold-duotone', variant: 'outline' as const },
  { value: 'cancelled', label: 'Cancelled', icon: 'solar:close-circle-bold-duotone', variant: 'outline' as const },
  { value: 'reversed', label: 'Reversed', icon: 'solar:undo-left-round-bold', variant: 'destructive' as const },
  { value: 'refunded', label: 'Refunded', icon: 'solar:refresh-circle-bold-duotone', variant: 'destructive' as const },
];

// Statuses that represent a completed terminal state — no further correction is
// allowed once a payment reaches one of these. Mirrors the backend guard in
// PaymentCorrectionService::assertCorrectable().
const TERMINAL_PAYMENT_STATUSES = ['reversed', 'refunded', 'cancelled', 'unapplied'];

const isCorrectable = (payment: Payment): boolean =>
  payment.payment_status !== 'draft'
  && !TERMINAL_PAYMENT_STATUSES.includes(payment.payment_status)
  && !payment.refund_payment_id;

const METHOD_OPTIONS = [
  { value: 'cash', label: 'Cash', icon: 'solar:wallet-bold-duotone' },
  { value: 'bank_transfer', label: 'Bank Transfer', icon: 'solar:banknote-bold-duotone' },
  { value: 'card', label: 'Card', icon: 'solar:card-2-bold-duotone' },
  { value: 'check', label: 'Check', icon: 'solar:document-bold-duotone' },
  { value: 'other', label: 'Other', icon: 'solar:question-circle-bold-duotone' },
];

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
  const [statusFilter, setStatusFilter] = useState(searchParams.get('payment_status') || '');
  const [methodFilter, setMethodFilter] = useState(searchParams.get('method') || '');
  const [customerFilter, setCustomerFilter] = useState(searchParams.get('customer_id') || '');
  const [dateFrom, setDateFrom] = useState(searchParams.get('date_from') || '');
  const [dateTo, setDateTo] = useState(searchParams.get('date_to') || '');
  const [page, setPage] = useState(Number(searchParams.get('page') || 1));
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [showBulkDeleteDialog, setShowBulkDeleteDialog] = useState(false);
  const [viewingPayment, setViewingPayment] = useState<Payment | null>(null);
  const [editingPayment, setEditingPayment] = useState<Payment | null>(null);
  const [correctionTarget, setCorrectionTarget] = useState<{
    payment: Payment;
    action: PaymentCorrectionAction;
  } | null>(null);
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const { hasPermission } = useAuthStore();
  // Correction endpoints (POST /corrections/payments/…) require accounting.post
  const canCorrect = hasPermission('accounting.post');

  // Fetch payments (paginated, filtered)
  const queryKey = useMemo(
    () => ['customer-payments', { searchQuery, statusFilter, methodFilter, customerFilter, dateFrom, dateTo, invoiceIdParam, page }],
    [searchQuery, statusFilter, methodFilter, customerFilter, dateFrom, dateTo, invoiceIdParam, page],
  );

  const { data: payments, isLoading } = useQuery({
    queryKey,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (searchQuery) params.append('search', searchQuery);
      if (statusFilter) params.append('payment_status', statusFilter);
      if (methodFilter) params.append('method', methodFilter);
      if (customerFilter) params.append('customer_id', customerFilter);
      if (dateFrom) params.append('date_from', dateFrom);
      if (dateTo) params.append('date_to', dateTo);
      if (invoiceIdParam) params.append('invoice_id', invoiceIdParam);
      params.append('page', String(page));
      params.append('per_page', '20');

      const response = await api.get(`/payments?${params.toString()}`);
      return response.data as PaginatedPayments;
    },
  });

  const paymentList = payments?.data || [];
  const totalPages = payments?.last_page || 1;
  const totalRecords = payments?.total || 0;

  // Fetch unpaid invoices for payment allocation.
  const { data: unpaidInvoices } = useQuery({
    queryKey: ['unpaid-invoices'],
    queryFn: async () => {
      const response = await api.get('/invoices?status=posted,sent,overdue,partial');
      return response.data.data || [];
    },
    enabled: showRecordPayment || editingPayment !== null,
  });

  // Customer list for the Customer filter dropdown (§7 — recommended filter).
  // Capped at 200 customers; the search box above already covers full-text lookup.
  const { data: customers } = useQuery({
    queryKey: ['customers-filter-list'],
    queryFn: async () => {
      const response = await api.get('/customers?per_page=200');
      return response.data.data || [];
    },
  });

  const getPaymentMethodBadge = (method: string) => {
    const cfg = METHOD_OPTIONS.find((m) => m.value === method);
    return (
      <Badge variant="outline" className="gap-1">
        <Icon icon={cfg?.icon || 'solar:wallet-bold-duotone'} className="h-3 w-3" />
        {cfg?.label || method}
      </Badge>
    );
  };

  const getStatusBadge = (status: string) => {
    const cfg = PAYMENT_STATUS_OPTIONS.find((s) => s.value === status) || {
      value: status,
      label: status,
      icon: 'solar:document-bold-duotone',
      variant: 'secondary' as const,
    };
    return (
      <Badge variant={cfg.variant} className="gap-1">
        <Icon icon={cfg.icon} className="h-3 w-3" />
        {cfg.label}
      </Badge>
    );
  };

  const toggleSelect = (id: number) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleSelectAll = () => {
    if (selectedIds.size === paymentList.length) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(paymentList.map((p) => p.id)));
    }
  };

  const draftSelected = paymentList.filter(
    (p) => selectedIds.has(p.id) && p.payment_status === 'draft',
  );
  const postableSelected = paymentList.filter(
    (p) => selectedIds.has(p.id) && p.payment_status === 'draft' && !p.journal_entry_id,
  );
  const lockedSelected = selectedIds.size - draftSelected.length;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['customer-payments'] });

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
      setSelectedIds(new Set());
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
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Customer Payments</h1>
          <p className="text-muted-foreground mt-1">Track payments received from customers</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => window.location.href = '/accounting/customers/payments/import'}>
            <Icon icon="solar:upload-bold-duotone" className="h-4 w-4 mr-2" />
            Import
          </Button>
          <Dialog open={showRecordPayment} onOpenChange={setShowRecordPayment}>
            <DialogTrigger asChild>
              <Button>
                <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
                Record Payment
              </Button>
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
      <Card>
        <CardContent className="pt-6 space-y-3">
          <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <div className="lg:col-span-2">
              <Label htmlFor="search" className="text-xs">Search</Label>
              <Input
                id="search"
                placeholder="Payment #, reference, customer, invoice..."
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setPage(1);
                  updateUrl('search', e.target.value);
                }}
                className="mt-1"
              />
            </div>
            <div>
              <Label className="text-xs">Status</Label>
              <Select
                value={statusFilter || 'all'}
                onValueChange={(value) => {
                  setStatusFilter(value === 'all' ? '' : value);
                  setPage(1);
                  updateUrl('payment_status', value === 'all' ? '' : value);
                }}
              >
                <SelectTrigger className="mt-1"><SelectValue placeholder="All statuses" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All statuses</SelectItem>
                  {PAYMENT_STATUS_OPTIONS.map((s) => (
                    <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label className="text-xs">Method</Label>
              <Select
                value={methodFilter || 'all'}
                onValueChange={(value) => {
                  setMethodFilter(value === 'all' ? '' : value);
                  setPage(1);
                  updateUrl('method', value === 'all' ? '' : value);
                }}
              >
                <SelectTrigger className="mt-1"><SelectValue placeholder="All methods" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All methods</SelectItem>
                  {METHOD_OPTIONS.map((m) => (
                    <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label className="text-xs">Customer</Label>
              <Select
                value={customerFilter || 'all'}
                onValueChange={(value) => {
                  setCustomerFilter(value === 'all' ? '' : value);
                  setPage(1);
                  updateUrl('customer_id', value === 'all' ? '' : value);
                }}
              >
                <SelectTrigger className="mt-1"><SelectValue placeholder="All customers" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All customers</SelectItem>
                  {(customers || []).map((c: any) => (
                    <SelectItem key={c.id} value={String(c.id)}>
                      {c.code ? `${c.code} — ${c.name}` : c.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label className="text-xs">From</Label>
              <Input
                type="date"
                value={dateFrom}
                onChange={(e) => {
                  setDateFrom(e.target.value);
                  setPage(1);
                  updateUrl('date_from', e.target.value);
                }}
                className="mt-1"
              />
            </div>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <Label className="text-xs">To</Label>
              <Input
                type="date"
                value={dateTo}
                onChange={(e) => {
                  setDateTo(e.target.value);
                  setPage(1);
                  updateUrl('date_to', e.target.value);
                }}
                className="mt-1"
              />
            </div>
            <div className="flex items-end">
              <Button variant="outline" onClick={clearFilters}>
                <Icon icon="solar:refresh-bold-duotone" className="h-4 w-4 mr-2" />
                Clear filters
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Bulk actions bar */}
      {selectedIds.size > 0 && (
        <Card>
          <CardContent className="py-3 flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Icon icon="solar:check-square-bold" className="h-4 w-4 text-primary" />
              <span className="text-sm font-medium">{selectedIds.size} selected</span>
              {lockedSelected > 0 && (
                <span className="text-xs text-muted-foreground">
                  ({lockedSelected} locked, only drafts can be deleted)
                </span>
              )}
            </div>
            <div className="flex gap-2">
              <Button
                size="sm"
                onClick={() => bulkPostMutation.mutate(postableSelected.map((p) => p.id))}
                disabled={postableSelected.length === 0 || bulkPostMutation.isPending}
              >
                <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 mr-2" />
                {bulkPostMutation.isPending
                  ? 'Posting...'
                  : `Post ${postableSelected.length > 0 ? `${postableSelected.length} draft(s)` : ''}`}
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={() => setShowBulkDeleteDialog(true)}
                disabled={draftSelected.length === 0}
              >
                <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 mr-2" />
                Delete {draftSelected.length > 0 ? `${draftSelected.length} draft(s)` : ''}
              </Button>
              <Button variant="outline" size="sm" onClick={() => setSelectedIds(new Set())}>
                Clear selection
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

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
                  <Button onClick={() => setShowRecordPayment(true)}>
                    <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
                    Record Payment
                  </Button>
                  <Button variant="outline" onClick={() => window.location.href = '/accounting/customers/payments/import'}>
                    <Icon icon="solar:upload-bold-duotone" className="h-4 w-4 mr-2" />
                    Import from CSV
                  </Button>
                </div>
              )}
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-10">
                        <Checkbox
                          checked={selectedIds.size === paymentList.length && paymentList.length > 0}
                          onCheckedChange={toggleSelectAll}
                          aria-label="Select all"
                        />
                      </TableHead>
                      <TableHead>Payment #</TableHead>
                      <TableHead>Date</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Customer</TableHead>
                      <TableHead>Method</TableHead>
                      <TableHead>Reference</TableHead>
                      <TableHead className="text-right">Amount</TableHead>
                      <TableHead>Currency</TableHead>
                      <TableHead>Allocated To</TableHead>
                      <TableHead>Created</TableHead>
                      <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {paymentList.map((payment) => {
                      const editable = payment.payment_status === 'draft' && !payment.journal_entry_id;
                      const postable = payment.payment_status === 'draft' && !payment.journal_entry_id;
                      const correctable = canCorrect && isCorrectable(payment);
                      return (
                        <TableRow key={payment.id} className="hover:bg-accent/50">
                          <TableCell>
                            <Checkbox
                              checked={selectedIds.has(payment.id)}
                              onCheckedChange={() => toggleSelect(payment.id)}
                              aria-label={`Select ${payment.payment_number}`}
                            />
                          </TableCell>
                          <TableCell className="font-medium">
                            {payment.payment_number || `PAY-${payment.id}`}
                          </TableCell>
                          <TableCell>{formatDate(payment.payment_date)}</TableCell>
                          <TableCell>{getStatusBadge(payment.payment_status)}</TableCell>
                          <TableCell>{payment.customer?.name || '-'}</TableCell>
                          <TableCell>{getPaymentMethodBadge(payment.method)}</TableCell>
                          <TableCell className="text-sm text-muted-foreground">
                            {payment.reference || '-'}
                          </TableCell>
                          <TableCell className="text-right font-medium">
                            {formatCurrency(payment.amount || 0)}
                          </TableCell>
                          <TableCell className="text-sm">
                            {payment.currency || '-'}
                          </TableCell>
                          <TableCell>
                            {payment.invoice ? (
                              <button
                                type="button"
                                onClick={() => navigate(`/invoices/${payment.invoice!.id}`)}
                                className="text-sm text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-primary/20 rounded px-1"
                              >
                                {payment.invoice.invoice_number}
                              </button>
                            ) : (
                              <Badge variant="outline" className="font-normal">Unallocated</Badge>
                            )}
                          </TableCell>
                          <TableCell className="text-sm text-muted-foreground">
                            {payment.created_at ? formatDate(payment.created_at) : '-'}
                          </TableCell>
                          <TableCell className="text-right">
                            <div className="flex justify-end gap-1">
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setViewingPayment(payment)}
                                aria-label="View payment"
                              >
                                <Icon icon="solar:eye-bold-duotone" className="h-4 w-4" />
                              </Button>
                              {editable && (
                                <Button
                                  size="sm"
                                  variant="ghost"
                                  onClick={() => setEditingPayment(payment)}
                                  aria-label="Edit draft payment"
                                  title="Edit draft payment"
                                >
                                  <Icon icon="solar:pen-bold-duotone" className="h-4 w-4" />
                                </Button>
                              )}
                              {postable && (
                                <Button
                                  size="sm"
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
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setCorrectionTarget({ payment, action: 'reverse' })}
                                    aria-label="Reverse payment"
                                    title="Reverse payment (creates a reversing journal entry)"
                                  >
                                    <Icon icon="solar:undo-left-round-bold" className="h-4 w-4 text-destructive" />
                                  </Button>
                                  <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setCorrectionTarget({ payment, action: 'refund' })}
                                    aria-label="Refund payment"
                                    title="Refund payment to customer"
                                  >
                                    <Icon icon="solar:refresh-circle-bold-duotone" className="h-4 w-4 text-amber-600" />
                                  </Button>
                                  {payment.invoice && (
                                    <Button
                                      size="sm"
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
              {totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                  <p className="text-sm text-muted-foreground">
                    Page {page} of {totalPages}
                  </p>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => { setPage((p) => Math.max(1, p - 1)); updateUrl('page', String(Math.max(1, page - 1))); }}
                      disabled={page <= 1}
                    >
                      <Icon icon="solar:arrow-left-bold" className="h-4 w-4 mr-1" />
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => { setPage((p) => Math.min(totalPages, p + 1)); updateUrl('page', String(Math.min(totalPages, page + 1))); }}
                      disabled={page >= totalPages}
                    >
                      Next
                      <Icon icon="solar:arrow-right-bold" className="h-4 w-4 ml-1" />
                    </Button>
                  </div>
                </div>
              )}
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

      {/* Bulk Delete Confirmation */}
      <AlertDialog open={showBulkDeleteDialog} onOpenChange={setShowBulkDeleteDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {draftSelected.length} draft payment(s)?</AlertDialogTitle>
            <AlertDialogDescription>
              {lockedSelected > 0 && (
                <p className="mb-2 text-amber-600">
                  {lockedSelected} selected payment(s) are posted/locked and will be skipped.
                  Use refund or reversal to correct posted payments.
                </p>
              )}
              This action permanently removes the selected draft payments and restores
              the linked invoice balances. This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (draftSelected.length === 0) return;
                api.post('/payments/bulk-delete', { ids: draftSelected.map((p) => p.id) })
                  .then((res) => {
                    const data = res.data;
                    queryClient.invalidateQueries({ queryKey: ['customer-payments'] });
                    setSelectedIds(new Set());
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

  return (
    <Dialog open onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="solar:wallet-money-bold-duotone" className="h-5 w-5 text-primary" />
            Payment {detail.payment_number || `PAY-${detail.id}`}
          </DialogTitle>
        </DialogHeader>
        {isLoading ? (
          <div className="py-8 text-center text-muted-foreground">Loading payment details…</div>
        ) : isError && !full ? (
          <div className="py-8 text-center text-muted-foreground">
            Could not load full details. Showing cached row data.
          </div>
        ) : (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <span className="text-sm text-muted-foreground">Status</span>
            {PAYMENT_STATUS_OPTIONS.find((s) => s.value === detail.payment_status) ? (
              <Badge variant={PAYMENT_STATUS_OPTIONS.find((s) => s.value === detail.payment_status)?.variant || 'secondary'} className="gap-1">
                <Icon icon={PAYMENT_STATUS_OPTIONS.find((s) => s.value === detail.payment_status)?.icon || 'solar:document-bold-duotone'} className="h-3 w-3" />
                {PAYMENT_STATUS_OPTIONS.find((s) => s.value === detail.payment_status)?.label || detail.payment_status}
              </Badge>
            ) : (
              <Badge variant="secondary">{detail.payment_status}</Badge>
            )}
          </div>

          <div className="grid grid-cols-2 gap-4 border-t pt-4">
            <div>
              <p className="text-xs text-muted-foreground">Payment Date</p>
              <p className="font-medium">{formatDate(detail.payment_date)}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Amount</p>
              <p className="font-medium text-lg">
                {detail.currency ? `${detail.currency} ` : ''}
                {formatCurrency(detail.amount || 0)}
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
                <p className="font-medium font-mono text-sm">JE #{detail.journal_entry_id}</p>
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

          {detail.payment_status !== 'draft' && (
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
        )}
      </DialogContent>
    </Dialog>
  );
};


// Record Payment Form Component
const RecordPaymentForm: React.FC<{
  unpaidInvoices: any[];
  initialInvoiceId?: string;
  onSuccess: () => void;
}> = ({ unpaidInvoices, initialInvoiceId, onSuccess }) => {
  const [formData, setFormData] = useState({
    customer_id: '',
    invoice_id: initialInvoiceId || '',
    amount: '',
    payment_date: new Date().toISOString().split('T')[0],
    method: 'bank_transfer',
    reference: '',
    notes: '',
  });

  // When initialInvoiceId is provided (deep-linked from invoice detail page),
  // fetch that specific invoice and pre-fill customer + amount. This works
  // regardless of whether the unpaid-invoices list has loaded yet.
  const { data: preselectedInvoice } = useQuery<InvoiceSearchItem>({
    queryKey: ['invoice-preselect', initialInvoiceId],
    queryFn: async () => {
      const res = await api.get(`/invoices/${initialInvoiceId}`);
      return res.data;
    },
    enabled: !!initialInvoiceId,
  });

  useEffect(() => {
    if (preselectedInvoice && !formData.customer_id) {
      setFormData((prev) => ({
        ...prev,
        invoice_id: String(preselectedInvoice.id),
        customer_id: String(preselectedInvoice.customer_id || ''),
        amount: String(preselectedInvoice.balance ?? preselectedInvoice.total ?? ''),
      }));
    }
  }, [preselectedInvoice]);

  const { toast } = useToast();

  const recordPaymentMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post('/payments', data);
      return response.data;
    },
    onSuccess: () => onSuccess(),
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to record payment',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    recordPaymentMutation.mutate(formData);
  };

  const handleInvoiceChange = (value: string, invoice?: InvoiceSearchItem) => {
    setFormData({
      ...formData,
      invoice_id: value,
      customer_id: invoice ? String(invoice.customer_id || '') : '',
      amount: invoice ? String(invoice.balance ?? invoice.total ?? '') : '',
    });
  };

  const selectedBalance = formData.invoice_id
    ? (preselectedInvoice?.id === parseInt(formData.invoice_id)
        ? preselectedInvoice.balance
        : unpaidInvoices.find((inv) => inv.id === parseInt(formData.invoice_id))?.balance)
    : null;

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="invoice_id">Invoice</Label>
          <InvoiceSearchCombobox
            value={formData.invoice_id}
            onValueChange={handleInvoiceChange}
            placeholder="Search invoice number, customer code, or name..."
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="amount">Amount</Label>
          <Input
            id="amount"
            type="number"
            step="0.01"
            value={formData.amount}
            onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
            required
          />
          {selectedBalance != null && (
            <p className="text-xs text-muted-foreground">
              Invoice balance: ${selectedBalance}
            </p>
          )}
        </div>
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="payment_date">Payment Date</Label>
          <Input
            id="payment_date"
            type="date"
            value={formData.payment_date}
            onChange={(e) => setFormData({ ...formData, payment_date: e.target.value })}
            required
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="method">Payment Method</Label>
          <Select value={formData.method} onValueChange={(value) => setFormData({ ...formData, method: value })}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              {METHOD_OPTIONS.map((m) => (
                <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
      <div className="space-y-2">
        <Label htmlFor="reference">Reference Number</Label>
        <Input
          id="reference"
          value={formData.reference}
          onChange={(e) => setFormData({ ...formData, reference: e.target.value })}
          placeholder="Transaction ID, check number, etc."
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor="notes">Notes (Optional)</Label>
        <Input
          id="notes"
          value={formData.notes}
          onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
        />
      </div>
      <div className="flex justify-end gap-2 pt-4">
        <Button type="submit" disabled={recordPaymentMutation.isPending}>
          {recordPaymentMutation.isPending ? 'Recording...' : 'Record Payment'}
        </Button>
      </div>
    </form>
  );
};

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
  });
  const { toast } = useToast();

  const updatePaymentMutation = useMutation({
    mutationFn: async () => {
      const response = await api.put(`/payments/${payment.id}`, {
        ...formData,
        invoice_id: formData.invoice_id ? Number(formData.invoice_id) : null,
        amount: Number(formData.amount),
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
              {METHOD_OPTIONS.map((method) => <SelectItem key={method.value} value={method.value}>{method.label}</SelectItem>)}
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
      <div className="flex justify-end pt-4">
        <Button type="submit" disabled={updatePaymentMutation.isPending}>
          {updatePaymentMutation.isPending ? 'Saving...' : 'Save Changes'}
        </Button>
      </div>
    </form>
  );
};

export default CustomerPayments;
