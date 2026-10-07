import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { formatCurrency, getApiErrorMessage } from '@/lib/utils';
import { useAuthStore } from '@/store/authStore';
import type { CustomerCreditNote } from '@/types/creditNote';
import {
  CREDIT_EPSILON,
  CREDIT_NOTE_APPLY_PERMISSION,
  CREDIT_NOTE_VIEW_PERMISSION,
  CREDIT_NOTES_API_BASE,
  remainingCreditOf,
  round2,
  sameOrUnknown,
  toNumber,
} from '@/lib/creditNoteCredit';

// #495 (SALES-ODOO "Outstanding credits"): on an open invoice, list the
// customer's posted credit notes that still hold credit and apply it — full or
// partial — through POST /accounting/customers/credit-notes/{id}/applications
// (PaymentAllocationService::allocateCreditNote). Unapplied payments are listed
// by UnappliedCreditsCard (#488) next to this card.

const APPLY_PERMISSION = CREDIT_NOTE_APPLY_PERMISSION;
const VIEW_PERMISSION = CREDIT_NOTE_VIEW_PERMISSION;
const API_BASE = CREDIT_NOTES_API_BASE;
const EPSILON = CREDIT_EPSILON;
const num = toNumber;
const remainingOf = remainingCreditOf;

export const OutstandingCreditNotesCard: React.FC<{
  customerId: number;
  invoiceId: number;
  invoiceNumber?: string;
  invoiceBalance: number;
  currency?: string | null;
  /** Only credit of the invoice's own legal entity can settle it. */
  legalEntityId?: number | null;
}> = ({ customerId, invoiceId, invoiceNumber, invoiceBalance, currency, legalEntityId }) => {
  const canApply = useAuthStore((state) => state.hasPermission(APPLY_PERMISSION));
  const canView = useAuthStore((state) => state.hasPermission(VIEW_PERMISSION));
  const [applying, setApplying] = useState<CustomerCreditNote | null>(null);

  const { data: notes = [], isLoading, isError } = useQuery<CustomerCreditNote[]>({
    queryKey: ['outstanding-credit-notes', customerId],
    enabled: canView,
    queryFn: async () => {
      const response = await api.get(API_BASE, {
        params: { customer_id: customerId, status: 'posted', outcome: 'customer_credit' },
      });
      return (response.data?.data ?? []) as CustomerCreditNote[];
    },
  });

  // The invoice's own credit notes are already netted into its balance, and
  // the backend refuses other currencies / entities — don't offer them.
  const eligible = notes.filter((note) => note.invoice_id !== invoiceId
    && remainingOf(note) > EPSILON
    && sameOrUnknown(note.currency_code, currency)
    && sameOrUnknown(note.legal_entity_id, legalEntityId));

  if (!canView || isLoading || isError || eligible.length === 0) {
    return null;
  }

  const total = round2(eligible.reduce((sum, note) => sum + remainingOf(note), 0));
  const offerApply = canApply && invoiceBalance > EPSILON;

  return (
    <Card data-testid="outstanding-credit-notes" className="rounded-xl border-slate-200/80 dark:border-slate-800">
      <CardHeader className="space-y-1">
        <CardTitle className="text-lg">Outstanding credit notes</CardTitle>
        <CardDescription>
          Posted credit notes for this customer with credit not yet applied. Total{' '}
          <span data-testid="outstanding-credit-notes-total" className="font-medium tabular-nums text-foreground">
            {formatCurrency(total, currency || undefined)}
          </span>
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Credit note</TableHead>
              <TableHead className="text-right">Total</TableHead>
              <TableHead className="text-right">Remaining credit</TableHead>
              {offerApply && <TableHead className="w-0" />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {eligible.map((note) => (
              <TableRow key={note.id}>
                <TableCell className="font-medium">
                  <Link to={`/accounting/customers/credit-notes/${note.id}`} className="hover:underline">
                    {note.credit_note_number}
                  </Link>
                </TableCell>
                <TableCell className="text-right tabular-nums">
                  {formatCurrency(num(note.total_amount), note.currency_code || currency || undefined)}
                </TableCell>
                <TableCell className="text-right font-medium tabular-nums">
                  {formatCurrency(remainingOf(note), note.currency_code || currency || undefined)}
                </TableCell>
                {offerApply && (
                  <TableCell className="text-right">
                    <Button size="sm" variant="outline" onClick={() => setApplying(note)}>
                      Apply to this invoice
                    </Button>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>

      {applying && (
        <ApplyCreditNoteDialog
          note={applying}
          invoiceId={invoiceId}
          invoiceNumber={invoiceNumber}
          invoiceBalance={invoiceBalance}
          currency={applying.currency_code || currency}
          onClose={() => setApplying(null)}
        />
      )}
    </Card>
  );
};

const ApplyCreditNoteDialog: React.FC<{
  note: CustomerCreditNote;
  invoiceId: number;
  invoiceNumber?: string;
  invoiceBalance: number;
  currency?: string | null;
  onClose: () => void;
}> = ({ note, invoiceId, invoiceNumber, invoiceBalance, currency, onClose }) => {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const remaining = remainingOf(note);
  const [amount, setAmount] = useState(() => String(round2(Math.min(remaining, invoiceBalance))));

  const value = round2(num(amount));
  const overCredit = value > remaining + EPSILON;
  const overBalance = value > invoiceBalance + EPSILON;
  const canSubmit = value > 0 && !overCredit && !overBalance;
  const target = invoiceNumber || 'this invoice';

  const applyMutation = useMutation({
    mutationFn: async () => api.post(`${API_BASE}/${note.id}/applications`, {
      applications: [{ invoice_id: invoiceId, amount: value }],
    }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['outstanding-credit-notes'] });
      queryClient.invalidateQueries({ queryKey: ['invoice'] });
      queryClient.invalidateQueries({ queryKey: ['customer'] });
      queryClient.invalidateQueries({ queryKey: ['customer-credit-notes'] });
      toast({ title: 'Credit applied', description: `${formatCurrency(value, currency || undefined)} applied to ${target}.` });
      onClose();
    },
    onError: (error) => {
      toast({
        title: 'Could not apply credit',
        description: getApiErrorMessage(error, 'The credit application was rejected.'),
        variant: 'destructive',
      });
    },
  });

  return (
    <Dialog open onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Apply {note.credit_note_number}</DialogTitle>
          <DialogDescription>
            {formatCurrency(remaining, currency || undefined)} of credit remains on this credit note. Apply all or part
            of it to {target} (balance {formatCurrency(invoiceBalance, currency || undefined)}).
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-1">
          <Input
            type="number"
            min="0"
            step="0.01"
            inputMode="decimal"
            aria-label={`Credit to apply to ${target}`}
            aria-invalid={overCredit || overBalance || undefined}
            className="text-right tabular-nums"
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
          />
          {overCredit && <p className="text-xs text-destructive">Exceeds the remaining credit</p>}
          {!overCredit && overBalance && <p className="text-xs text-destructive">Exceeds the invoice balance</p>}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button disabled={!canSubmit || applyMutation.isPending} onClick={() => applyMutation.mutate()}>
            Apply credit
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
