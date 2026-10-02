import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import {
  buyerRejectionDeadline,
  deriveEInvoiceStatus,
  type EInvoiceStatusKey,
} from './einvoiceStatus';

export type EInvoiceDocumentType = 'invoice' | 'credit_note';

export interface ComplianceDocumentRow {
  id: number;
  document_type: string;
  document_id: number;
  country_code: string;
  compliance_status: string;
  compliance_notes?: string | null;
  latest_submission?: ComplianceSubmissionRow | null;
}

export interface ComplianceSubmissionRow {
  id: number;
  document_id: number;
  submission_status: string;
  provider_reference?: string | null;
  external_document_uuid?: string | null;
  validation_link?: string | null;
  error_message?: string | null;
  rejection_reason?: string | null;
  submitted_at?: string | null;
  acknowledged_at?: string | null;
  status_details?: Record<string, unknown> | null;
}

/**
 * Fetch the compliance status for one document, plus its latest submission.
 */
export function useEInvoiceDocument(type: EInvoiceDocumentType, documentId?: number) {
  const numericId = Number(documentId);

  const documentQuery = useQuery({
    queryKey: ['einvoice-document', type, numericId],
    queryFn: async () => {
      const response = await api.get(
        `/accounting/compliance/documents?document_type=${type}&with_latest_submission=1&per_page=100`,
      );
      const rows: ComplianceDocumentRow[] = response.data?.data ?? [];
      return rows.find((row) => Number(row.document_id) === numericId) ?? null;
    },
    enabled: Number.isFinite(numericId) && numericId > 0,
  });

  const document = documentQuery.data ?? null;

  const submissionsQuery = useQuery({
    queryKey: ['einvoice-submissions', document?.id],
    queryFn: async () => {
      const response = await api.get(`/accounting/compliance/documents/${document?.id}/submissions`);
      const rows: ComplianceSubmissionRow[] = response.data?.data ?? [];
      return Array.isArray(rows) ? rows : [];
    },
    enabled: !!document?.id,
  });

  const latestSubmission = submissionsQuery.data?.[0]
    ?? (document?.latest_submission as ComplianceSubmissionRow | undefined)
    ?? null;

  const status: EInvoiceStatusKey = deriveEInvoiceStatus(
    document?.compliance_status,
    latestSubmission?.submission_status,
  );

  return {
    document,
    latestSubmission,
    status,
    deadline: buyerRejectionDeadline(latestSubmission),
    isLoading: documentQuery.isLoading || (!!document && submissionsQuery.isLoading),
    refetch: async () => {
      await documentQuery.refetch();
      await submissionsQuery.refetch();
    },
  };
}

/**
 * Status per document id for a list page — one request for the whole page.
 */
export function useEInvoiceStatusMap(type: EInvoiceDocumentType, documentIds: number[]) {
  const ids = documentIds.filter((id) => Number.isFinite(id) && id > 0);

  const query = useQuery({
    queryKey: ['einvoice-status-map', type, ids.join(',')],
    queryFn: async () => {
      const response = await api.get(
        `/accounting/compliance/documents?document_type=${type}&with_latest_submission=1&per_page=100`,
      );
      const rows: ComplianceDocumentRow[] = response.data?.data ?? [];

      const map = new Map<number, { status: EInvoiceStatusKey; document: ComplianceDocumentRow; submission: ComplianceSubmissionRow | null }>();
      for (const row of rows) {
        map.set(Number(row.document_id), {
          status: deriveEInvoiceStatus(
            row.compliance_status,
            row.latest_submission?.submission_status ?? null,
          ),
          document: row,
          submission: (row.latest_submission as ComplianceSubmissionRow | undefined) ?? null,
        });
      }

      return map;
    },
    enabled: ids.length > 0,
  });

  return query.data ?? new Map();
}

const RETRYABLE = ['failed', 'rejected', 'cancelled'];

/**
 * Submit / retry / cancel / respond for one document, refreshing the e-invoice
 * queries on success.
 */
export function useEInvoiceActions(
  type: EInvoiceDocumentType,
  documentId: number,
  document: ComplianceDocumentRow | null,
  latestSubmission: ComplianceSubmissionRow | null,
) {
  const queryClient = useQueryClient();

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['einvoice-document', type, documentId] });
    queryClient.invalidateQueries({ queryKey: ['einvoice-submissions', document?.id] });
    queryClient.invalidateQueries({ queryKey: ['einvoice-status-map'] });
  };

  const submit = useMutation({
    mutationFn: async (countryCode: string) => {
      if (type === 'credit_note') {
        const response = await api.post(`/accounting/compliance/credit-notes/${documentId}/submit`, {
          country_code: countryCode,
        });
        return response.data;
      }

      // Invoices submit through their compliance document; the list call above
      // seeds the document stub if this is the first submission.
      if (!document?.id) {
        await api.get('/accounting/compliance/documents?document_type=invoice&country_code=MY&per_page=1');
      }
      const documentIdToSubmit = document?.id
        ?? (await api.get(`/accounting/compliance/documents?document_type=invoice&per_page=100`)).data?.data
          ?.find((row: ComplianceDocumentRow) => Number(row.document_id) === documentId)?.id;

      const response = await api.post(`/accounting/compliance/documents/${documentIdToSubmit}/submit`, {
        country_code: countryCode,
      });
      return response.data;
    },
    onSuccess: refresh,
  });

  const retry = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/accounting/compliance/submissions/${latestSubmission?.id}/retry`, {});
      return response.data;
    },
    onSuccess: refresh,
  });

  const cancel = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/accounting/compliance/submissions/${latestSubmission?.id}/cancel`, {});
      return response.data;
    },
    onSuccess: refresh,
  });

  const respondToRejection = useMutation({
    mutationFn: async (accept: boolean) => {
      const response = await api.post(`/accounting/compliance/submissions/${latestSubmission?.id}/rejection-response`, {
        accept_rejection: accept,
      });
      return response.data;
    },
    onSuccess: refresh,
  });

  const submissionStatus = (latestSubmission?.submission_status ?? '').toLowerCase();

  return {
    submit,
    retry,
    cancel,
    respondToRejection,
    canRetry: RETRYABLE.includes(submissionStatus),
    canCancel: submissionStatus === 'accepted' || submissionStatus === 'rejection_requested',
    canRespondToRejection: submissionStatus === 'rejection_requested',
  };
}
