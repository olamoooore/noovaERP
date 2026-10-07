import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import api from '@/lib/api';
import { formatCurrency, getApiErrorMessage } from '@/lib/utils';
import {
  APPLICABLE_INVOICE_LIFECYCLES,
  CREDIT_EPSILON,
  CREDIT_NOTES_API_BASE,
  round2,
  sameOrUnknown,
  toNumber,
} from '@/lib/creditNoteCredit';
import type { CreditNoteApplication } from '@/types/creditNote';

/** A row of GET /payments/eligible-invoices (the receipt form's open-invoice source). */
interface OpenInvoice {
  id: number;
  invoice_number: string;
  invoice_date?: string | null;
  due_date?: string | null;
  currency_code?: string | null;
  balance: number;
  lifecycle_status?: string | null;
  legal_entity_id?: number | null;
}

interface Props {
  creditNoteId: number;
  creditNoteNumber: string;
  customerId: number;
  /** The note's own invoice already holds its credit — never offered. */
  sourceInvoiceId?: number | null;
  currencyCode?: string | null;
  legalEntityId?: number | null;
  remainingCredit: number;
  applications: CreditNoteApplication[];
  /** POST .../applications is gated by accounting.write. */
  canApply: boolean;
  isPosted: boolean;
}

/**
 * #528 (SALES-ODOO): apply a credit note's remaining credit to the customer's
 * other open invoices from the credit note itself — full or partial, one or
 * several invoices — through the EXISTING #495 endpoint
 * POST /accounting/customers/credit-notes/{id}/applications
 * (PaymentAllocationService::allocateCreditNote: same customer, entity and
 * currency; capped at the remaining credit and each invoice's balance).
 */
export function CreditNoteApplications({
  creditNoteId,
  creditNoteNumber,
  customerId,
  sourceInvoiceId,
  currencyCode,
  legalEntityId,
  remainingCredit,
  applications,
  canApply,
  isPosted,
}: Props) {
  const [open, setOpen] = useState(false);
  const offerApply = canApply && isPosted && remainingCredit > CREDIT_EPSILON;

  if (!offerApply && applications.length === 0) {
    return null;
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <CardTitle className="text-sm text-muted-foreground">Applied to other invoices</CardTitle>
        {offerApply && (
          <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
            Apply to invoices
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {applications.length === 0 ? (
          <p className="text-sm text-muted-foreground">No credit applied to other invoices yet.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Invoice</TableHead>
                <TableHead>Date</TableHead>
                <TableHead className="text-right">Amount</TableHead>
                <TableHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {applications.map((application) => (
                <TableRow key={application.id}>
                  <TableCell className="font-medium">
                    <Link to={`/accounting/customers/bills/${application.invoice_id}`} className="text-blue-600 hover:underline">
                      {application.invoice?.invoice_number || `Invoice #${application.invoice_id}`}
                    </Link>
                  </TableCell>
                  <TableCell>{application.created_at ? new Date(application.created_at).toLocaleDateString() : '-'}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatCurrency(toNumber(application.applied_amount), application.currency_code || currencyCode || undefined)}
                  </TableCell>
                  <TableCell className="text-right">
                    {application.reversed_at ? <Badge variant="secondary">Reversed</Badge> : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>

      {open && (
        <ApplyToInvoicesDialog
          creditNoteId={creditNoteId}
          creditNoteNumber={creditNoteNumber}
          customerId={customerId}
          sourceInvoiceId={sourceInvoiceId}
          currencyCode={currencyCode}
          legalEntityId={legalEntityId}
          remainingCredit={remainingCredit}
          onClose={() => setOpen(false)}
        />
      )}
    </Card>
  );
}

function ApplyToInvoicesDialog({
  creditNoteId,
  creditNoteNumber,
  customerId,
  sourceInvoiceId,
  currencyCode,
  legalEntityId,
  remainingCredit,
  onClose,
}: Omit<Props, 'applications' | 'canApply' | 'isPosted'> & { onClose: () => void }) {
  const queryClient = useQueryClient();
  const [amounts, setAmounts] = useState<Record<number, string>>({});

  const { data: invoices = [], isLoading } = useQuery<OpenInvoice[]>({
    queryKey: ['payment-eligible-invoices', String(customerId)],
    queryFn: async () => {
      const response = await api.get('/payments/eligible-invoices', { params: { customer_id: customerId } });
      return (response.data?.data ?? []) as OpenInvoice[];
    },
  });

  // Only what allocateCreditNote would accept: another open posted
  // receivable of the same customer, legal entity and currency.
  const eligible = invoices.filter((invoice) => invoice.id !== sourceInvoiceId
    && APPLICABLE_INVOICE_LIFECYCLES.includes(String(invoice.lifecycle_status ?? ''))
    && toNumber(invoice.balance) > CREDIT_EPSILON
    && sameOrUnknown(invoice.currency_code, currencyCode)
    && sameOrUnknown(invoice.legal_entity_id, legalEntityId));

  const lines = eligible
    .map((invoice) => ({ invoice, amount: round2(toNumber(amounts[invoice.id])) }))
    .filter((line) => line.amount > 0);
  const total = round2(lines.reduce((sum, line) => sum + line.amount, 0));
  const overCredit = total > remainingCredit + CREDIT_EPSILON;
  const overBalance = lines.some((line) => line.amount > round2(toNumber(line.invoice.balance)) + CREDIT_EPSILON);
  const canSubmit = lines.length > 0 && !overCredit && !overBalance;

  const applyMutation = useMutation({
    mutationFn: async () => api.post(`${CREDIT_NOTES_API_BASE}/${creditNoteId}/applications`, {
      applications: lines.map((line) => ({ invoice_id: line.invoice.id, amount: line.amount })),
    }),
    onSuccess: () => {
      // Refresh the note's remaining credit + applications list, and every
      // surface showing the affected invoices / customer credit.
      queryClient.invalidateQueries({ queryKey: ['customer-credit-note'] });
      queryClient.invalidateQueries({ queryKey: ['customer-credit-notes'] });
      queryClient.invalidateQueries({ queryKey: ['outstanding-credit-notes'] });
      queryClient.invalidateQueries({ queryKey: ['payment-eligible-invoices'] });
      queryClient.invalidateQueries({ queryKey: ['invoice'] });
      queryClient.invalidateQueries({ queryKey: ['customer'] });
      onClose();
    },
  });

  // Fill an invoice with as much credit as is still unallocated.
  const fillMax = (invoice: OpenInvoice) => {
    const otherTotal = round2(lines.filter((line) => line.invoice.id !== invoice.id)
      .reduce((sum, line) => sum + line.amount, 0));
    const max = round2(Math.max(0, Math.min(toNumber(invoice.balance), remainingCredit - otherTotal)));
    setAmounts((prev) => ({ ...prev, [invoice.id]: max > 0 ? max.toFixed(2) : '' }));
  };

  const currency = currencyCode || undefined;

  return (
    <Dialog open onOpenChange={(next) => { if (!next && !applyMutation.isPending) onClose(); }}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Apply {creditNoteNumber} to invoices</DialogTitle>
          <DialogDescription>
            {formatCurrency(remainingCredit, currency)} of credit remains. Apply all or part of it to this customer&apos;s
            open invoices in the same currency and legal entity.
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading open invoices…</p>
        ) : eligible.length === 0 ? (
          <p className="text-sm text-muted-foreground">This customer has no other open invoices to apply the credit to.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Invoice</TableHead>
                <TableHead>Due</TableHead>
                <TableHead className="text-right">Balance</TableHead>
                <TableHead className="text-right">Apply</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {eligible.map((invoice) => {
                const lineAmount = round2(toNumber(amounts[invoice.id]));
                const lineOver = lineAmount > round2(toNumber(invoice.balance)) + CREDIT_EPSILON;
                return (
                  <TableRow key={invoice.id}>
                    <TableCell className="font-medium">{invoice.invoice_number}</TableCell>
                    <TableCell>{invoice.due_date ? String(invoice.due_date).slice(0, 10) : '-'}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatCurrency(toNumber(invoice.balance), invoice.currency_code || currency)}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Input
                          type="number"
                          min="0"
                          step="0.01"
                          inputMode="decimal"
                          aria-label={`Credit to apply to ${invoice.invoice_number}`}
                          aria-invalid={lineOver || undefined}
                          className="w-28 text-right tabular-nums"
                          value={amounts[invoice.id] ?? ''}
                          onChange={(event) => setAmounts((prev) => ({ ...prev, [invoice.id]: event.target.value }))}
                        />
                        <Button type="button" size="sm" variant="ghost" onClick={() => fillMax(invoice)}>
                          Max
                        </Button>
                      </div>
                      {lineOver && <p className="text-xs text-destructive">Exceeds the invoice balance</p>}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        )}

        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">To apply</span>
          <span className="font-medium tabular-nums" data-testid="credit-apply-total">{formatCurrency(total, currency)}</span>
        </div>
        {overCredit && <p className="text-sm text-destructive">Exceeds the remaining credit</p>}
        {applyMutation.isError && (
          <p className="text-sm text-destructive">
            {getApiErrorMessage(applyMutation.error, 'The credit application was rejected.')}
          </p>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={applyMutation.isPending}>Cancel</Button>
          <Button disabled={!canSubmit || applyMutation.isPending} onClick={() => applyMutation.mutate()}>
            {applyMutation.isPending ? 'Applying…' : 'Apply credit'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
