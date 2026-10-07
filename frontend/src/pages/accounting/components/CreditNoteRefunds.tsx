import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import type { DepositAccountOption } from '@/lib/paymentDepositAccount';
import type { CreditNoteRefund } from '@/types/creditNote';

const errorMessage = (error: unknown, fallback: string) => {
  const data = (error as { response?: { data?: { message?: string; error?: string } } })?.response?.data;
  return data?.message ?? data?.error ?? fallback;
};

interface Props {
  creditNoteId: number;
  creditNoteNumber: string;
  currencyCode?: string | null;
  remainingCredit: number;
  refunds: CreditNoteRefund[];
  /** Refunding and voiding post to the GL (accounting.post on the backend). */
  canPost: boolean;
  isPosted: boolean;
}

/**
 * #528 (SALES-ODOO): pay a credit note's remaining credit out to the
 * customer ("Refund") and list / void the refunds. The backend posts each
 * refund as an outbound payment (Dr AR / Cr Bank) and caps it at the
 * remaining credit.
 */
export function CreditNoteRefunds({
  creditNoteId,
  creditNoteNumber,
  currencyCode,
  remainingCredit,
  refunds,
  canPost,
  isPosted,
}: Props) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState('');
  const [bankAccountId, setBankAccountId] = useState('');
  const [refundDate, setRefundDate] = useState(() => new Date().toISOString().slice(0, 10));

  const canRefund = canPost && isPosted && remainingCredit > 0;

  const { data: bankAccounts } = useQuery<DepositAccountOption[]>({
    queryKey: ['bank-accounts'],
    queryFn: async () => (await api.get('/accounting/bank-accounts')).data.data as DepositAccountOption[],
    enabled: open,
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['customer-credit-note'] });
    queryClient.invalidateQueries({ queryKey: ['customer-credit-notes'] });
  };

  const refundMutation = useMutation({
    mutationFn: async () =>
      (
        await api.post(`/accounting/customers/credit-notes/${creditNoteId}/refunds`, {
          amount: Number(amount),
          bank_account_id: bankAccountId ? Number(bankAccountId) : null,
          method: 'bank_transfer',
          refund_date: refundDate,
        })
      ).data,
    onSuccess: () => {
      invalidate();
      setOpen(false);
    },
  });

  const voidMutation = useMutation({
    mutationFn: async (refundId: number) =>
      (await api.post(`/accounting/customers/credit-notes/${creditNoteId}/refunds/${refundId}/void`)).data,
    onSuccess: invalidate,
  });

  const openDialog = () => {
    refundMutation.reset();
    setAmount(remainingCredit.toFixed(2));
    setOpen(true);
  };

  const numericAmount = Number(amount);
  const amountInvalid = !(numericAmount > 0) || numericAmount > remainingCredit + 0.005;

  if (!canRefund && refunds.length === 0) {
    return null;
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <CardTitle className="text-sm text-muted-foreground">Refunds</CardTitle>
        {canRefund && (
          <Button size="sm" variant="outline" onClick={openDialog}>
            Refund
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {refunds.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No refunds yet. Refund pays the remaining customer credit back to the customer.
          </p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Payment</TableHead>
                <TableHead>Date</TableHead>
                <TableHead className="text-right">Amount</TableHead>
                <TableHead>Status</TableHead>
                <TableHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {refunds.map((refund) => (
                <TableRow key={refund.id}>
                  <TableCell>
                    {refund.payment?.payment_number ?? '-'}
                    {refund.journal_entry_id && (
                      <>
                        {' · '}
                        <Link
                          to={`/accounting/core/journal-entries?entry_id=${refund.journal_entry_id}`}
                          className="text-blue-600 hover:underline"
                        >
                          Journal
                        </Link>
                      </>
                    )}
                  </TableCell>
                  <TableCell>{refund.payment?.payment_date?.slice(0, 10) ?? refund.created_at?.slice(0, 10)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(Number(refund.amount), currencyCode ?? undefined)}</TableCell>
                  <TableCell>
                    {refund.voided_at ? <Badge variant="outline">Voided</Badge> : <Badge variant="outline">Refunded</Badge>}
                  </TableCell>
                  <TableCell className="text-right">
                    {canPost && !refund.voided_at && (
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={voidMutation.isPending}
                        onClick={() => voidMutation.mutate(refund.id)}
                      >
                        Void refund
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
        {voidMutation.isError && (
          <p className="mt-2 text-sm text-red-600">{errorMessage(voidMutation.error, 'Failed to void the refund.')}</p>
        )}
      </CardContent>

      <Dialog open={open} onOpenChange={(next) => !refundMutation.isPending && setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Refund credit note {creditNoteNumber}</DialogTitle>
            <DialogDescription>
              Pays the customer back from a bank account. Up to {formatCurrency(remainingCredit, currencyCode ?? undefined)} of
              customer credit remains on this credit note.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="credit-note-refund-amount">Amount</Label>
              <Input
                id="credit-note-refund-amount"
                type="number"
                min="0.01"
                step="0.01"
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
              />
              {amount !== '' && amountInvalid && (
                <p className="text-sm text-red-600">
                  Enter an amount above zero and no more than the remaining credit.
                </p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="credit-note-refund-account">Pay from</Label>
              <Select value={bankAccountId || undefined} onValueChange={setBankAccountId}>
                <SelectTrigger id="credit-note-refund-account" aria-label="Pay from account">
                  <SelectValue placeholder="Default bank mapping" />
                </SelectTrigger>
                <SelectContent>
                  {(bankAccounts ?? []).map((option) => (
                    <SelectItem key={option.id} value={String(option.id)}>
                      {option.account_name}
                      {option.bank_name ? ` (${option.bank_name})` : ''}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="credit-note-refund-date">Date</Label>
              <Input
                id="credit-note-refund-date"
                type="date"
                value={refundDate}
                onChange={(event) => setRefundDate(event.target.value)}
              />
            </div>
            {refundMutation.isError && (
              <p className="text-sm text-red-600">{errorMessage(refundMutation.error, 'Failed to refund the credit.')}</p>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={refundMutation.isPending}>
              Cancel
            </Button>
            <Button onClick={() => refundMutation.mutate()} disabled={amountInvalid || refundMutation.isPending}>
              {refundMutation.isPending ? 'Refunding...' : 'Confirm refund'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
