import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { formatCurrency, getApiErrorMessage } from '@/lib/utils';
import { useAuthStore } from '@/store/authStore';

// #488 (PAY-UX2): UI for the #155 split-payment / unapplied-credit backend —
// GET/POST /payments/{id}/allocations, the customer's eligible invoices and
// GET /payments?unapplied=1 for the payments still holding credit.

/** Allocating is an accounting write (the POST route sits behind EnsureAccountingWriteAccess). */
const ALLOCATE_PERMISSION = 'accounting.write';
/** Payment reads are gated by accounting.view (#481). */
const VIEW_PERMISSION = 'accounting.view';

/** PaymentAllocationService only allocates applied (posted) payments… */
const ALLOCATABLE_PAYMENT_STATUS = 'applied';
/** …against invoices in one of these posting lifecycles. */
const ALLOCATABLE_INVOICE_LIFECYCLES = ['posted', 'credited'];

const EPSILON = 0.005;
const round2 = (value: number) => Math.round(value * 100) / 100;
const num = (value: unknown) => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

interface PaymentApplicationRow {
  id: number;
  invoice_id: number;
  applied_amount: number | string;
  reversed_at?: string | null;
  created_at?: string | null;
  invoice?: { id: number; invoice_number: string } | null;
}

interface AllocationsResponse {
  data: PaymentApplicationRow[];
  amount?: number;
  applied_amount?: number;
  unapplied_amount?: number;
}

interface EligibleInvoice {
  id: number;
  invoice_number: string;
  invoice_date?: string;
  total: number;
  balance: number;
  status?: string;
  lifecycle_status?: string | null;
}

interface UnappliedPayment {
  id: number;
  payment_number?: string | null;
  payment_date?: string;
  amount: number | string;
  unapplied_amount: number | string;
  currency?: string | null;
}

const formatDate = (date?: string | null) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
};

const invalidateAllocationQueries = (queryClient: ReturnType<typeof useQueryClient>, paymentId: number) => {
  queryClient.invalidateQueries({ queryKey: ['payment-allocations', paymentId] });
  queryClient.invalidateQueries({ queryKey: ['unapplied-payments'] });
  queryClient.invalidateQueries({ queryKey: ['payment-eligible-invoices'] });
  queryClient.invalidateQueries({ queryKey: ['payment-detail', paymentId] });
  queryClient.invalidateQueries({ queryKey: ['payments'] });
  queryClient.invalidateQueries({ queryKey: ['invoice'] });
  queryClient.invalidateQueries({ queryKey: ['customer'] });
};

/**
 * Lists the customer's open posted invoices and posts a split of the payment's
 * unapplied amount across them (POST /payments/{id}/allocations).
 */
export const AllocatePaymentDialog: React.FC<{
  open: boolean;
  onOpenChange: (open: boolean) => void;
  paymentId: number;
  paymentLabel?: string;
  customerId: number;
  unappliedAmount: number;
  currency?: string | null;
  /** Pre-fill this invoice with min(unapplied, balance) — the invoice page's "Apply". */
  preselectInvoiceId?: number;
  onAllocated?: () => void;
}> = ({ open, onOpenChange, paymentId, paymentLabel, customerId, unappliedAmount, currency, preselectInvoiceId, onAllocated }) => {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [amounts, setAmounts] = useState<Record<number, string>>({});

  const { data: invoices = [], isLoading } = useQuery<EligibleInvoice[]>({
    queryKey: ['payment-eligible-invoices', customerId],
    enabled: open,
    queryFn: async () => {
      const response = await api.get('/payments/eligible-invoices', { params: { customer_id: customerId } });
      const rows = (response.data?.data ?? []) as EligibleInvoice[];
      return rows.filter(
        (row) => ALLOCATABLE_INVOICE_LIFECYCLES.includes(String(row.lifecycle_status ?? ''))
          && num(row.balance) > EPSILON,
      );
    },
  });

  useEffect(() => {
    if (!open) {
      setAmounts({});
      return;
    }
    if (preselectInvoiceId == null) return;
    const target = invoices.find((invoice) => invoice.id === preselectInvoiceId);
    if (!target) return;
    setAmounts((current) => (current[target.id] !== undefined
      ? current
      : { ...current, [target.id]: String(round2(Math.min(unappliedAmount, num(target.balance)))) }));
  }, [open, preselectInvoiceId, invoices, unappliedAmount]);

  const lines = useMemo(
    () => invoices
      .map((invoice) => ({ invoice, amount: round2(num(amounts[invoice.id])) }))
      .filter((line) => line.amount > 0),
    [invoices, amounts],
  );
  const total = round2(lines.reduce((sum, line) => sum + line.amount, 0));
  const remaining = round2(unappliedAmount - total);
  const overBalance = lines.filter((line) => line.amount > num(line.invoice.balance) + EPSILON);
  const overTotal = total > unappliedAmount + EPSILON;
  const canSubmit = lines.length > 0 && overBalance.length === 0 && !overTotal;

  const allocateMutation = useMutation({
    mutationFn: async () => api.post(`/payments/${paymentId}/allocations`, {
      applications: lines.map((line) => ({ invoice_id: line.invoice.id, amount: line.amount })),
    }),
    onSuccess: () => {
      invalidateAllocationQueries(queryClient, paymentId);
      toast({ title: 'Payment allocated', description: `${formatCurrency(total, currency || undefined)} applied.` });
      onAllocated?.();
      onOpenChange(false);
    },
    onError: (error) => {
      toast({
        title: 'Could not allocate payment',
        description: getApiErrorMessage(error, 'The allocation was rejected.'),
        variant: 'destructive',
      });
    },
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Allocate {paymentLabel ?? 'payment'}</DialogTitle>
          <DialogDescription>
            Apply the unapplied {formatCurrency(unappliedAmount, currency || undefined)} to this customer's open
            posted invoices. Anything left stays on account as unapplied credit.
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <p className="py-6 text-center text-sm text-muted-foreground">Loading open invoices…</p>
        ) : invoices.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            This customer has no open posted invoices to allocate against.
          </p>
        ) : (
          <div className="max-h-[50vh] overflow-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Invoice</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead className="text-right">Balance</TableHead>
                  <TableHead className="w-40 text-right">Apply</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {invoices.map((invoice) => {
                  const value = amounts[invoice.id] ?? '';
                  const tooMuch = round2(num(value)) > num(invoice.balance) + EPSILON;
                  return (
                    <TableRow key={invoice.id}>
                      <TableCell className="font-medium">{invoice.invoice_number}</TableCell>
                      <TableCell>{formatDate(invoice.invoice_date)}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatCurrency(num(invoice.balance), currency || undefined)}
                      </TableCell>
                      <TableCell className="text-right">
                        <Input
                          type="number"
                          min="0"
                          step="0.01"
                          inputMode="decimal"
                          aria-label={`Amount to apply to ${invoice.invoice_number}`}
                          aria-invalid={tooMuch || undefined}
                          className="h-8 text-right tabular-nums"
                          value={value}
                          onChange={(event) => setAmounts((current) => ({ ...current, [invoice.id]: event.target.value }))}
                        />
                        {tooMuch && (
                          <p className="mt-1 text-xs text-destructive">Exceeds the invoice balance</p>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        )}

        <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted/50 px-3 py-2 text-sm">
          <span>
            Allocating <span className="font-medium tabular-nums">{formatCurrency(total, currency || undefined)}</span>
          </span>
          <span>
            Remaining unapplied{' '}
            <span data-testid="allocation-remaining" className="font-medium tabular-nums">
              {formatCurrency(Math.max(remaining, 0), currency || undefined)}
            </span>
          </span>
        </div>
        {overTotal && (
          <p className="text-sm text-destructive">
            The total exceeds the unapplied amount of {formatCurrency(unappliedAmount, currency || undefined)}.
          </p>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button
            onClick={() => allocateMutation.mutate()}
            disabled={!canSubmit || allocateMutation.isPending}
          >
            {allocateMutation.isPending ? 'Applying…' : 'Apply allocation'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

/**
 * Payment detail: every invoice the payment is applied to, the applied and
 * unapplied amounts, and an "Allocate" action for the unapplied remainder.
 */
export const PaymentAllocationsPanel: React.FC<{
  paymentId: number;
  paymentLabel?: string;
  customerId?: number | null;
  paymentStatus?: string | null;
  currency?: string | null;
  onNavigate?: () => void;
}> = ({ paymentId, paymentLabel, customerId, paymentStatus, currency, onNavigate }) => {
  const canAllocate = useAuthStore((state) => state.hasPermission(ALLOCATE_PERMISSION));
  const [allocateOpen, setAllocateOpen] = useState(false);

  const { data, isLoading, isError } = useQuery<AllocationsResponse>({
    queryKey: ['payment-allocations', paymentId],
    queryFn: async () => {
      const response = await api.get(`/payments/${paymentId}/allocations`);
      return response.data as AllocationsResponse;
    },
  });

  const applications = data?.data ?? [];
  const appliedLive = round2(
    applications.filter((row) => !row.reversed_at).reduce((sum, row) => sum + num(row.applied_amount), 0),
  );
  const unapplied = data?.unapplied_amount != null ? num(data.unapplied_amount) : null;
  const showAllocate = canAllocate
    && paymentStatus === ALLOCATABLE_PAYMENT_STATUS
    && customerId != null
    && unapplied != null
    && unapplied > EPSILON;

  return (
    <div data-testid="payment-allocations" className="space-y-3 border-t pt-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium">Applied to invoices</p>
        {showAllocate && (
          <Button size="sm" variant="outline" className="gap-1.5" onClick={() => setAllocateOpen(true)}>
            <Icon icon="solar:split-bold-duotone" className="h-4 w-4" />
            Allocate
          </Button>
        )}
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading applications…</p>
      ) : isError ? (
        <p className="text-sm text-destructive">Could not load this payment's applications.</p>
      ) : (
        <>
          {applications.length === 0 ? (
            <p className="text-sm text-muted-foreground">Not applied to any invoice yet — held on account.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Invoice</TableHead>
                  <TableHead>Applied on</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {applications.map((row) => (
                  <TableRow key={row.id} className={row.reversed_at ? 'text-muted-foreground' : undefined}>
                    <TableCell>
                      <span className="inline-flex items-center gap-2">
                        {row.invoice ? (
                          <Link
                            to={`/invoices/${row.invoice.id}`}
                            onClick={onNavigate}
                            className="font-medium text-primary hover:underline"
                          >
                            {row.invoice.invoice_number}
                          </Link>
                        ) : (
                          <span>Invoice #{row.invoice_id}</span>
                        )}
                        {row.reversed_at && <Badge variant="outline" className="text-[10px]">Reversed</Badge>}
                      </span>
                    </TableCell>
                    <TableCell>{formatDate(row.created_at)}</TableCell>
                    <TableCell className={`text-right tabular-nums ${row.reversed_at ? 'line-through' : ''}`}>
                      {formatCurrency(num(row.applied_amount), currency || undefined)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}

          <div className="grid grid-cols-2 gap-3 text-sm">
            <div className="rounded-md bg-muted/50 p-3">
              <p className="text-xs text-muted-foreground">Applied</p>
              <p data-testid="applied-amount" className="font-medium tabular-nums">
                {formatCurrency(appliedLive, currency || undefined)}
              </p>
            </div>
            <div className="rounded-md bg-muted/50 p-3">
              <p className="text-xs text-muted-foreground">Unapplied (on account)</p>
              <p data-testid="unapplied-amount" className="font-medium tabular-nums">
                {unapplied != null ? formatCurrency(unapplied, currency || undefined) : '-'}
              </p>
            </div>
          </div>
        </>
      )}

      {showAllocate && customerId != null && unapplied != null && (
        <AllocatePaymentDialog
          open={allocateOpen}
          onOpenChange={setAllocateOpen}
          paymentId={paymentId}
          paymentLabel={paymentLabel}
          customerId={customerId}
          unappliedAmount={unapplied}
          currency={currency}
        />
      )}
    </div>
  );
};

/**
 * The customer's payments still holding unapplied credit — on the customer
 * page, and on an open invoice ("outstanding credits") with an apply action.
 */
export const UnappliedCreditsCard: React.FC<{
  customerId: number;
  /** When shown on an invoice: the invoice the "Apply" action pre-fills. */
  invoiceId?: number;
  invoiceBalance?: number;
  /** Render nothing while there is no unapplied credit (invoice page). */
  hideWhenEmpty?: boolean;
  currency?: string | null;
}> = ({ customerId, invoiceId, invoiceBalance, hideWhenEmpty = false, currency }) => {
  const canAllocate = useAuthStore((state) => state.hasPermission(ALLOCATE_PERMISSION));
  // GET /payments is gated by accounting.view (#481) — don't ask without it.
  const canView = useAuthStore((state) => state.hasPermission(VIEW_PERMISSION));
  const [allocating, setAllocating] = useState<UnappliedPayment | null>(null);

  const { data: payments = [], isLoading, isError } = useQuery<UnappliedPayment[]>({
    queryKey: ['unapplied-payments', customerId],
    enabled: canView,
    queryFn: async () => {
      const response = await api.get('/payments', {
        params: { unapplied: 1, customer_id: customerId, per_page: 100 },
      });
      return (response.data?.data ?? []) as UnappliedPayment[];
    },
  });

  const total = round2(payments.reduce((sum, payment) => sum + num(payment.unapplied_amount), 0));
  const offerApply = canAllocate && invoiceId != null && (invoiceBalance == null || invoiceBalance > EPSILON);

  if (!canView) {
    return null;
  }

  if (hideWhenEmpty && (isLoading || isError || payments.length === 0)) {
    return null;
  }

  return (
    <Card data-testid="unapplied-credits" className="rounded-xl border-slate-200/80 dark:border-slate-800">
      <CardHeader className="space-y-1">
        <CardTitle className="text-lg">{invoiceId != null ? 'Outstanding credits' : 'Unapplied payments'}</CardTitle>
        <CardDescription>
          Payments received from this customer that are not yet applied to an invoice. Total{' '}
          <span data-testid="unapplied-credits-total" className="font-medium tabular-nums text-foreground">
            {formatCurrency(total, currency || undefined)}
          </span>
        </CardDescription>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load unapplied payments.</p>
        ) : payments.length === 0 ? (
          <p className="text-sm text-muted-foreground">No unapplied payments.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Payment</TableHead>
                <TableHead>Date</TableHead>
                <TableHead className="text-right">Amount</TableHead>
                <TableHead className="text-right">Unapplied</TableHead>
                {offerApply && <TableHead className="w-0" />}
              </TableRow>
            </TableHeader>
            <TableBody>
              {payments.map((payment) => (
                <TableRow key={payment.id}>
                  <TableCell className="font-medium">{payment.payment_number || `PAY-${payment.id}`}</TableCell>
                  <TableCell>{formatDate(payment.payment_date)}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatCurrency(num(payment.amount), payment.currency || currency || undefined)}
                  </TableCell>
                  <TableCell className="text-right font-medium tabular-nums">
                    {formatCurrency(num(payment.unapplied_amount), payment.currency || currency || undefined)}
                  </TableCell>
                  {offerApply && (
                    <TableCell className="text-right">
                      <Button size="sm" variant="outline" onClick={() => setAllocating(payment)}>
                        Apply to this invoice
                      </Button>
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>

      {allocating && (
        <AllocatePaymentDialog
          open
          onOpenChange={(open) => { if (!open) setAllocating(null); }}
          paymentId={allocating.id}
          paymentLabel={allocating.payment_number || `PAY-${allocating.id}`}
          customerId={customerId}
          unappliedAmount={num(allocating.unapplied_amount)}
          currency={allocating.currency || currency}
          preselectInvoiceId={invoiceId}
        />
      )}
    </Card>
  );
};
