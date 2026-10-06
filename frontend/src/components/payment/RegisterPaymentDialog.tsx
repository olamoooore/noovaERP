import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { todayDateOnly } from '@/lib/dateOnly'; // #484
import DepositAccountSelect from '@/components/payment/DepositAccountSelect';
import { defaultDepositAccountId, readDepositMemory, rememberDeposit, type DepositAccountOption } from '@/lib/paymentDepositAccount';
import { buildMethodOptions, type PaymentMethodRow } from '@/lib/paymentMethods'; // #486
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { InvoiceSearchCombobox, type InvoiceSearchItem } from '@/components/ui/InvoiceSearchCombobox';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import { buildPaymentPayload, type PaymentFormState } from '@/lib/paymentPayload'; // #487

/** #490: GET /payments/fx-preview — the posting engine's own settlement maths. */
export interface PaymentFxPreview {
  currency: string;
  base_currency_code: string;
  foreign: boolean;
  exchange_rate: number;
  rate_source: string;
  invoice_rate: number;
  amount: number;
  base_amount: number;
  ar_base_amount: number;
  fx_amount: number;
  fx_type: 'gain' | 'loss' | 'none';
}

/** A draft payment as the edit path receives it (#487: same dialog edits drafts). */
export interface EditablePayment {
  id: number;
  invoice_id?: number | null;
  amount: number | string;
  payment_date: string;
  method: string;
  reference?: string | null;
  notes?: string | null;
  bank_account_id?: number | string | null;
}

/**
 * #487 (PAY-UX1): the ONE customer-payment entry dialog. Every entry point —
 * invoice detail, invoice list rows, the Payments page (record AND edit a
 * draft) and the retired /invoices/:id/payment URL (now a redirect) — opens
 * this component, and every submit goes through buildPaymentPayload().
 *
 * Open state: `open` when given; otherwise open while an invoice or a draft
 * payment is set (the #491 call sites pass only `invoiceId`).
 */
export const RegisterPaymentDialog: React.FC<{
  /** Invoice to pay. */
  invoiceId?: number | string | null;
  /** Explicit open flag (e.g. the Payments page "Record Payment" button). */
  open?: boolean;
  /** A draft payment to edit (PUT /payments/{id}) instead of recording one. */
  payment?: EditablePayment | null;
  /** Optional preloaded open invoices (balance hint while the fetch is in flight). */
  unpaidInvoices?: any[];
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
}> = ({ invoiceId = null, open, payment = null, unpaidInvoices = [], onOpenChange, onSuccess }) => {
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const isOpen = open ?? (invoiceId != null || payment != null);
  const isEdit = payment != null;

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Edit draft payment' : 'Register payment'}</DialogTitle>
        </DialogHeader>
        {isOpen && (
          <RecordPaymentForm
            key={isEdit ? `edit-${payment.id}` : String(invoiceId ?? 'new')}
            unpaidInvoices={unpaidInvoices}
            initialInvoiceId={invoiceId != null ? String(invoiceId) : undefined}
            payment={payment}
            onSuccess={() => {
              onOpenChange(false);
              // #477: a draft does not move the invoice, but the payment
              // count/history and payment lists must refresh.
              queryClient.invalidateQueries({ queryKey: ['customer-payments'] });
              queryClient.invalidateQueries({ queryKey: ['unpaid-invoices'] });
              queryClient.invalidateQueries({ queryKey: ['invoice-payments-count'] });
              queryClient.invalidateQueries({ queryKey: ['invoice'] });
              queryClient.invalidateQueries({ queryKey: ['customer-invoice'] });
              queryClient.invalidateQueries({ queryKey: ['invoices'] });
              toast(isEdit
                ? { title: 'Draft payment updated' }
                : { title: 'Draft payment recorded', description: 'Post it from the Payments page to update the ledger.' });
              onSuccess?.();
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  );
};

export const RecordPaymentForm: React.FC<{
  unpaidInvoices?: any[];
  initialInvoiceId?: string;
  /** #487: edit this draft (PUT) instead of recording a new payment (POST). */
  payment?: EditablePayment | null;
  onSuccess: () => void;
}> = ({ unpaidInvoices = [], initialInvoiceId, payment = null, onSuccess }) => {
  const isEdit = payment != null;
  // Pin the deep-link id on mount: the parent strips ?invoice_id right after
  // opening the dialog, and reading the live prop made the preselect query
  // switch keys (and cancel) before its data arrived — leaving Amount blank
  // on the first open.
  const [pinnedInvoiceId] = useState(isEdit ? '' : (initialInvoiceId ?? ''));

  const [formData, setFormData] = useState<PaymentFormState>(() => (payment
    ? {
        customer_id: '',
        invoice_id: payment.invoice_id ? String(payment.invoice_id) : '',
        amount: String(payment.amount),
        payment_date: String(payment.payment_date).slice(0, 10),
        method: payment.method,
        reference: payment.reference || '',
        notes: payment.notes || '',
        // #485: prefill from the stored deposit account; no silent auto-fill
        // for legacy drafts (saving must not change the posting target unasked).
        bank_account_id: payment.bank_account_id ? String(payment.bank_account_id) : '',
      }
    : {
        customer_id: '',
        invoice_id: pinnedInvoiceId,
        amount: '',
        payment_date: todayDateOnly(), // #484: local business date, never UTC
        method: 'bank_transfer',
        reference: '',
        notes: '',
        bank_account_id: '',
      }));
  // #487: optional fields (reference, notes) sit behind "More".
  const [showMore, setShowMore] = useState(false);

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

  // #485: default the deposit account per method, remembered per user
  // (new payments only — editing a draft never re-targets it silently).
  useEffect(() => {
    if (isEdit || formData.bank_account_id || !depositOptions?.length) return;
    const next = defaultDepositAccountId(
      formData.method,
      depositOptions,
      authUser ? readDepositMemory(authUser.id) : {},
    );
    if (next != null) setFormData((prev) => ({ ...prev, bank_account_id: String(next) }));
  }, [isEdit, formData.bank_account_id, formData.method, depositOptions, authUser]);

  // When deep-linked from an invoice, fetch that invoice and pre-fill
  // customer + amount. Works regardless of whether the unpaid-invoices list
  // has loaded yet.
  const { data: preselectedInvoice } = useQuery<InvoiceSearchItem>({
    queryKey: ['invoice-preselect', pinnedInvoiceId],
    queryFn: async () => {
      const res = await api.get(`/invoices/${pinnedInvoiceId}`);
      return res.data;
    },
    enabled: !!pinnedInvoiceId,
  });

  useEffect(() => {
    if (! pinnedInvoiceId || formData.customer_id) return;

    // Prefer the dedicated fetch; fall back to the page's unpaid-invoice list
    // (covers the case where the preselect request is still in flight).
    const source = preselectedInvoice
      ?? unpaidInvoices.find((invoice) => String(invoice.id) === String(pinnedInvoiceId));

    if (! source) return;

    setFormData((prev) => ({
      ...prev,
      invoice_id: String(source.id),
      customer_id: String(source.customer_id || ''),
      amount: String(source.balance ?? source.total ?? ''),
    }));
  }, [pinnedInvoiceId, preselectedInvoice, unpaidInvoices]);

  const { toast } = useToast();

  // #490 (PAY-UX4): foreign-currency invoices show the currency, an editable
  // payment rate (defaulted from the stored rate snapshot), the base amount
  // and the expected realized FX gain/loss — all from the backend preview,
  // which is the same computation posting uses.
  const [rateInput, setRateInput] = useState('');
  const [rateEdited, setRateEdited] = useState(false);
  const amountNumber = Number(formData.amount);
  const editedRate = rateEdited && Number(rateInput) > 0 ? Number(rateInput) : null;
  const { data: fxPreview, error: fxPreviewError } = useQuery<PaymentFxPreview>({
    queryKey: ['payment-fx-preview', formData.invoice_id, formData.amount, formData.payment_date, editedRate],
    queryFn: async () => {
      const res = await api.get('/payments/fx-preview', {
        params: {
          invoice_id: formData.invoice_id,
          amount: amountNumber,
          payment_date: formData.payment_date || undefined,
          exchange_rate: editedRate ?? undefined,
        },
      });
      return res.data.data as PaymentFxPreview;
    },
    // The draft-edit endpoint takes no exchange_rate, so the FX preview (and
    // rate override) belongs to recording only — as before #487.
    enabled: !isEdit && !!formData.invoice_id && amountNumber > 0,
    retry: false,
  });

  useEffect(() => {
    if (!rateEdited && fxPreview?.foreign) setRateInput(String(fxPreview.exchange_rate));
  }, [fxPreview, rateEdited]);

  const recordPaymentMutation = useMutation({
    mutationFn: async (data: Record<string, unknown>) => {
      const response = payment
        ? await api.put(`/payments/${payment.id}`, data)
        : await api.post('/payments', data);
      return response.data;
    },
    onSuccess: () => onSuccess(),
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: isEdit ? 'Failed to update payment' : 'Failed to record payment',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const handleRecordMethodChange = (value: string) => {
    if (isEdit) {
      setFormData({ ...formData, method: value });
      return;
    }
    const next = defaultDepositAccountId(
      value,
      depositOptions ?? [],
      authUser ? readDepositMemory(authUser.id) : {},
    );
    setFormData({
      ...formData,
      method: value,
      bank_account_id: next != null ? String(next) : '',
    });
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (authUser && formData.bank_account_id) {
      rememberDeposit(authUser.id, formData.method, Number(formData.bank_account_id));
    }
    recordPaymentMutation.mutate(buildPaymentPayload(formData, {
      // #490: an edited rate (the bank's actual one) is sent as exchange_rate;
      // otherwise the backend snapshots the payment-date rate itself.
      exchangeRate: !isEdit && editedRate != null && fxPreview?.foreign ? editedRate : null,
    }));
  };

  const handleInvoiceChange = (value: string, invoice?: InvoiceSearchItem) => {
    if (isEdit) {
      setFormData((current) => ({ ...current, invoice_id: value }));
      return;
    }
    setRateEdited(false);
    setRateInput('');
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

  // #487: Reference is optional unless the method master requires it — then
  // it is shown up front instead of behind "More".
  const referenceRequired = methodOptions.find((o) => o.value === formData.method)?.requiresReference || false;

  const referenceField = (
    <div className="space-y-2" data-payment-field="reference">
      <Label htmlFor="reference">Reference Number</Label>
      <Input
        id="reference"
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
        <div className="space-y-2" data-payment-field="invoice">
          <Label htmlFor="invoice_id">Invoice</Label>
          <InvoiceSearchCombobox
            value={formData.invoice_id}
            onValueChange={handleInvoiceChange}
            placeholder="Search invoice number, customer code, or name..."
          />
        </div>
        <div className="space-y-2" data-payment-field="amount">
          <Label htmlFor="amount">Amount</Label>
          <Input
            id="amount"
            type="number"
            min={isEdit ? '0.01' : undefined}
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
        <div className="space-y-2" data-payment-field="payment_date">
          <Label htmlFor="payment_date">Payment Date</Label>
          <Input
            id="payment_date"
            type="date"
            value={formData.payment_date}
            onChange={(e) => setFormData({ ...formData, payment_date: e.target.value })}
            required
          />
        </div>
        <div className="space-y-2" data-payment-field="method">
          <Label htmlFor="method">Payment Method</Label>
          <Select value={formData.method} onValueChange={handleRecordMethodChange}>
            <SelectTrigger id="method"><SelectValue /></SelectTrigger>
            <SelectContent>
              {methodOptions.map((m) => (
                <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
      {fxPreview?.foreign && (
        <div className="rounded-md border p-3 space-y-2" data-testid="payment-fx-preview">
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="exchange_rate">
                Payment rate (1 {fxPreview.currency} = ? {fxPreview.base_currency_code})
              </Label>
              <Input
                id="exchange_rate"
                type="number"
                step="0.000001"
                min="0"
                value={rateInput}
                onChange={(e) => {
                  setRateEdited(true);
                  setRateInput(e.target.value);
                }}
              />
              <p className="text-xs text-muted-foreground">
                Invoice rate: {fxPreview.invoice_rate}
                {!rateEdited && ' · defaulted from the rate snapshot; edit to use the bank\'s rate'}
              </p>
            </div>
            <div className="space-y-1 text-sm">
              <p>Currency: <span className="font-medium">{fxPreview.currency}</span></p>
              <p>
                Base amount:{' '}
                <span className="font-medium" data-testid="fx-base-amount">
                  {fxPreview.base_amount.toFixed(2)} {fxPreview.base_currency_code}
                </span>
              </p>
              <p>
                Expected FX {fxPreview.fx_type === 'loss' ? 'loss' : 'gain'}:{' '}
                <span
                  data-testid="fx-amount"
                  className={fxPreview.fx_type === 'loss' ? 'font-medium text-red-600' : 'font-medium text-green-700'}
                >
                  {Math.abs(fxPreview.fx_amount).toFixed(2)} {fxPreview.base_currency_code}
                </span>
              </p>
            </div>
          </div>
        </div>
      )}
      {fxPreviewError && formData.invoice_id && (
        <p className="text-xs text-red-600">
          {(fxPreviewError as any)?.response?.data?.message || 'Could not preview the exchange rate.'}
        </p>
      )}
      <div data-payment-field="deposit_account">
        <DepositAccountSelect
          id="deposit_account_id"
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
            <Label htmlFor="notes">Notes (Optional)</Label>
            <Input
              id="notes"
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
            />
          </div>
        </div>
      )}

      <div className="flex justify-end gap-2 pt-4">
        <Button type="submit" disabled={recordPaymentMutation.isPending}>
          {isEdit
            ? (recordPaymentMutation.isPending ? 'Saving...' : 'Save Changes')
            : (recordPaymentMutation.isPending ? 'Recording...' : 'Record Payment')}
        </Button>
      </div>
    </form>
  );
};
