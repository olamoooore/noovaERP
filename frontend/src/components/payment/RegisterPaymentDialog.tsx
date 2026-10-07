import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { todayDateOnly } from '@/lib/dateOnly'; // #484
import DepositAccountSelect from '@/components/payment/DepositAccountSelect';
import {
  defaultDepositAccountId,
  readDepositMemory,
  readRememberedMethod,
  rememberDeposit,
  rememberMethod,
  type DepositAccountOption,
} from '@/lib/paymentDepositAccount';
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
import { CustomerReceiptForm } from '@/components/payment/CustomerReceiptForm'; // #489

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

/** #489: posting a customer receipt is a GL post (route: accounting.post). */
const RECEIPT_PERMISSION = 'accounting.post';
/** #508: "Save & post" / Ctrl+Enter hits POST /payments/{id}/post — accounting.post (#480). */
const POST_PAYMENT_PERMISSION = 'accounting.post';
/** Fallback method when nothing is remembered (the pre-#508 default). */
const DEFAULT_METHOD = 'bank_transfer';

/** What a RecordPaymentForm submit did (#508: a payment may be saved AND posted). */
export interface RecordPaymentResult {
  posted: boolean;
  /** The draft was saved but posting it failed (already reported to the user). */
  postFailed?: boolean;
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
 * Open state: `open` when given; otherwise open while an invoice, a customer
 * or a draft payment is set (the #491 call sites pass only `invoiceId`).
 *
 * #489 (PAY-UX3): a new payment that is not tied to one invoice can be entered
 * customer-first (CustomerReceiptForm → POST /payments/receipts, which posts),
 * offered only to users holding accounting.post.
 */
export const RegisterPaymentDialog: React.FC<{
  /** Invoice to pay. */
  invoiceId?: number | string | null;
  /** Explicit open flag (e.g. the Payments page "Record Payment" button). */
  open?: boolean;
  /** A draft payment to edit (PUT /payments/{id}) instead of recording one. */
  payment?: EditablePayment | null;
  /** #489: open customer-first for this customer (the customer page). */
  customerId?: number | string | null;
  /** Optional preloaded open invoices (balance hint while the fetch is in flight). */
  unpaidInvoices?: any[];
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
}> = ({ invoiceId = null, open, payment = null, customerId = null, unpaidInvoices = [], onOpenChange, onSuccess }) => {
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const isOpen = open ?? (invoiceId != null || payment != null || customerId != null);
  const isEdit = payment != null;
  // #489: customer-first posts the receipt, so it needs accounting.post.
  const canPost = useAuthStore((state) => state.hasPermission(RECEIPT_PERMISSION));
  const canChooseMode = !isEdit && invoiceId == null && canPost;
  const [mode, setMode] = useState<'invoice' | 'customer'>(customerId != null ? 'customer' : 'invoice');
  useEffect(() => {
    if (isOpen) setMode(customerId != null ? 'customer' : 'invoice');
  }, [isOpen, customerId]);
  const customerMode = canChooseMode && mode === 'customer';

  const refresh = () => {
    // #477: a draft does not move the invoice, but the payment
    // count/history and payment lists must refresh.
    queryClient.invalidateQueries({ queryKey: ['customer-payments'] });
    queryClient.invalidateQueries({ queryKey: ['unpaid-invoices'] });
    queryClient.invalidateQueries({ queryKey: ['invoice-payments-count'] });
    queryClient.invalidateQueries({ queryKey: ['invoice'] });
    queryClient.invalidateQueries({ queryKey: ['customer-invoice'] });
    queryClient.invalidateQueries({ queryKey: ['invoices'] });
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Edit draft payment' : 'Register payment'}</DialogTitle>
        </DialogHeader>
        {canChooseMode && (
          <div className="inline-flex rounded-md border p-0.5 text-sm" role="group" aria-label="Payment entry mode">
            <Button
              type="button"
              size="sm"
              variant={customerMode ? 'ghost' : 'secondary'}
              aria-pressed={!customerMode}
              onClick={() => setMode('invoice')}
            >
              By invoice
            </Button>
            <Button
              type="button"
              size="sm"
              variant={customerMode ? 'secondary' : 'ghost'}
              aria-pressed={customerMode}
              onClick={() => setMode('customer')}
            >
              By customer
            </Button>
          </div>
        )}
        {isOpen && customerMode && (
          <CustomerReceiptForm
            key={`receipt-${customerId ?? 'new'}`}
            initialCustomerId={customerId != null ? String(customerId) : ''}
            onSuccess={() => {
              onOpenChange(false);
              refresh();
              // #489: the receipt is posted — balances, credits and the GL moved.
              queryClient.invalidateQueries({ queryKey: ['unapplied-payments'] });
              queryClient.invalidateQueries({ queryKey: ['payment-eligible-invoices'] });
              queryClient.invalidateQueries({ queryKey: ['payments'] });
              queryClient.invalidateQueries({ queryKey: ['customer'] });
              onSuccess?.();
            }}
          />
        )}
        {isOpen && !customerMode && (
          <RecordPaymentForm
            key={isEdit ? `edit-${payment.id}` : String(invoiceId ?? 'new')}
            unpaidInvoices={unpaidInvoices}
            initialInvoiceId={invoiceId != null ? String(invoiceId) : undefined}
            payment={payment}
            onSuccess={(result) => {
              onOpenChange(false);
              refresh();
              if (result?.posted) {
                // #508: saved and posted — the ledger and balances moved.
                queryClient.invalidateQueries({ queryKey: ['payments'] });
                toast({ title: 'Payment posted', description: 'The payment was saved and posted to the ledger.' });
              } else if (!result?.postFailed) {
                toast(isEdit
                  ? { title: 'Draft payment updated' }
                  : { title: 'Draft payment recorded', description: 'Post it from the Payments page to update the ledger.' });
              }
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
  onSuccess: (result?: RecordPaymentResult) => void;
}> = ({ unpaidInvoices = [], initialInvoiceId, payment = null, onSuccess }) => {
  const isEdit = payment != null;
  const { user: authUser } = useAuthStore();
  // #508: Save & post (button and Ctrl/⌘+Enter) — same gate as the Post button.
  const canPostPayment = useAuthStore((state) => state.hasPermission(POST_PAYMENT_PERMISSION));
  // #508: a new payment starts on the method this user last saved with.
  const [rememberedMethod] = useState(() => (isEdit ? null : readRememberedMethod(authUser?.id)));
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
        method: rememberedMethod ?? DEFAULT_METHOD,
        reference: '',
        notes: '',
        bank_account_id: '',
      }));
  // #487: optional fields (reference, notes) sit behind "More".
  const [showMore, setShowMore] = useState(false);

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

  // #508: a remembered method the tenant no longer offers falls back to the default.
  useEffect(() => {
    if (!rememberedMethod || !methodRows) return;
    if (formData.method === rememberedMethod && !methodOptions.some((option) => option.value === rememberedMethod)) {
      setFormData((prev) => ({ ...prev, method: DEFAULT_METHOD, bank_account_id: '' }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- re-check only when the master loads
  }, [methodRows, rememberedMethod]);

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
    // customer_id is a one-shot guard, not a trigger: re-running when the user
    // clears the invoice would silently re-fill the deep-linked one.
    // eslint-disable-next-line react-hooks/exhaustive-deps
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

  // #494: payment difference — a short payment keeps the invoice open, or
  // is marked fully paid and the difference written off to the account of a
  // configured reason (within the tenant tolerance; the backend re-checks).
  const [differenceMode, setDifferenceMode] = useState<'keep_open' | 'write_off'>('keep_open');
  const [writeOffReason, setWriteOffReason] = useState('');
  const [writeOffLabel, setWriteOffLabel] = useState('');

  const recordPaymentMutation = useMutation({
    mutationFn: async ({ data, post }: { data: Record<string, unknown>; post: boolean }): Promise<RecordPaymentResult> => {
      const response = payment
        ? await api.put(`/payments/${payment.id}`, data)
        : await api.post('/payments', data);
      if (!post) return { posted: false };
      // #508: Save & post — the draft is saved first, then posted through the
      // same endpoint as the Payments page Post button (accounting.post, #480).
      const savedId = payment?.id ?? response.data?.id ?? response.data?.data?.id;
      try {
        await api.post(`/payments/${savedId}/post`);
      } catch (error) {
        throw Object.assign(new Error('post failed'), { draftSaved: true, cause: error });
      }
      return { posted: true };
    },
    onSuccess: (result) => onSuccess(result),
    onError: (error: any) => {
      if (error?.draftSaved) {
        // The draft exists — close and refresh (no duplicate on retry), and
        // say plainly that it was NOT posted.
        toast({
          variant: 'destructive',
          title: 'Saved as draft — posting failed',
          description: error.cause?.response?.data?.message || 'Post it from the Payments page.',
        });
        onSuccess({ posted: false, postFailed: true });
        return;
      }
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

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    // #508: the "Save & post" button (or Ctrl/⌘+Enter, which submits through
    // it) asks to post; never for users without accounting.post.
    const submitter = (e.nativeEvent as SubmitEvent).submitter;
    const post = canPostPayment && submitter?.getAttribute('data-intent') === 'post';
    if (authUser && formData.bank_account_id) {
      rememberDeposit(authUser.id, formData.method, Number(formData.bank_account_id));
    }
    if (authUser && !isEdit) rememberMethod(authUser.id, formData.method);
    recordPaymentMutation.mutate({
      data: buildPaymentPayload(formData, {
        // #490: an edited rate (the bank's actual one) is sent as exchange_rate;
        // otherwise the backend snapshots the payment-date rate itself.
        exchangeRate: !isEdit && editedRate != null && fxPreview?.foreign ? editedRate : null,
        writeOff: writeOffSelected ? { reason: writeOffReason, label: writeOffLabel } : null,
      }),
      post,
    });
  };

  const postButtonRef = React.useRef<HTMLButtonElement>(null);
  // #508: Ctrl/⌘+Enter = Save & post (through the button, so native field
  // validation still runs). Without accounting.post it only saves the draft.
  const handleFormKeyDown = (e: React.KeyboardEvent<HTMLFormElement>) => {
    if (e.key !== 'Enter' || !(e.ctrlKey || e.metaKey) || e.altKey) return;
    e.preventDefault();
    if (recordPaymentMutation.isPending) return;
    const form = e.currentTarget;
    if (canPostPayment && postButtonRef.current && !postButtonRef.current.disabled) {
      form.requestSubmit(postButtonRef.current);
    } else if (!canPostPayment) {
      form.requestSubmit();
    }
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

  // #494: the short-payment difference (new payments against an invoice).
  const balanceNumber = selectedBalance != null ? Number(selectedBalance) : null;
  const paymentDifference = !isEdit && balanceNumber != null && amountNumber > 0
    ? Math.round((balanceNumber - amountNumber) * 100) / 100
    : 0;
  const showDifference = paymentDifference > 0;
  const { data: writeOffSettings } = useQuery<{ write_off_tolerance: number; write_off_reasons: Array<{ code: string; label: string }> } | null>({
    queryKey: ['sales-settings', 'payment-write-off'],
    queryFn: async () => {
      const res = await api.get('/settings/sales');
      return res.data?.data?.payment ?? null;
    },
    enabled: showDifference,
    retry: false,
  });
  const writeOffTolerance = Number(writeOffSettings?.write_off_tolerance ?? 0);
  const writeOffReasons = writeOffSettings?.write_off_reasons ?? [];
  const writeOffAvailable = writeOffTolerance > 0 && writeOffReasons.length > 0 && paymentDifference <= writeOffTolerance;
  const writeOffSelected = showDifference && writeOffAvailable && differenceMode === 'write_off' && writeOffReason !== '';
  // #506 (PY4): within tolerance, a one-tap chip applies the write-off with
  // the suggested reason — the first configured reason.
  const suggestedWriteOffReason = writeOffAvailable ? writeOffReasons[0] : undefined;
  const shortPayChipApplied = suggestedWriteOffReason != null
    && differenceMode === 'write_off' && writeOffReason === suggestedWriteOffReason.code;

  // #487: Reference is optional unless the method master requires it — then
  // it is shown up front instead of behind "More".
  const referenceRequired = methodOptions.find((o) => o.value === formData.method)?.requiresReference || false;

  const submitDisabled = recordPaymentMutation.isPending
    || (showDifference && writeOffAvailable && differenceMode === 'write_off' && writeOffReason === '');

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
    <form onSubmit={handleSubmit} onKeyDown={handleFormKeyDown} className="space-y-4">
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
      {showDifference && (
        <div className="rounded-md border p-3 space-y-3" data-testid="payment-difference">
          <p className="text-sm">
            Payment difference: <span className="font-medium" data-testid="payment-difference-amount">{paymentDifference.toFixed(2)}</span>
          </p>
          {suggestedWriteOffReason && (
            <button
              type="button"
              data-testid="short-pay-chip"
              aria-pressed={shortPayChipApplied}
              onClick={() => {
                setDifferenceMode('write_off');
                setWriteOffReason(suggestedWriteOffReason.code);
              }}
              className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                shortPayChipApplied
                  ? 'border-primary bg-primary text-primary-foreground'
                  : 'border-input bg-background hover:bg-accent'
              }`}
            >
              {shortPayChipApplied ? 'Written off: ' : 'Write off '}
              {paymentDifference.toFixed(2)} as {suggestedWriteOffReason.label}
            </button>
          )}
          <div className="flex flex-col gap-2 text-sm" role="radiogroup" aria-label="Payment difference">
            <label className="flex items-center gap-2">
              <input
                type="radio"
                name="payment_difference"
                value="keep_open"
                checked={differenceMode === 'keep_open' || !writeOffAvailable}
                onChange={() => setDifferenceMode('keep_open')}
              />
              Keep open — the invoice stays partially paid
            </label>
            <label className="flex items-center gap-2">
              <input
                type="radio"
                name="payment_difference"
                value="write_off"
                disabled={!writeOffAvailable}
                checked={writeOffAvailable && differenceMode === 'write_off'}
                onChange={() => {
                  setDifferenceMode('write_off');
                  if (!writeOffReason && writeOffReasons.length > 0) setWriteOffReason(writeOffReasons[0].code);
                }}
              />
              Mark as fully paid — write off the difference
            </label>
          </div>
          {!writeOffAvailable && (
            <p className="text-xs text-muted-foreground">
              {writeOffReasons.length === 0 || writeOffTolerance <= 0
                ? 'Write-offs need a tolerance and at least one write-off reason in Sales Settings → Payment Differences.'
                : `The difference exceeds the write-off tolerance (${writeOffTolerance.toFixed(2)}).`}
            </p>
          )}
          {writeOffAvailable && differenceMode === 'write_off' && (
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="write_off_reason">Write-off reason</Label>
                <Select value={writeOffReason || undefined} onValueChange={setWriteOffReason}>
                  <SelectTrigger id="write_off_reason"><SelectValue placeholder="Choose a reason" /></SelectTrigger>
                  <SelectContent>
                    {writeOffReasons.map((reason) => (
                      <SelectItem key={reason.code} value={reason.code}>{reason.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="write_off_label">Label (optional)</Label>
                <Input
                  id="write_off_label"
                  value={writeOffLabel}
                  maxLength={255}
                  onChange={(e) => setWriteOffLabel(e.target.value)}
                  placeholder="e.g. TT charges"
                />
              </div>
            </div>
          )}
        </div>
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

      <div className="flex items-center justify-end gap-2 pt-4">
        {/* #508: keyboard hint — the full list is in the "?" overlay. */}
        <p className="mr-auto text-xs text-muted-foreground">
          <kbd className="font-mono">Enter</kbd> save
          {canPostPayment && <> · <kbd className="font-mono">Ctrl/⌘+Enter</kbd> save &amp; post</>}
        </p>
        {/* First submit button in tree order = the default button Enter clicks:
            Enter must save a draft, never post (#508). `order-last` keeps it
            visually rightmost. */}
        <Button type="submit" className="order-last" disabled={submitDisabled}>
          {isEdit
            ? (recordPaymentMutation.isPending ? 'Saving...' : 'Save Changes')
            : (recordPaymentMutation.isPending ? 'Recording...' : 'Record Payment')}
        </Button>
        {canPostPayment && (
          <Button
            ref={postButtonRef}
            type="submit"
            variant="outline"
            data-intent="post"
            disabled={submitDisabled}
          >
            Save &amp; post
          </Button>
        )}
      </div>
    </form>
  );
};
