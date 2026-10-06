import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { todayDateOnly } from '@/lib/dateOnly'; // #484
import DepositAccountSelect from '@/components/payment/DepositAccountSelect';
import { defaultDepositAccountId, readDepositMemory, rememberDeposit, type DepositAccountOption } from '@/lib/paymentDepositAccount';
import { buildMethodOptions, type PaymentMethodRow } from '@/lib/paymentMethods'; // #486
import { buildPaymentPayload, type PaymentFormState } from '@/lib/paymentPayload'; // #487
import { allocateOldestFirst, daysOverdue, sortOldestDueFirst, type ReceiptInvoiceRow } from '@/lib/paymentAllocation';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useAuthStore } from '@/store/authStore';

/** PaymentAllocationService settles only posted receivables (#155/#488). */
const ALLOCATABLE_INVOICE_LIFECYCLES = ['posted', 'credited'];
const round2 = (value: number) => Math.round(value * 100) / 100;

interface OpenInvoice extends ReceiptInvoiceRow {
  lifecycle_status?: string | null;
  currency_code?: string | null;
}

/**
 * #489 (PAY-UX3): customer-first receipt inside RegisterPaymentDialog. Pick
 * the customer, type the amount received — it is allocated oldest-due first
 * across the open invoices (each row editable) and the rest stays as
 * unapplied credit. "Post receipt" sends ONE POST /payments/receipts that
 * records, posts (PostingEngine) and allocates (#155) atomically.
 */
export const CustomerReceiptForm: React.FC<{
  initialCustomerId?: string;
  onSuccess: () => void;
}> = ({ initialCustomerId = '', onSuccess }) => {
  const { toast } = useToast();
  const { user: authUser } = useAuthStore();
  const today = todayDateOnly();

  const [formData, setFormData] = useState<PaymentFormState>({
    customer_id: initialCustomerId,
    invoice_id: '',
    amount: '',
    payment_date: today,
    method: 'bank_transfer',
    reference: '',
    notes: '',
    bank_account_id: '',
  });
  const [applied, setApplied] = useState<Record<number, string>>({});
  const [showMore, setShowMore] = useState(false);

  const { data: depositOptions, isLoading: depositLoading } = useQuery<DepositAccountOption[]>({
    queryKey: ['bank-accounts'],
    queryFn: async () => (await api.get('/accounting/bank-accounts')).data.data as DepositAccountOption[],
  });
  const { data: methodRows } = useQuery<PaymentMethodRow[]>({
    queryKey: ['payment-methods'],
    queryFn: async () => (await api.get('/accounting/payment-methods')).data.data as PaymentMethodRow[],
  });
  const methodOptions = buildMethodOptions(methodRows);

  // #485: default the deposit account per method, remembered per user.
  useEffect(() => {
    if (formData.bank_account_id || !depositOptions?.length) return;
    const next = defaultDepositAccountId(formData.method, depositOptions, authUser ? readDepositMemory(authUser.id) : {});
    if (next != null) setFormData((prev) => ({ ...prev, bank_account_id: String(next) }));
  }, [formData.bank_account_id, formData.method, depositOptions, authUser]);

  const { data: invoices = [], isLoading: invoicesLoading } = useQuery<OpenInvoice[]>({
    queryKey: ['payment-eligible-invoices', formData.customer_id],
    enabled: !!formData.customer_id,
    queryFn: async () => {
      const response = await api.get('/payments/eligible-invoices', { params: { customer_id: formData.customer_id } });
      const rows = (response.data?.data ?? []) as OpenInvoice[];
      return sortOldestDueFirst(rows.filter(
        (row) => ALLOCATABLE_INVOICE_LIFECYCLES.includes(String(row.lifecycle_status ?? '')) && Number(row.balance) > 0.005,
      ));
    },
  });

  const autoAllocate = (amount: string, rows: OpenInvoice[]) => {
    const split = allocateOldestFirst(rows, Number(amount));
    setApplied(Object.fromEntries(Object.entries(split).map(([id, value]) => [id, String(value)])));
  };

  // Re-allocate when the invoice list arrives after the amount was typed.
  useEffect(() => {
    if (formData.amount) autoAllocate(formData.amount, invoices);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [invoices]);

  const applications = useMemo(
    () => invoices
      .filter((invoice) => !invoice.foreign)
      .map((invoice) => ({ invoice_id: invoice.id, amount: round2(Number(applied[invoice.id]) || 0) }))
      .filter((line) => line.amount > 0),
    [invoices, applied],
  );
  const amountNumber = round2(Number(formData.amount) || 0);
  const appliedTotal = round2(applications.reduce((sum, line) => sum + line.amount, 0));
  const unapplied = round2(amountNumber - appliedTotal);
  const overAllocated = unapplied < -0.005;
  const overBalance = invoices.some((invoice) => round2(Number(applied[invoice.id]) || 0) > round2(Number(invoice.balance)) + 0.005);

  const referenceRequired = methodOptions.find((o) => o.value === formData.method)?.requiresReference || false;

  const receiptMutation = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => (await api.post('/payments/receipts', payload)).data,
    onSuccess: (data: { unapplied_amount?: number }) => {
      toast({
        title: 'Receipt posted',
        description: Number(data?.unapplied_amount) > 0
          ? `${formatCurrency(Number(data.unapplied_amount))} kept as unapplied credit.`
          : 'Allocated to the selected invoices.',
      });
      onSuccess();
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to post receipt',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (overAllocated || overBalance) return;
    if (authUser && formData.bank_account_id) {
      rememberDeposit(authUser.id, formData.method, Number(formData.bank_account_id));
    }
    receiptMutation.mutate(buildPaymentPayload(formData, { applications }));
  };

  const referenceField = (
    <div className="space-y-2" data-payment-field="reference">
      <Label htmlFor="receipt_reference">Reference Number</Label>
      <Input
        id="receipt_reference"
        value={formData.reference}
        onChange={(e) => setFormData({ ...formData, reference: e.target.value })}
        placeholder="Transaction ID, check number, etc."
        required={referenceRequired}
      />
    </div>
  );

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2" data-payment-field="customer">
          <Label>Customer</Label>
          <CustomerSearchCombobox
            value={formData.customer_id}
            onValueChange={(value) => {
              setFormData({ ...formData, customer_id: value });
              setApplied({});
            }}
            placeholder="Pick a customer..."
          />
        </div>
        <div className="space-y-2" data-payment-field="amount">
          <Label htmlFor="receipt_amount">Amount received</Label>
          <Input
            id="receipt_amount"
            type="number"
            min="0.01"
            step="0.01"
            value={formData.amount}
            onChange={(e) => {
              setFormData({ ...formData, amount: e.target.value });
              autoAllocate(e.target.value, invoices);
            }}
            required
          />
        </div>
      </div>

      {formData.customer_id && (
        <div className="space-y-2">
          <div className="max-h-64 overflow-auto rounded-md border">
            <Table data-testid="receipt-open-invoices">
              <TableHeader>
                <TableRow>
                  <TableHead>Invoice</TableHead>
                  <TableHead>Due</TableHead>
                  <TableHead className="text-right">Days overdue</TableHead>
                  <TableHead className="text-right">Balance</TableHead>
                  <TableHead className="w-32 text-right">Apply</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {invoices.map((invoice) => (
                  <TableRow key={invoice.id}>
                    <TableCell className="font-medium">{invoice.invoice_number}</TableCell>
                    <TableCell>{invoice.due_date ? String(invoice.due_date).slice(0, 10) : '-'}</TableCell>
                    <TableCell className="text-right tabular-nums">{daysOverdue(invoice.due_date, today) || ''}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatCurrency(Number(invoice.balance))}</TableCell>
                    <TableCell className="text-right">
                      {invoice.foreign ? (
                        <span className="text-xs text-muted-foreground" title="Foreign-currency invoices are paid from the invoice">
                          {invoice.currency_code ?? 'FX'} · pay from invoice
                        </span>
                      ) : (
                        <Input
                          aria-label={`Apply to ${invoice.invoice_number}`}
                          type="number"
                          min="0"
                          step="0.01"
                          className="h-8 text-right"
                          value={applied[invoice.id] ?? ''}
                          onChange={(e) => setApplied((current) => ({ ...current, [invoice.id]: e.target.value }))}
                        />
                      )}
                    </TableCell>
                  </TableRow>
                ))}
                {!invoicesLoading && invoices.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={5} className="py-4 text-center text-sm text-muted-foreground">
                      No open posted invoices — the whole receipt is kept as unapplied credit.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
          <div className="flex flex-wrap justify-end gap-4 text-sm">
            <span>Applied: <span className="font-medium tabular-nums">{formatCurrency(appliedTotal)}</span></span>
            <span data-testid="receipt-unapplied">
              Unapplied credit: <span className="font-medium tabular-nums">{formatCurrency(Math.max(0, unapplied))}</span>
            </span>
          </div>
          {overAllocated && (
            <p className="text-xs text-red-600">The applied total exceeds the amount received.</p>
          )}
          {overBalance && (
            <p className="text-xs text-red-600">An applied amount exceeds that invoice&apos;s open balance.</p>
          )}
        </div>
      )}

      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2" data-payment-field="payment_date">
          <Label htmlFor="receipt_payment_date">Payment Date</Label>
          <Input
            id="receipt_payment_date"
            type="date"
            value={formData.payment_date}
            onChange={(e) => setFormData({ ...formData, payment_date: e.target.value })}
            required
          />
        </div>
        <div className="space-y-2" data-payment-field="method">
          <Label htmlFor="receipt_method">Payment Method</Label>
          <Select
            value={formData.method}
            onValueChange={(value) => {
              const next = defaultDepositAccountId(value, depositOptions ?? [], authUser ? readDepositMemory(authUser.id) : {});
              setFormData({ ...formData, method: value, bank_account_id: next != null ? String(next) : '' });
            }}
          >
            <SelectTrigger id="receipt_method"><SelectValue /></SelectTrigger>
            <SelectContent>
              {methodOptions.map((m) => (
                <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
      <div data-payment-field="deposit_account">
        <DepositAccountSelect
          id="receipt_deposit_account_id"
          value={formData.bank_account_id}
          onChange={(value) => {
            if (authUser && value) rememberDeposit(authUser.id, formData.method, Number(value));
            setFormData({ ...formData, bank_account_id: value });
          }}
          options={depositOptions}
          isLoading={depositLoading}
        />
      </div>
      {referenceRequired && referenceField}

      <div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="px-0 text-muted-foreground"
          aria-expanded={showMore}
          onClick={() => setShowMore((v) => !v)}
        >
          {showMore ? 'Less' : 'More'}
        </Button>
      </div>
      {showMore && (
        <div className="space-y-4">
          {!referenceRequired && referenceField}
          <div className="space-y-2">
            <Label htmlFor="receipt_notes">Notes (Optional)</Label>
            <Input
              id="receipt_notes"
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
            />
          </div>
        </div>
      )}

      <div className="flex items-center justify-between gap-2 pt-4">
        <p className="text-xs text-muted-foreground">Posting records the receipt in the ledger immediately.</p>
        <Button
          type="submit"
          disabled={receiptMutation.isPending || !formData.customer_id || amountNumber <= 0 || overAllocated || overBalance}
        >
          {receiptMutation.isPending ? 'Posting...' : 'Post receipt'}
        </Button>
      </div>
    </form>
  );
};
