import React, { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Icon } from '@iconify/react';
import api from '@/lib/api';
import { formatCurrency, getApiErrorMessage } from '@/lib/utils';
import RefundNoteBanner from '@/pages/accounting/components/RefundNoteBanner';

export type PaymentCorrectionAction = 'reverse' | 'refund' | 'unapply';

interface CorrectablePayment {
  id: number;
  payment_number?: string;
  amount: number;
  currency?: string;
  customer?: { id: number; name: string };
  invoice?: { id: number; invoice_number: string };
  journal_entry_id?: number | null;
}

export interface PaymentCorrectionOutcome {
  message: string;
  reversal_payment?: { id: number; payment_number?: string };
  refund_payment?: { id: number; payment_number?: string };
  payment?: { id: number; payment_number?: string };
  reversal_journal_entry?: { id: number; entry_number?: string; entry_date?: string } | null;
}

const REASON_CODES = [
  { value: 'DATA_ENTRY_ERROR', label: 'Data entry error' },
  { value: 'DUPLICATE_PAYMENT', label: 'Duplicate payment' },
  { value: 'CUSTOMER_REQUEST', label: 'Customer request' },
  { value: 'BANK_ERROR', label: 'Bank error' },
  { value: 'OVERPAYMENT', label: 'Overpayment' },
  { value: 'FRAUD_SUSPECTED', label: 'Suspected fraud' },
  { value: 'OTHER', label: 'Other' },
];

const ACTION_COPY: Record<
  PaymentCorrectionAction,
  { title: string; icon: string; description: string; confirmLabel: string }
> = {
  reverse: {
    title: 'Reverse Payment',
    icon: 'solar:undo-left-round-bold',
    description:
      'Creates a reversing journal entry and a negative correction payment, fully cancelling this payment. The invoice balance is restored. Use this when the payment was recorded in error.',
    confirmLabel: 'Reverse Payment',
  },
  refund: {
    title: 'Refund Payment',
    icon: 'solar:refresh-circle-bold-duotone',
    description:
      'Records that the money is being returned to the customer. Creates a reversing journal entry and a refund record, and restores the invoice balance. Use this when the customer is reimbursed.',
    confirmLabel: 'Refund Payment',
  },
  unapply: {
    title: 'Unapply Payment',
    icon: 'solar:link-broken-bold-duotone',
    description:
      'Detaches this payment from its invoice and restores the invoice balance, keeping the payment on account as an unapplied receipt. The payment can be re-allocated later.',
    confirmLabel: 'Unapply Payment',
  },
};

export const PaymentCorrectionDialog: React.FC<{
  payment: CorrectablePayment;
  action: PaymentCorrectionAction;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: (outcome: PaymentCorrectionOutcome) => void;
}> = ({ payment, action, open, onOpenChange, onSuccess }) => {
  const [reasonCode, setReasonCode] = useState('');
  const [reasonText, setReasonText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const copy = ACTION_COPY[action];

  const mutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/corrections/payments/${payment.id}/${action}`, {
        reason_code: reasonCode,
        reason_text: reasonText || null,
      });
      return response.data as PaymentCorrectionOutcome;
    },
    onSuccess: (data) => {
      onOpenChange(false);
      onSuccess(data);
    },
    onError: (err: any) => {
      setError(getApiErrorMessage(err, 'The correction could not be completed.'));
    },
  });

  const handleConfirm = () => {
    if (!reasonCode) {
      setError('A reason code is required.');
      return;
    }
    setError(null);
    mutation.mutate();
  };

  const handleOpenChange = (next: boolean) => {
    if (mutation.isPending) return;
    if (!next) {
      setReasonCode('');
      setReasonText('');
      setError(null);
    }
    onOpenChange(next);
  };

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon={copy.icon} className="h-5 w-5 text-destructive" />
            {copy.title}
          </DialogTitle>
          <DialogDescription>{copy.description}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="rounded-md bg-muted/50 p-3 text-sm space-y-1">
            <div className="flex justify-between">
              <span className="text-muted-foreground">Payment</span>
              <span className="font-medium">{payment.payment_number || `PAY-${payment.id}`}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Customer</span>
              <span className="font-medium">{payment.customer?.name || '-'}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Amount</span>
              <span className="font-medium">
                {payment.currency ? `${payment.currency} ` : ''}
                {formatCurrency(payment.amount || 0)}
              </span>
            </div>
            {payment.invoice && (
              <div className="flex justify-between">
                <span className="text-muted-foreground">Invoice</span>
                <span className="font-medium">{payment.invoice.invoice_number}</span>
              </div>
            )}
          </div>

          {/* #825 (EINV-UX8): a customer refund on an MY entity is reported to
              LHDN as a refund note (type 04) — warn before it is posted. */}
          {action === 'refund' && (
            <RefundNoteBanner
              customerName={payment.customer?.name}
              invoiceNumber={payment.invoice?.invoice_number}
              amount={payment.amount}
              currency={payment.currency}
            />
          )}

          <div className="space-y-2">
            <Label htmlFor="reason-code">Reason code *</Label>
            <Select value={reasonCode} onValueChange={setReasonCode} disabled={mutation.isPending}>
              <SelectTrigger id="reason-code" aria-label="Correction reason code">
                <SelectValue placeholder="Select a reason…" />
              </SelectTrigger>
              <SelectContent>
                {REASON_CODES.map((rc) => (
                  <SelectItem key={rc.value} value={rc.value}>
                    {rc.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label htmlFor="reason-text">Details (optional)</Label>
            <Textarea
              id="reason-text"
              placeholder="Add any additional context for the audit trail…"
              value={reasonText}
              maxLength={500}
              rows={3}
              disabled={mutation.isPending}
              onChange={(e) => setReasonText(e.target.value)}
            />
            <p className="text-xs text-muted-foreground text-right">{reasonText.length}/500</p>
          </div>

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button
            variant="destructive"
            onClick={handleConfirm}
            disabled={mutation.isPending || !reasonCode}
            aria-label={copy.confirmLabel}
          >
            {mutation.isPending ? (
              <>
                <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                Processing…
              </>
            ) : (
              copy.confirmLabel
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
