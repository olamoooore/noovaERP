import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

/**
 * #673 (EINV-UX4): the failed / invalid e-invoice queue. Rows can be
 * multi-selected and retried (POST /accounting/compliance/documents/bulk-retry,
 * one queued submission per document; Valid or already queued documents are
 * skipped by the backend). Each row shows its last error as the country pack
 * explained it — plain language with a link to the record and field to fix —
 * and the raw provider message when they differ.
 */

export type RetryQueueFixType = 'customer' | 'product' | 'legal_entity' | 'einvoice_settings' | 'invoice'

export interface RetryQueueError {
  country_code: string
  code: string | null
  issue_code: string | null
  message: string
  raw_message: string
  fix: { type: RetryQueueFixType; id: number | null; fields: string[] } | null
}

export interface RetryQueueDocument {
  id: number
  document_id: number | null
  document_number: string | null
  document_type: string
  compliance_status: string
  country_code: string
  retry_count?: number | null
  last_retried_at?: string | null
  errors?: RetryQueueError[]
}

interface BulkRetryResult {
  id: number
  outcome: 'queued' | 'skipped'
  reason?: string
}

const retryQueueQueryKey = (countryCode: string) => ['einvoice-retry-queue', countryCode]

const FIELD_LABELS: Record<string, string> = {
  tax_number: 'TIN',
  buyer_type: 'buyer type',
  company_registration_number: 'registration number',
  state: 'state',
  einvoice_classification_code: 'classification code',
  tax_id: 'TIN',
  registration_number: 'registration number',
  lines: 'lines',
}

const STATUS_LABELS: Record<string, string> = {
  failed: 'Failed',
  rejected: 'Invalid',
}

function fieldSuffix(fields: string[]): string {
  const labels = Array.from(new Set(fields.map((field) => FIELD_LABELS[field] ?? field.replace(/_/g, ' '))))
  return labels.length > 0 ? ` — ${labels.join(', ')}` : ''
}

function retryFixLink(error: RetryQueueError): { to: string; label: string } | null {
  const fix = error.fix
  if (!fix) return null
  const suffix = fieldSuffix(fix.fields)

  switch (fix.type) {
    case 'customer':
      return fix.id ? { to: `/customers/${fix.id}`, label: `Open customer${suffix}` } : null
    case 'product':
      return fix.id ? { to: `/products/${fix.id}/edit`, label: `Open product${suffix}` } : null
    case 'invoice':
      return fix.id ? { to: `/invoices/${fix.id}`, label: `Open invoice${suffix}` } : null
    case 'legal_entity':
      return { to: '/accounting/configuration/company?tab=entities', label: `Open legal entity${suffix}` }
    case 'einvoice_settings':
      return error.country_code === 'MY'
        ? { to: '/accounting/configuration/myinvois', label: 'Open MyInvois settings' }
        : null
    default:
      return null
  }
}

interface EInvoiceRetryQueueProps {
  countryCode: string
  canRetry: boolean
  documentPath?: (doc: RetryQueueDocument) => string | null
  onRetried?: () => void
}

export function EInvoiceRetryQueue({ countryCode, canRetry, documentPath, onRetried }: EInvoiceRetryQueueProps) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<Set<number>>(new Set())

  const { data, isLoading } = useQuery({
    queryKey: retryQueueQueryKey(countryCode),
    queryFn: async () => {
      const res = await api.get('/accounting/compliance/documents', {
        params: { country_code: countryCode, status: 'failed,rejected', with_errors: 1, per_page: 100 },
      })
      return (res.data?.data ?? []) as RetryQueueDocument[]
    },
  })

  const documents = useMemo(() => data ?? [], [data])
  const selectedIds = documents.filter((doc) => selected.has(doc.id)).map((doc) => doc.id)
  const allSelected = documents.length > 0 && selectedIds.length === documents.length

  const toggle = (id: number, checked: boolean) => {
    setSelected((current) => {
      const next = new Set(current)
      if (checked) next.add(id)
      else next.delete(id)
      return next
    })
  }

  const toggleAll = (checked: boolean) => {
    setSelected(checked ? new Set(documents.map((doc) => doc.id)) : new Set())
  }

  const retry = useMutation({
    mutationFn: async (ids: number[]) => {
      const res = await api.post('/accounting/compliance/documents/bulk-retry', { document_ids: ids })
      return res.data?.data as { queued: number; skipped: number; results: BulkRetryResult[] }
    },
    onSuccess: (result) => {
      const skippedReasons = (result?.results ?? [])
        .filter((row) => row.outcome === 'skipped' && row.reason)
        .map((row) => row.reason as string)
      toast({
        title: `${result?.queued ?? 0} e-invoice(s) queued for resubmission`,
        description: skippedReasons.length > 0
          ? `${result?.skipped ?? skippedReasons.length} skipped: ${Array.from(new Set(skippedReasons)).join(' ')}`
          : undefined,
      })
      setSelected(new Set())
      queryClient.invalidateQueries({ queryKey: retryQueueQueryKey(countryCode) })
      onRetried?.()
    },
    onError: (error: any) => {
      toast({
        title: 'Retry failed',
        description: error?.response?.data?.message || 'The selected e-invoices could not be queued for resubmission.',
        variant: 'destructive',
      })
    },
  })

  return (
    <Card className="p-6 space-y-4">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h2 className="text-lg font-semibold">Failed / invalid e-invoices</h2>
          <p className="text-sm text-muted-foreground">
            Fix the record named in each error, then select the documents and retry.
          </p>
        </div>
        {canRetry && (
          <Button
            size="sm"
            onClick={() => retry.mutate(selectedIds)}
            disabled={selectedIds.length === 0 || retry.isPending}
          >
            {retry.isPending ? 'Retrying...' : `Retry selected (${selectedIds.length})`}
          </Button>
        )}
      </div>

      {isLoading ? (
        <div className="text-sm text-muted-foreground">Loading failed and invalid e-invoices...</div>
      ) : documents.length === 0 ? (
        <div className="text-sm text-muted-foreground">No failed or invalid e-invoices.</div>
      ) : (
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                {canRetry && (
                  <TableHead className="w-8">
                    <Checkbox
                      aria-label="Select all failed or invalid e-invoices"
                      checked={allSelected}
                      onCheckedChange={(checked) => toggleAll(checked === true)}
                    />
                  </TableHead>
                )}
                <TableHead>Number</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Retries</TableHead>
                <TableHead>Last error</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {documents.map((doc) => {
                const path = documentPath?.(doc) ?? null
                const errors = doc.errors ?? []
                return (
                  <TableRow key={doc.id}>
                    {canRetry && (
                      <TableCell>
                        <Checkbox
                          aria-label={`Select ${doc.document_number || `document ${doc.id}`}`}
                          checked={selected.has(doc.id)}
                          onCheckedChange={(checked) => toggle(doc.id, checked === true)}
                        />
                      </TableCell>
                    )}
                    <TableCell>
                      {path ? (
                        <Link to={path} className="text-primary underline underline-offset-2 font-medium">
                          {doc.document_number || `#${doc.id}`}
                        </Link>
                      ) : (
                        doc.document_number || `#${doc.id}`
                      )}
                    </TableCell>
                    <TableCell>
                      <Badge variant={doc.compliance_status === 'rejected' ? 'destructive' : 'secondary'}>
                        {STATUS_LABELS[doc.compliance_status] ?? doc.compliance_status}
                      </Badge>
                    </TableCell>
                    <TableCell>{doc.retry_count ?? 0}</TableCell>
                    <TableCell className="max-w-xl">
                      {errors.length === 0 ? (
                        <span className="text-muted-foreground">No error recorded</span>
                      ) : (
                        <ul className="space-y-2">
                          {errors.map((error, index) => {
                            const link = retryFixLink(error)
                            return (
                              <li key={index} className="text-sm">
                                <div>{error.message}</div>
                                {link && (
                                  <Link to={link.to} className="text-primary underline underline-offset-2 text-xs">
                                    {link.label}
                                  </Link>
                                )}
                                {error.raw_message !== error.message && (
                                  <div className="text-xs text-muted-foreground">
                                    As received: {error.code ? `${error.code} — ` : ''}{error.raw_message}
                                  </div>
                                )}
                              </li>
                            )
                          })}
                        </ul>
                      )}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
      )}
    </Card>
  )
}
