import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { AuditTab } from '@/components/audit/AuditTab';
import { StockMovesTab } from '@/components/inventory/StockMovesTab';
import { PrintSendMenu } from '@/components/documents/PrintSendMenu';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePostCreditNote, useVoidCreditNote } from '@/hooks/useCustomerCreditNotes';
import { useAuthStore } from '@/store/authStore';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import EInvoicePanel from '../components/EInvoicePanel';
import { EInvoiceStatusChip } from '../components/EInvoiceStatusChip';
import { CreditNoteOutcomeBadge } from '../components/CreditNoteOutcomeBadge';
import { CreditNoteRefunds } from '../components/CreditNoteRefunds';
import { CreditNoteApplications } from '../components/CreditNoteApplications';
import type { CreditNoteOutcome } from '@/types/creditNote';
import { formatCountdown } from '../components/einvoiceStatus';
import { useEInvoiceDocument } from '../components/useEInvoice';

const statusColors: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  posted: 'bg-green-100 text-green-800',
  voided: 'bg-red-100 text-red-800',
  cancelled: 'bg-red-100 text-red-800',
};

const errorMessage = (error: unknown, fallback: string) => {
  const data = (error as { response?: { data?: { message?: string; error?: string } } })?.response?.data;
  return data?.message ?? data?.error ?? fallback;
};

const journalEntryHref = (entryId: number | string) => `/accounting/core/journal-entries?entry_id=${entryId}`;

const formatDate = (date: string) =>
  date ? new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '-';

export const CustomerCreditNoteDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const { data: creditNote, isLoading } = useQuery({
    queryKey: ['customer-credit-note', id],
    queryFn: async () => {
      const response = await api.get(`/accounting/customers/credit-notes/${id}`);
      return response.data;
    },
    enabled: !!id,
  });

  // #670 (EINV-UX1): the header chip shares the shared e-invoice hook, which
  // react-query dedupes with the panel's own query.
  const creditNoteId = Number(id);
  const isPosted = !!creditNote && ['posted', 'voided'].includes(creditNote.status);
  const einvoice = useEInvoiceDocument('credit_note', isPosted ? creditNoteId : undefined);

  // #520: lifecycle actions. Posting, voiding and the e-invoice submission
  // lifecycle all require accounting.post on the backend (#515), so they are
  // hidden from a viewer who would only receive a 403.
  const hasPermission = useAuthStore((state) => state.hasPermission);
  const canPost = hasPermission('accounting.post');
  // #528: applying credit to other invoices is an accounting.write action (#495 route).
  const canApplyCredit = hasPermission('accounting.write');
  const postMutation = usePostCreditNote();
  const voidMutation = useVoidCreditNote();
  const [voidOpen, setVoidOpen] = useState(false);
  const [voidReason, setVoidReason] = useState('');

  const openVoid = () => {
    voidMutation.reset();
    setVoidReason('');
    setVoidOpen(true);
  };

  const confirmVoid = () => {
    const reason = voidReason.trim();
    if (!reason) return;
    voidMutation.mutate(
      { id: creditNoteId, reason },
      { onSuccess: () => setVoidOpen(false) },
    );
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!creditNote) {
    return (
      <div className="p-6 text-center text-muted-foreground">
        Credit note not found.
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate('/accounting/customers/credit-notes')}>
            <Icon icon="solar:arrow-left-bold" className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold">
              Credit Note {creditNote.credit_note_number}
            </h1>
            <p className="text-muted-foreground">
              {creditNote.customer?.name || creditNote.customer_name || 'Unknown Customer'}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {isPosted && (
            <EInvoiceStatusChip
              status={einvoice.status}
              uuid={einvoice.latestSubmission?.external_document_uuid}
              validationLink={einvoice.latestSubmission?.validation_link}
              reason={einvoice.latestSubmission?.rejection_reason ?? einvoice.latestSubmission?.error_message}
              countdown={formatCountdown(einvoice.deadline)}
            />
          )}
          <Badge className={statusColors[creditNote.status] || 'bg-gray-100 text-gray-800'}>
            {creditNote.status?.charAt(0).toUpperCase() + creditNote.status?.slice(1)}
          </Badge>
          {canPost && creditNote.status === 'draft' && (
            <Button onClick={() => postMutation.mutate(creditNoteId)} disabled={postMutation.isPending}>
              {postMutation.isPending ? 'Posting...' : 'Post'}
            </Button>
          )}
          {canPost && creditNote.status === 'posted' && (
            <Button
              variant="outline"
              className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
              onClick={openVoid}
            >
              Void
            </Button>
          )}
          {creditNote.journal_entry_id && (
            <Button variant="outline" asChild>
              <Link to={journalEntryHref(creditNote.journal_entry_id)}>View journal entry</Link>
            </Button>
          )}
          {creditNote.reversal_journal_entry_id && (
            <Button variant="outline" asChild>
              <Link to={journalEntryHref(creditNote.reversal_journal_entry_id)}>View reversal entry</Link>
            </Button>
          )}
          {/* #618: one print/send menu shared with every sales document. A
              credit note has no customer send endpoint, so Email/WhatsApp are
              hidden rather than faked. */}
          <PrintSendMenu
            documentType="credit_note"
            pdfPath={`/credit-notes/${id}/pdf`}
            filename={`credit-note-${creditNote.credit_note_number || id}.pdf`}
            title="Credit Note"
            resolutionPath={`/documents/credit_note/resolution?document_id=${id}`}
          />
        </div>
      </div>

      {postMutation.isError && (
        <p className="text-sm text-red-600">{errorMessage(postMutation.error, 'Failed to post credit note.')}</p>
      )}

      <Dialog open={voidOpen} onOpenChange={(open) => !voidMutation.isPending && setVoidOpen(open)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Void credit note {creditNote.credit_note_number}?</DialogTitle>
            <DialogDescription>
              Voiding reverses the credit note's journal entry and restores the invoice balance. This cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="credit-note-void-reason">Reason</Label>
            <Textarea
              id="credit-note-void-reason"
              value={voidReason}
              maxLength={500}
              onChange={(event) => setVoidReason(event.target.value)}
              placeholder="Why is this credit note being voided?"
            />
            {voidMutation.isError && (
              <p className="text-sm text-red-600">{errorMessage(voidMutation.error, 'Failed to void credit note.')}</p>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setVoidOpen(false)} disabled={voidMutation.isPending}>
              Keep credit note
            </Button>
            <Button
              variant="destructive"
              onClick={confirmVoid}
              disabled={!voidReason.trim() || voidMutation.isPending}
            >
              {voidMutation.isPending ? 'Voiding...' : 'Confirm void'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Tabs defaultValue="document" className="space-y-6">
        <TabsList className="h-10 w-fit rounded-full">
          <TabsTrigger value="document" className="gap-2 rounded-full px-4">
            Document
          </TabsTrigger>
          <TabsTrigger value="audit" className="gap-2 rounded-full px-4">
            <Icon icon="solar:shield-check-bold-duotone" className="h-4 w-4" />
            Audit
          </TabsTrigger>
          <TabsTrigger value="stock-moves" className="gap-2 rounded-full px-4">
            <Icon icon="solar:box-bold-duotone" className="h-4 w-4" />
            Stock moves
          </TabsTrigger>
        </TabsList>

        <TabsContent value="document" className="space-y-6 mt-6">
      {/* Details */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm text-muted-foreground">Date</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg font-semibold">{formatDate(creditNote.credit_note_date)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm text-muted-foreground">Related Invoice</CardTitle>
          </CardHeader>
          <CardContent>
            {creditNote.invoice ? (
              <button
                className="text-lg font-semibold text-blue-600 hover:underline"
                onClick={() => navigate(`/accounting/customers/bills/${creditNote.invoice.id}`)}
              >
                {creditNote.invoice.invoice_number}
              </button>
            ) : (
              <p className="text-lg font-semibold text-muted-foreground">-</p>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm text-muted-foreground">Total Amount</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg font-semibold">
              {formatCurrency(creditNote.total_amount, creditNote.currency_code)}
            </p>
          </CardContent>
        </Card>
      </div>

      {/* #525 (CN-UX4): what happened to the credit. */}
      {creditNote.outcome && (() => {
        const outcome = creditNote.outcome as CreditNoteOutcome;
        return (
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-2 text-sm text-muted-foreground">
                Credit outcome
                <CreditNoteOutcomeBadge state={outcome.state} />
              </CardTitle>
            </CardHeader>
            <CardContent>
              {outcome.state === 'voided' ? (
                <p className="text-sm text-muted-foreground">
                  Voided — the credit was released from the invoice and no customer credit remains.
                </p>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                  <div>
                    <p className="text-muted-foreground">Applied to</p>
                    {outcome.applied_to ? (
                      <p className="font-medium">
                        <Link
                          to={`/accounting/customers/bills/${outcome.applied_to.invoice_id}`}
                          className="text-blue-600 hover:underline"
                        >
                          {outcome.applied_to.invoice_number || `Invoice #${outcome.applied_to.invoice_id}`}
                        </Link>
                        {' · '}
                        {formatCurrency(outcome.applied_amount, creditNote.currency_code)}
                      </p>
                    ) : (
                      <p className="font-medium text-muted-foreground">Not applied — the invoice had no open balance</p>
                    )}
                  </div>
                  <div>
                    <p className="text-muted-foreground">Remaining customer credit</p>
                    <p className="font-medium">{formatCurrency(outcome.remaining_credit, creditNote.currency_code)}</p>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        );
      })()}

      {/* #528: apply the remaining credit to the customer's other open invoices. */}
      {creditNote.status !== 'draft' && (
        <CreditNoteApplications
          creditNoteId={creditNoteId}
          creditNoteNumber={creditNote.credit_note_number}
          customerId={creditNote.customer_id}
          sourceInvoiceId={creditNote.invoice_id}
          currencyCode={creditNote.currency_code}
          legalEntityId={creditNote.legal_entity_id}
          remainingCredit={Number((creditNote.outcome as CreditNoteOutcome | null)?.remaining_credit ?? 0)}
          applications={creditNote.applications ?? []}
          canApply={canApplyCredit}
          isPosted={creditNote.status === 'posted'}
        />
      )}

      {/* #528: refund the remaining credit to the customer. */}
      {creditNote.status !== 'draft' && (
        <CreditNoteRefunds
          creditNoteId={creditNoteId}
          creditNoteNumber={creditNote.credit_note_number}
          customerId={creditNote.customer_id}
          currencyCode={creditNote.currency_code}
          remainingCredit={Number((creditNote.outcome as CreditNoteOutcome | null)?.remaining_credit ?? 0)}
          refunds={creditNote.refunds ?? []}
          canPost={canPost}
          isPosted={creditNote.status === 'posted'}
        />
      )}

      {/* Line Items */}
      {creditNote.lines && creditNote.lines.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Line Items</CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Description</TableHead>
                  <TableHead>Account</TableHead>
                  <TableHead className="text-right">Quantity</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {creditNote.lines.map((line: any) => (
                  <TableRow key={line.id}>
                    <TableCell>{line.description || line.product?.name || '-'}</TableCell>
                    <TableCell>{line.account?.name || '-'}</TableCell>
                    <TableCell className="text-right">{line.quantity}</TableCell>
                    <TableCell className="text-right">{formatCurrency(line.unit_price, creditNote.currency_code)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(line.line_total, creditNote.currency_code)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Journal Entry */}
      {creditNote.journal_entry && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5" />
              Journal Entry
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Account</TableHead>
                  <TableHead className="text-right">Debit</TableHead>
                  <TableHead className="text-right">Credit</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {creditNote.journal_entry.lines?.map((line: any) => (
                  <TableRow key={line.id}>
                    <TableCell>{line.account?.name || '-'}</TableCell>
                    <TableCell className="text-right">{line.debit ? formatCurrency(line.debit, creditNote.currency_code) : '-'}</TableCell>
                    <TableCell className="text-right">{line.credit ? formatCurrency(line.credit, creditNote.currency_code) : '-'}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* #670: shared e-invoice panel — status, provider details, actions. */}
      {isPosted && <EInvoicePanel type="credit_note" documentId={creditNoteId} canManage={canPost} />}

      {/* Metadata */}
      <Card>
        <CardHeader>
          <CardTitle className="text-sm text-muted-foreground">Additional Info</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          {creditNote.reason && (
            <div>
              <p className="text-muted-foreground">Reason</p>
              <p className="font-medium">{creditNote.reason}</p>
            </div>
          )}
          {creditNote.created_by && (
            <div>
              <p className="text-muted-foreground">Created By</p>
              <p className="font-medium">{creditNote.created_by?.name || '-'}</p>
            </div>
          )}
          {creditNote.posted_by && (
            <div>
              <p className="text-muted-foreground">Posted By</p>
              <p className="font-medium">{creditNote.posted_by?.name || '-'}</p>
            </div>
          )}
          <div>
            <p className="text-muted-foreground">Created</p>
            <p className="font-medium">{formatDate(creditNote.created_at)}</p>
          </div>
        </CardContent>
      </Card>
        </TabsContent>

        <TabsContent value="audit" className="mt-6">
          <AuditTab endpoint={`/accounting/customers/credit-notes/${id}/audit`} permission="accounting.view" />
        </TabsContent>

        <TabsContent value="stock-moves" className="mt-6">
          <StockMovesTab endpoint={`/accounting/customers/credit-notes/${id}/stock-moves`} permission="accounting.view" />
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default CustomerCreditNoteDetail;
