import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams, useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils';

const statusColors: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  posted: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
};

const formatDate = (date: string) =>
  date ? new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '-';

const complianceStatusColors: Record<string, string> = {
  accepted: 'bg-green-100 text-green-800',
  ack: 'bg-blue-100 text-blue-800',
  submitted: 'bg-blue-100 text-blue-800',
  pending: 'bg-gray-100 text-gray-800',
  failed: 'bg-red-100 text-red-800',
  rejected: 'bg-red-100 text-red-800',
  cancelled: 'bg-gray-100 text-gray-800',
};

const RETRYABLE_SUBMISSION_STATUSES = ['failed', 'rejected', 'cancelled'];

export const CustomerCreditNoteDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [countryCode, setCountryCode] = useState('MY');

  const { data: creditNote, isLoading } = useQuery({
    queryKey: ['customer-credit-note', id],
    queryFn: async () => {
      const response = await api.get(`/accounting/customers/credit-notes/${id}`);
      return response.data;
    },
    enabled: !!id,
  });

  // #516 (CN7): a posted credit note is its own statutory document
  // (MyInvois type 02) and must be submitted, so the page surfaces the
  // e-invoice status and offers Submit / Retry.
  const creditNoteId = Number(id);
  const isPosted = !!creditNote && ['posted', 'voided'].includes(creditNote.status);

  const { data: complianceData } = useQuery({
    queryKey: ['credit-note-compliance', creditNoteId],
    queryFn: async () => {
      const response = await api.get(
        `/accounting/compliance/documents?document_type=credit_note&per_page=100`
      );
      return response.data?.data ?? [];
    },
    enabled: isPosted && Number.isFinite(creditNoteId),
  });

  const complianceDocument = (complianceData ?? []).find(
    (doc: any) => Number(doc.document_id) === creditNoteId
  );

  const { data: submissionsData } = useQuery({
    queryKey: ['credit-note-compliance-submissions', complianceDocument?.id],
    queryFn: async () => {
      const response = await api.get(
        `/accounting/compliance/documents/${complianceDocument.id}/submissions`
      );
      return response.data?.data ?? [];
    },
    enabled: !!complianceDocument?.id,
  });

  const latestSubmission = (submissionsData ?? [])[0] ?? null;

  const refreshCompliance = () => {
    queryClient.invalidateQueries({ queryKey: ['credit-note-compliance', creditNoteId] });
    queryClient.invalidateQueries({ queryKey: ['credit-note-compliance-submissions', complianceDocument?.id] });
  };

  const submitMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/accounting/compliance/credit-notes/${creditNoteId}/submit`, {
        country_code: countryCode,
      });
      return response.data;
    },
    onSuccess: refreshCompliance,
  });

  const retryMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/accounting/compliance/submissions/${latestSubmission.id}/retry`, {});
      return response.data;
    },
    onSuccess: refreshCompliance,
  });

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
        <Badge className={statusColors[creditNote.status] || 'bg-gray-100 text-gray-800'}>
          {creditNote.status?.charAt(0).toUpperCase() + creditNote.status?.slice(1)}
        </Badge>
      </div>

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

      {/* E-Invoice (compliance submission) — #516 */}
      {isPosted && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="text-sm text-muted-foreground">E-Invoice Submission</CardTitle>
            {complianceDocument && (
              <Badge className={complianceStatusColors[complianceDocument.compliance_status] || 'bg-gray-100 text-gray-800'}>
                {complianceDocument.compliance_status}
              </Badge>
            )}
          </CardHeader>
          <CardContent className="space-y-3">
            {submitMutation.isError && (
              <p className="text-sm text-red-600">
                {(submitMutation.error as any)?.response?.data?.message ?? 'Submission failed.'}
              </p>
            )}
            {retryMutation.isError && (
              <p className="text-sm text-red-600">
                {(retryMutation.error as any)?.response?.data?.message ?? 'Retry failed.'}
              </p>
            )}

            {complianceDocument ? (
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                  <p className="text-muted-foreground">Country</p>
                  <p className="font-medium">{complianceDocument.country_code}</p>
                </div>
                <div>
                  <p className="text-muted-foreground">Submission</p>
                  <p className="font-medium">{latestSubmission?.submission_status ?? '-'}</p>
                </div>
                <div>
                  <p className="text-muted-foreground">Provider Reference</p>
                  <p className="font-medium">{latestSubmission?.provider_reference ?? '-'}</p>
                </div>
                <div>
                  <p className="text-muted-foreground">Document UUID</p>
                  <p className="font-medium break-all">{latestSubmission?.external_document_uuid ?? '-'}</p>
                </div>
                {complianceDocument.compliance_notes && (
                  <div className="col-span-2 md:col-span-4">
                    <p className="text-muted-foreground">Notes</p>
                    <p className="font-medium">{complianceDocument.compliance_notes}</p>
                  </div>
                )}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">
                This credit note has not been submitted to an e-invoicing authority yet.
              </p>
            )}

            <div className="flex items-end gap-2">
              <div className="flex flex-col gap-1">
                <label className="text-xs text-muted-foreground" htmlFor="credit-note-country">
                  Country
                </label>
                <select
                  id="credit-note-country"
                  className="border rounded-md px-2 py-1 text-sm bg-background"
                  value={countryCode}
                  onChange={(event) => setCountryCode(event.target.value)}
                >
                  <option value="MY">MY — MyInvois</option>
                  <option value="SG">SG — InvoiceNow</option>
                </select>
              </div>

              <Button
                onClick={() => submitMutation.mutate()}
                disabled={submitMutation.isPending}
                variant="outline"
              >
                {submitMutation.isPending ? 'Submitting...' : complianceDocument ? 'Resubmit' : 'Submit'}
              </Button>

              {latestSubmission && RETRYABLE_SUBMISSION_STATUSES.includes(latestSubmission.submission_status) && (
                <Button
                  onClick={() => retryMutation.mutate()}
                  disabled={retryMutation.isPending}
                  variant="outline"
                >
                  {retryMutation.isPending ? 'Retrying...' : 'Retry'}
                </Button>
              )}
            </div>
          </CardContent>
        </Card>
      )}

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
    </div>
  );
};

export default CustomerCreditNoteDetail;
