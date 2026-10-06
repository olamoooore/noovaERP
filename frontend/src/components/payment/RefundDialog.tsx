import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatCurrency, getApiErrorMessage } from '@/lib/utils';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Loader2, AlertCircle, CheckCircle2 } from 'lucide-react';

interface RefundDialogProps {
  transaction: {
    transaction_id: string;
    amount: number;
    refunded_amount?: number;
    currency: string;
  };
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
}

export function RefundDialog({
  transaction,
  open,
  onOpenChange,
  onSuccess,
}: RefundDialogProps) {
  const [refundAmount, setRefundAmount] = useState('');
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const availableForRefund =
    parseFloat(transaction.amount as any) - parseFloat((transaction.refunded_amount as any) || 0);

  const refundMutation = useMutation({
    mutationFn: async (data: { amount: number; reason: string }) => {
      const response = await api.post(
        `/payment-transactions/${transaction.transaction_id}/refund`,
        data
      );
      return response.data;
    },
    onSuccess: () => {
      setSuccess(true);
      setError(null);
      setTimeout(() => {
        onSuccess?.();
        handleClose();
      }, 2000);
    },
    onError: (err: any) => {
      setError(getApiErrorMessage(err, 'Failed to process refund. Please try again.'));
      setSuccess(false);
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    const amount = parseFloat(refundAmount);

    // Validation
    if (!amount || amount <= 0) {
      setError('Please enter a valid refund amount');
      return;
    }

    if (amount > availableForRefund) {
      setError(`Refund amount cannot exceed ${formatCurrency(availableForRefund, transaction.currency || undefined)}`);
      return;
    }

    if (!reason.trim()) {
      setError('Please provide a reason for the refund');
      return;
    }

    if (reason.length > 500) {
      setError('Reason must be less than 500 characters');
      return;
    }

    refundMutation.mutate({ amount, reason });
  };

  const handleClose = () => {
    setRefundAmount('');
    setReason('');
    setError(null);
    setSuccess(false);
    onOpenChange(false);
  };

  const isPartialRefund = refundAmount && parseFloat(refundAmount) < availableForRefund;

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-[500px]">
        <DialogHeader>
          <DialogTitle>Process Refund</DialogTitle>
          <DialogDescription>
            Issue a {isPartialRefund ? 'partial' : 'full'} refund for this payment transaction.
          </DialogDescription>
        </DialogHeader>

        {success ? (
          <div className="py-8">
            <Alert className="border-green-200 bg-green-50">
              <CheckCircle2 className="h-4 w-4 text-green-600" />
              <AlertDescription className="text-green-800">
                Refund processed successfully! The customer will receive their refund shortly.
              </AlertDescription>
            </Alert>
          </div>
        ) : (
          <form onSubmit={handleSubmit}>
            <div className="space-y-4 py-4">
              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-gray-600">Original Amount:</span>
                  <span className="font-medium">
                    {parseFloat(transaction.amount as any).toFixed(2)} {transaction.currency}
                  </span>
                </div>
                {transaction.refunded_amount !== undefined && transaction.refunded_amount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600">Already Refunded:</span>
                    <span className="font-medium text-orange-600">
                      {parseFloat(transaction.refunded_amount as any).toFixed(2)} {transaction.currency}
                    </span>
                  </div>
                )}
                <div className="flex justify-between text-sm border-t pt-2">
                  <span className="text-gray-600">Available for Refund:</span>
                  <span className="font-semibold">
                    {availableForRefund.toFixed(2)} {transaction.currency}
                  </span>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="refund-amount">Refund Amount *</Label>
                <div className="relative">
                  <Input
                    id="refund-amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    max={availableForRefund}
                    placeholder="0.00"
                    value={refundAmount}
                    onChange={(e) => setRefundAmount(e.target.value)}
                    disabled={refundMutation.isPending}
                    className="pr-16"
                  />
                  <div className="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">
                    {transaction.currency}
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setRefundAmount(availableForRefund.toString())}
                  className="text-xs text-blue-600 hover:text-blue-700"
                  disabled={refundMutation.isPending}
                >
                  Refund full amount
                </button>
              </div>

              <div className="space-y-2">
                <Label htmlFor="reason">Reason for Refund *</Label>
                <Textarea
                  id="reason"
                  placeholder="Explain why this refund is being processed..."
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  disabled={refundMutation.isPending}
                  rows={4}
                  maxLength={500}
                />
                <div className="text-xs text-gray-500 text-right">
                  {reason.length}/500 characters
                </div>
              </div>

              {error && (
                <Alert variant="destructive">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={handleClose}
                disabled={refundMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={refundMutation.isPending || !refundAmount || !reason}
                className="bg-red-600 hover:bg-red-700"
              >
                {refundMutation.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                Process Refund
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
