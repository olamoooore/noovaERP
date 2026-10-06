import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import { EInvoiceStatusChip } from './EInvoiceStatusChip';
import { formatCountdown } from './einvoiceStatus';
import { useEInvoiceActions, useEInvoiceDocument, type EInvoiceDocumentType } from './useEInvoice';

interface EInvoicePanelProps {
  type: EInvoiceDocumentType;
  documentId: number;
  /**
   * #520: whether the viewer may run the submission lifecycle actions. The
   * compliance submit/retry/cancel routes require accounting.post, so a
   * read-only viewer sees the status without buttons that would only 403.
   */
  canManage?: boolean;
}

/**
 * #670 (EINV-UX1): the document-level e-invoice panel — status chip, provider
 * details and the lifecycle actions (Submit, Retry, Cancel, respond to a
 * buyer rejection request) on the invoice and credit note detail pages.
 */
export default function EInvoicePanel({ type, documentId, canManage = true }: EInvoicePanelProps) {
  const [countryCode, setCountryCode] = useState('MY');
  const { document, latestSubmission, status, deadline, isLoading } = useEInvoiceDocument(type, documentId);
  const actions = useEInvoiceActions(type, documentId, document, latestSubmission);

  const countdown = formatCountdown(deadline);
  const errorMessage = (error: unknown) =>
    (error as { response?: { data?: { message?: string; error?: string } } })?.response?.data?.message
    ?? (error as { response?: { data?: { error?: string } } })?.response?.data?.error
    ?? 'The request failed.';

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-sm text-muted-foreground">E-Invoice Submission</CardTitle>
        <EInvoiceStatusChip
          status={status}
          uuid={latestSubmission?.external_document_uuid}
          validationLink={latestSubmission?.validation_link}
          reason={latestSubmission?.rejection_reason ?? latestSubmission?.error_message}
          countdown={countdown}
        />
      </CardHeader>
      <CardContent className="space-y-3">
        {isLoading && <p className="text-sm text-muted-foreground">Loading e-invoice status...</p>}

        {actions.submit.isError && <p className="text-sm text-red-600">{errorMessage(actions.submit.error)}</p>}
        {actions.retry.isError && <p className="text-sm text-red-600">{errorMessage(actions.retry.error)}</p>}
        {actions.cancel.isError && <p className="text-sm text-red-600">{errorMessage(actions.cancel.error)}</p>}
        {actions.respondToRejection.isError && (
          <p className="text-sm text-red-600">{errorMessage(actions.respondToRejection.error)}</p>
        )}

        {document ? (
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
              <p className="text-muted-foreground">Country</p>
              <p className="font-medium">{document.country_code}</p>
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
            {(latestSubmission?.rejection_reason || document.compliance_notes) && (
              <div className="col-span-2 md:col-span-4">
                <p className="text-muted-foreground">Notes</p>
                <p className="font-medium">{latestSubmission?.rejection_reason ?? document.compliance_notes}</p>
              </div>
            )}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">
            This document has not been submitted to an e-invoicing authority yet.
          </p>
        )}

        {canManage && (
        <div className="flex flex-wrap items-end gap-2">
          <div className="flex flex-col gap-1">
            <label className="text-xs text-muted-foreground" htmlFor={`einvoice-country-${type}-${documentId}`}>
              Country
            </label>
            <select
              id={`einvoice-country-${type}-${documentId}`}
              className="border rounded-md px-2 py-1 text-sm bg-background"
              value={countryCode}
              onChange={(event) => setCountryCode(event.target.value)}
            >
              <option value="MY">MY — MyInvois</option>
              <option value="SG">SG — InvoiceNow</option>
            </select>
          </div>

          <Button
            variant="outline"
            onClick={() => actions.submit.mutate(countryCode)}
            disabled={actions.submit.isPending}
          >
            {actions.submit.isPending ? 'Submitting...' : document ? 'Resubmit' : 'Submit'}
          </Button>

          {actions.canRetry && (
            <Button variant="outline" onClick={() => actions.retry.mutate()} disabled={actions.retry.isPending}>
              {actions.retry.isPending ? 'Retrying...' : 'Retry'}
            </Button>
          )}

          {actions.canCancel && (
            <Button
              variant="outline"
              className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
              onClick={() => actions.cancel.mutate()}
              disabled={actions.cancel.isPending}
            >
              <Icon icon="solar:close-circle-bold-duotone" className="mr-1.5 h-4 w-4" />
              {actions.cancel.isPending ? 'Cancelling...' : countdown ? `Cancel (${countdown})` : 'Cancel'}
            </Button>
          )}

          {actions.canRespondToRejection && (
            <>
              <Button
                variant="outline"
                onClick={() => actions.respondToRejection.mutate(true)}
                disabled={actions.respondToRejection.isPending}
              >
                Accept rejection
              </Button>
              <Button
                variant="outline"
                onClick={() => actions.respondToRejection.mutate(false)}
                disabled={actions.respondToRejection.isPending}
              >
                Decline rejection
              </Button>
            </>
          )}
        </div>
        )}
      </CardContent>
    </Card>
  );
}
