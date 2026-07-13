import { useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import QRCode from 'react-qr-code'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'

type UserStub = { id: number; name: string }

type ComplianceSubmission = {
  id: number
  submission_number?: string | null
  provider_reference: string | null
  submission_status: string
  retry_count: number
  error_code?: string | null
  error_message?: string | null
  rejection_reason?: string | null
  submitted_at?: string | null
  submitted_by?: number | null
  submitted_by_user?: UserStub | null
  payload_hash?: string | null
  document_hash?: string | null
  validation_link?: string | null
  external_document_uuid?: string | null
  external_long_id?: string | null
  cancellation_reason?: string | null
  cancelled_by?: number | null
  cancelled_by_user?: UserStub | null
  cancelled_at?: string | null
  buyer_rejection_reason?: string | null
  buyer_rejection_responded_by?: number | null
  buyer_rejection_responded_by_user?: UserStub | null
  buyer_rejection_responded_at?: string | null
}

type ComplianceEvent = {
  id: number
  event_type: string
  created_at: string
  event_data?: Record<string, unknown>
  previous_status?: string | null
  actor_type?: string | null
  created_by?: number | null
  created_by_user?: UserStub | null
}

const CANCELLATION_WINDOW_HOURS = 72

const EVENT_LABELS: Record<string, string> = {
  submit: 'Submitted to LHDN',
  submit_failed: 'Submission Failed',
  retry: 'Retry Submitted',
  retry_failed: 'Retry Failed',
  accepted: 'Accepted by LHDN',
  rejected: 'Rejected by LHDN',
  cancel: 'Cancelled',
  status_sync: 'Status Synced',
  rejection_request_declined: 'Buyer Rejection Declined',
  rejection_request_accepted: 'Buyer Rejection Accepted',
}

function eventLabel(type: string) {
  return EVENT_LABELS[type] ?? type
}

function actorLabel(event: ComplianceEvent): string {
  if (event.actor_type === 'scheduler') return 'System'
  if (event.created_by_user?.name) return event.created_by_user.name
  if (event.created_by) return `User #${event.created_by}`
  return event.actor_type === 'user' ? 'User' : '-'
}

function eventSummary(event: ComplianceEvent): string {
  const d = event.event_data ?? {}
  switch (event.event_type) {
    case 'submit':
    case 'retry':
      return (d.document_number as string) ? `Doc: ${d.document_number}` : ''
    case 'submit_failed':
    case 'retry_failed':
      return (d.error_message as string) ?? ''
    case 'accepted':
      return (d.accepted_at as string) ? `At ${new Date(d.accepted_at as string).toLocaleString()}` : ''
    case 'rejected':
      return (d.reason as string) ?? (d.error_category as string) ?? ''
    case 'cancel':
      return (d.cancellation_reason as string) ?? ''
    case 'status_sync': {
      const from = d.previous_status as string | undefined
      const to = d.new_status as string | undefined
      return from && to ? `${from} → ${to}` : ''
    }
    case 'rejection_request_declined':
      return (d.reason as string) ?? ''
    default:
      return ''
  }
}

function truncateHash(hash?: string | null): string {
  if (!hash) return '-'
  return hash.slice(0, 16) + '…'
}

function CopyHash({ hash }: { hash?: string | null }) {
  const [copied, setCopied] = useState(false)
  if (!hash) return <span className="text-muted-foreground">-</span>
  const copy = () => {
    navigator.clipboard.writeText(hash)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }
  return (
    <span className="flex items-center gap-2 font-mono text-xs">
      <span className="break-all">{truncateHash(hash)}</span>
      <button
        onClick={copy}
        className="shrink-0 text-muted-foreground hover:text-foreground transition-colors"
        title={hash}
      >
        <Icon icon={copied ? 'solar:check-circle-bold' : 'solar:copy-linear'} className="w-3.5 h-3.5" />
      </button>
    </span>
  )
}

function getCancellationWindowInfo(submittedAt?: string | null) {
  if (!submittedAt) return { canCancel: false, expiresAt: null as Date | null }
  const submittedDate = new Date(submittedAt)
  if (Number.isNaN(submittedDate.getTime())) return { canCancel: false, expiresAt: null as Date | null }
  const expiresAt = new Date(submittedDate.getTime() + CANCELLATION_WINDOW_HOURS * 60 * 60 * 1000)
  return { canCancel: expiresAt.getTime() >= Date.now(), expiresAt }
}

export default function MyInvoiceSubmissionPage() {
  const { id, submissionId } = useParams<{ id: string; submissionId: string }>()
  const documentId = Number(id)
  const submissionIdNum = Number(submissionId)
  const navigate = useNavigate()
  const { toast } = useToast()
  const { role } = useAuthStore()
  const canSubmit = role === 'Owner' || role === 'Admin'

  const [cancelDialogOpen, setCancelDialogOpen] = useState(false)
  const [cancelReason, setCancelReason] = useState('')
  const [rejectionResponseDialogOpen, setRejectionResponseDialogOpen] = useState(false)
  const [rejectionResponseDecision, setRejectionResponseDecision] = useState<'cancel' | 'decline' | null>(null)
  const [rejectionResponseReason, setRejectionResponseReason] = useState('')

  const {
    data: submissionDetailResponse,
    isLoading: isSubmissionLoading,
    refetch: refetchSubmission,
  } = useQuery({
    queryKey: ['myinvoice-submission-detail', submissionIdNum],
    queryFn: async () => {
      const res = await api.get(`/accounting/compliance/submissions/${submissionIdNum}`)
      return res.data as { data?: ComplianceSubmission }
    },
    enabled: Boolean(submissionIdNum),
  })

  const submission = submissionDetailResponse?.data ?? null

  const {
    data: eventsResponse,
    isLoading: isEventsLoading,
    refetch: refetchEvents,
  } = useQuery({
    queryKey: ['myinvoice-submission-events', submissionIdNum],
    queryFn: async () => {
      const res = await api.get(`/accounting/compliance/submissions/${submissionIdNum}/events`)
      return res.data as { data?: ComplianceEvent[] }
    },
    enabled: Boolean(submissionIdNum),
  })

  const events = useMemo(() => eventsResponse?.data ?? [], [eventsResponse])

  const syncSubmissionMutation = useMutation({
    mutationFn: async () => {
      const res = await api.post(`/accounting/compliance/submissions/${submissionIdNum}/sync`)
      return res.data as { success?: boolean; updated?: boolean; message?: string }
    },
    onSuccess: async (result) => {
      toast({
        title: result?.updated ? 'Status Updated' : 'Sync Completed',
        description: result?.message || 'Submission status sync finished.',
      })
      await refetchSubmission()
      await refetchEvents()
    },
    onError: (error: any) => {
      toast({
        title: 'Sync Failed',
        description: error?.response?.data?.message || 'Unable to sync submission.',
        variant: 'destructive',
      })
    },
  })

  const cancelSubmissionMutation = useMutation({
    mutationFn: async (reason: string) => {
      const res = await api.post(`/accounting/compliance/submissions/${submissionIdNum}/cancel`, {
        reason: reason || undefined,
      })
      return res.data
    },
    onSuccess: async () => {
      setCancelDialogOpen(false)
      setCancelReason('')
      toast({ title: 'Submission Cancelled', description: 'The e-Invoice was cancelled in the compliance workflow.' })
      await refetchSubmission()
      await refetchEvents()
    },
    onError: (error: any) => {
      toast({
        title: 'Cancellation Failed',
        description: error?.response?.data?.message || 'Unable to cancel submission.',
        variant: 'destructive',
      })
    },
  })

  const respondToRejectionMutation = useMutation({
    mutationFn: async (payload: { decision: 'cancel' | 'decline'; reason: string }) => {
      const res = await api.post(`/accounting/compliance/submissions/${submissionIdNum}/rejection-response`, {
        decision: payload.decision,
        reason: payload.reason || undefined,
      })
      return res.data
    },
    onSuccess: async (_data, payload) => {
      setRejectionResponseDialogOpen(false)
      setRejectionResponseReason('')
      setRejectionResponseDecision(null)
      const label = payload.decision === 'cancel' ? 'Cancelled' : 'Declined'
      toast({
        title: `Buyer Rejection ${label}`,
        description: payload.decision === 'cancel'
          ? 'Document cancelled following buyer rejection request.'
          : 'Buyer rejection request declined. Invoice remains valid.',
      })
      await refetchSubmission()
      await refetchEvents()
    },
    onError: (error: any) => {
      toast({
        title: 'Response Failed',
        description: error?.response?.data?.message || 'Unable to respond to buyer rejection.',
        variant: 'destructive',
      })
    },
  })

  const isLifecyclePending = cancelSubmissionMutation.isPending || respondToRejectionMutation.isPending

  const submissionWindow = getCancellationWindowInfo(submission?.submitted_at)
  const canCancel = Boolean(
    submission &&
      submission.submission_status === 'accepted' &&
      submission.provider_reference &&
      submissionWindow.canCancel
  )

  const declineEvent = useMemo(
    () => events.find((e) => e.event_type === 'rejection_request_declined') ?? null,
    [events]
  )

  const openRejectionDialog = (decision: 'cancel' | 'decline') => {
    setRejectionResponseDecision(decision)
    setRejectionResponseReason('')
    setRejectionResponseDialogOpen(true)
  }

  const handleRejectionResponse = (decision: 'cancel' | 'decline') => {
    if (!rejectionResponseReason.trim()) {
      toast({ title: 'Reason Required', description: 'Please provide a reason before submitting the response.', variant: 'destructive' })
      return
    }
    respondToRejectionMutation.mutate({ decision, reason: rejectionResponseReason.trim() })
  }

  if (isSubmissionLoading) {
    return (
      <div className="p-6">
        <Card className="p-6">Loading submission...</Card>
      </div>
    )
  }

  if (!submission) {
    return (
      <div className="p-6 space-y-4">
        <Button variant="outline" onClick={() => navigate(`/accounting/reporting/myinvoice/${documentId}`)}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Document
        </Button>
        <Card className="p-6 text-sm text-amber-700 border-amber-200 bg-amber-50">
          Submission could not be loaded.
        </Card>
      </div>
    )
  }

  const submissionTitle = submission.submission_number ?? `#${submission.id}`

  return (
    <div className="p-6 space-y-6">
      {/* ── Header ─────────────────────────────────────────────────── */}
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div className="flex items-center gap-3">
          <Button variant="outline" size="sm" onClick={() => navigate(`/accounting/reporting/myinvoice/${documentId}`)}>
            <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-2xl font-bold">Submission {submissionTitle}</h1>
            <p className="text-sm text-muted-foreground">Statutory audit record and LHDN response details.</p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => { refetchSubmission(); refetchEvents() }}
            disabled={isSubmissionLoading}
          >
            <Icon icon="solar:refresh-linear" className="w-4 h-4 mr-2" />
            Refresh
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => syncSubmissionMutation.mutate()}
            disabled={syncSubmissionMutation.isPending}
          >
            {syncSubmissionMutation.isPending ? 'Syncing...' : 'Sync Status'}
          </Button>
        </div>
      </div>

      {/* ── Buyer rejection alert ───────────────────────────────────── */}
      {submission.submission_status === 'rejection_requested' && (
        <Card className="p-6">
          <div className="rounded-lg border border-destructive/50 bg-destructive/5 p-4 space-y-3">
            <div>
              <h3 className="text-base font-semibold text-destructive">Buyer Rejection Request</h3>
              <p className="text-sm text-muted-foreground mt-1">
                The buyer has requested rejection of this document. You must respond within the 72-hour window.
                
              </p>
              {submission.buyer_rejection_reason && (
                <p className="text-sm mt-2 font-medium text-destructive">
                  Buyer's reason: {submission.buyer_rejection_reason}
                </p>
              )}
            </div>
            {canSubmit && (
              <div className="flex gap-2 flex-wrap">
                <Button variant="destructive" onClick={() => openRejectionDialog('cancel')} disabled={isLifecyclePending}>
                  Accept Rejection — Cancel Document
                </Button>
                <Button variant="outline" onClick={() => openRejectionDialog('decline')} disabled={isLifecyclePending}>
                  Decline Rejection — Keep Valid
                </Button>
              </div>
            )}
          </div>
        </Card>
      )}

      {/* ── Rejection declined pending expiry ───────────────────────── */}
      {submission.submission_status === 'rejection_declined_pending_expiry' && (
        <Card className="p-6">
          <div className="rounded-lg border border-amber-400/50 bg-amber-50 p-4 space-y-2">
            <h3 className="text-base font-semibold text-amber-800">Rejection Declined — Awaiting Expiry</h3>
            <p className="text-sm text-amber-700">
              You declined the buyer's rejection request. The e-Invoice remains <strong>Valid</strong> in MyInvois.
              LHDN will automatically clear the rejection request after the 72-hour window expires.
              Use <strong>Sync Status</strong> to confirm once the window has closed.
            </p>
          </div>
        </Card>
      )}

      {/* ── Decline event summary ───────────────────────────────────── */}
      {declineEvent && (
        <Card className="p-6">
          <div className="rounded-lg border border-green-300 bg-green-50 p-4 space-y-3">
            <div className="flex items-center gap-2">
              <Icon icon="solar:shield-check-bold-duotone" className="w-5 h-5 text-green-600 shrink-0" />
              <h3 className="text-base font-semibold text-green-800">Buyer Rejection Declined</h3>
            </div>
            <p className="text-sm text-green-700">
              The supplier declined the buyer's rejection request. The e-Invoice remains <strong>Valid</strong> in MyInvois.
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div className="rounded-md border border-green-200 bg-white p-3">
                <div className="text-xs text-muted-foreground mb-1">Reason</div>
                <div className="text-sm font-medium">
                  {typeof declineEvent.event_data?.reason === 'string' ? declineEvent.event_data.reason : '-'}
                </div>
              </div>
              <div className="rounded-md border border-green-200 bg-white p-3">
                <div className="text-xs text-muted-foreground mb-1">Responded At</div>
                <div className="text-sm font-medium">
                  {typeof declineEvent.event_data?.responded_at === 'string'
                    ? new Date(declineEvent.event_data.responded_at).toLocaleString()
                    : new Date(declineEvent.created_at).toLocaleString()}
                </div>
              </div>
            </div>
          </div>
        </Card>
      )}

      {/* ── LHDN References ────────────────────────────────────────── */}
      <Card className="p-6 space-y-4">
        <h2 className="text-lg font-semibold">LHDN References</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Submission Status</div>
            <div className="mt-2">
              <Badge variant={
                submission.submission_status === 'rejection_requested' ? 'destructive' :
                submission.submission_status === 'rejection_declined_pending_expiry' ? 'outline' :
                'secondary'
              }>
                {submission.submission_status === 'rejection_requested'
                  ? '⚠ Buyer Rejection Pending'
                  : submission.submission_status === 'rejection_declined_pending_expiry'
                  ? '⏳ Rejection Declined — Awaiting Expiry'
                  : submission.submission_status}
              </Badge>
            </div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">External UUID</div>
            <div className="mt-2 break-all text-sm">{submission.external_document_uuid || '-'}</div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Long ID</div>
            <div className="mt-2 break-all text-sm">{submission.external_long_id || '-'}</div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Validation Link</div>
            <div className="mt-2 break-all">
              {submission.validation_link ? (
                <a href={submission.validation_link} target="_blank" rel="noreferrer" className="text-primary underline text-xs">
                  {submission.validation_link}
                </a>
              ) : '-'}
            </div>
            {submission.validation_link && (
              <div className="mt-3">
                <QRCode value={submission.validation_link} size={112} />
              </div>
            )}
          </div>
        </div>
      </Card>

      {/* ── Submission Audit Record ─────────────────────────────────── */}
      <Card className="p-6 space-y-4">
        <h2 className="text-lg font-semibold">Submission Audit Record</h2>
        <p className="text-sm text-muted-foreground -mt-2">
          Immutable record of what was submitted, by whom, and what LHDN returned.
        </p>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="rounded-lg border p-4">
            <div className="text-xs text-muted-foreground mb-1">Submission Reference</div>
            <div className="font-mono text-sm font-semibold">
              {submission.submission_number ?? `#${submission.id}`}
            </div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-xs text-muted-foreground mb-1">Submitted By</div>
            <div className="text-sm font-medium">
              {submission.submitted_by_user?.name ?? (submission.submitted_by ? `User #${submission.submitted_by}` : '-')}
            </div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-xs text-muted-foreground mb-1">Submitted At</div>
            <div className="text-sm font-medium">
              {submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : '-'}
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="rounded-lg border p-4 space-y-1">
            <div className="text-xs text-muted-foreground">Payload Hash (SHA-256)</div>
            <div className="text-xs text-muted-foreground">Hash of the JSON payload sent to LHDN</div>
            <CopyHash hash={submission.payload_hash} />
          </div>
          <div className="rounded-lg border p-4 space-y-1">
            <div className="text-xs text-muted-foreground">Document Hash (SHA-256)</div>
            <div className="text-xs text-muted-foreground">Hash of the signed UBL document</div>
            <CopyHash hash={submission.document_hash} />
          </div>
        </div>

        <div className="rounded-lg border p-4 space-y-1">
          <div className="text-xs text-muted-foreground">LHDN Submission UID (Provider Reference)</div>
          <div className="font-mono text-sm break-all">
            {submission.provider_reference ?? '-'}
          </div>
        </div>

        {submission.error_message && (
          <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 space-y-1">
            <div className="text-xs font-medium text-destructive">LHDN Error Response</div>
            {submission.error_code && (
              <div className="text-xs text-muted-foreground">Code: {submission.error_code}</div>
            )}
            <div className="text-sm">{submission.error_message}</div>
          </div>
        )}
      </Card>

      {/* ── Cancellation Record (shown when cancelled or has reason) ── */}
      {(submission.submission_status === 'cancelled' || submission.cancelled_at || submission.cancellation_reason) && (
        <Card className="p-6 space-y-4">
          <h2 className="text-lg font-semibold">Cancellation Record</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="rounded-lg border p-4">
              <div className="text-xs text-muted-foreground mb-1">Cancelled By</div>
              <div className="text-sm font-medium">
                {submission.cancelled_by_user?.name ?? (submission.cancelled_by ? `User #${submission.cancelled_by}` : '-')}
              </div>
            </div>
            <div className="rounded-lg border p-4">
              <div className="text-xs text-muted-foreground mb-1">Cancelled At</div>
              <div className="text-sm font-medium">
                {submission.cancelled_at ? new Date(submission.cancelled_at).toLocaleString() : '-'}
              </div>
            </div>
            <div className="rounded-lg border p-4">
              <div className="text-xs text-muted-foreground mb-1">Reason</div>
              <div className="text-sm font-medium">{submission.cancellation_reason || '-'}</div>
            </div>
          </div>
        </Card>
      )}

      {/* ── Buyer Rejection Record (when buyer submitted a rejection) ─ */}
      {submission.buyer_rejection_reason && (
        <Card className="p-6 space-y-4">
          <h2 className="text-lg font-semibold">Buyer Rejection Record</h2>
          <div className="rounded-lg border p-4 space-y-1">
            <div className="text-xs text-muted-foreground">Buyer's Stated Reason</div>
            <div className="text-sm font-medium">{submission.buyer_rejection_reason}</div>
          </div>
          {submission.buyer_rejection_responded_at && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="rounded-lg border p-4">
                <div className="text-xs text-muted-foreground mb-1">Supplier Responded By</div>
                <div className="text-sm font-medium">
                  {submission.buyer_rejection_responded_by_user?.name
                    ?? (submission.buyer_rejection_responded_by ? `User #${submission.buyer_rejection_responded_by}` : '-')}
                </div>
              </div>
              <div className="rounded-lg border p-4">
                <div className="text-xs text-muted-foreground mb-1">Responded At</div>
                <div className="text-sm font-medium">
                  {new Date(submission.buyer_rejection_responded_at).toLocaleString()}
                </div>
              </div>
            </div>
          )}
        </Card>
      )}

      {/* ── Cancellation Action ─────────────────────────────────────── */}
      {submission.submission_status !== 'cancelled' && canSubmit && (
        <Card className="p-6 space-y-3">
          <div>
            <h3 className="text-base font-semibold">Cancel Submission</h3>
            <p className="text-sm text-muted-foreground mt-1">
              Cancellation is allowed only within 72 hours from the submission time.
            </p>
          </div>
          <div>
            <Button
              variant="destructive"
              onClick={() => setCancelDialogOpen(true)}
              disabled={!canCancel || isLifecyclePending}
            >
              Cancel Submission
            </Button>
          </div>
          {!submission.provider_reference && (
            <div className="text-sm text-amber-700">Provider cancellation requires a provider reference.</div>
          )}
          {submission.submission_status !== 'accepted' && (
            <div className="text-sm text-amber-700">
              Cancellation is only available after MyInvois marks the document as accepted. Use Sync Status first.
            </div>
          )}
          {submission.submitted_at && submissionWindow.expiresAt && (
            <div className="text-sm text-muted-foreground">
              Cancellation window closes at {submissionWindow.expiresAt.toLocaleString()}.
            </div>
          )}
          {!submissionWindow.canCancel && (
            <div className="text-sm text-amber-700">
              The 72-hour cancellation window has expired for this submission.
            </div>
          )}
        </Card>
      )}

      {/* ── Events Timeline ─────────────────────────────────────────── */}
      <Card className="p-6">
        <h3 className="text-base font-semibold mb-3">Status History & Events</h3>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Timestamp</TableHead>
              <TableHead>Event</TableHead>
              <TableHead>Actor</TableHead>
              <TableHead>From Status</TableHead>
              <TableHead>Detail</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isEventsLoading ? (
              <TableRow>
                <TableCell colSpan={5} className="text-center text-muted-foreground">Loading events...</TableCell>
              </TableRow>
            ) : events.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} className="text-center text-muted-foreground">No events for this submission.</TableCell>
              </TableRow>
            ) : (
              events.map((event) => {
                const summary = eventSummary(event)
                return (
                  <TableRow key={event.id}>
                    <TableCell className="text-xs text-muted-foreground whitespace-nowrap">
                      {new Date(event.created_at).toLocaleString()}
                    </TableCell>
                    <TableCell>
                      <span className="text-sm font-medium">{eventLabel(event.event_type)}</span>
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                      {actorLabel(event)}
                    </TableCell>
                    <TableCell>
                      {event.previous_status ? (
                        <Badge variant="outline" className="text-xs font-normal">
                          {event.previous_status}
                        </Badge>
                      ) : (
                        <span className="text-muted-foreground text-xs">—</span>
                      )}
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground max-w-xs truncate">
                      {summary || '—'}
                    </TableCell>
                  </TableRow>
                )
              })
            )}
          </TableBody>
        </Table>
      </Card>

      {/* ── Rejection response dialog ───────────────────────────────── */}
      <Dialog
        open={rejectionResponseDialogOpen}
        onOpenChange={(open) => {
          if (!open && !isLifecyclePending) {
            setRejectionResponseDialogOpen(false)
            setRejectionResponseReason('')
            setRejectionResponseDecision(null)
          }
        }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Respond to Buyer Rejection Request</DialogTitle>
            <DialogDescription>
              The buyer has requested rejection of this e-Invoice. Choose how to respond.
              
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="rounded-lg border p-4 text-sm space-y-1">
              <div className="font-medium">Submission {submissionTitle}</div>
              <div className="text-muted-foreground">
                Rejection is allowed within 72 hours from validation. Document stays Valid until supplier cancels.
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="rejection-response-reason">
                Reason <span className="text-destructive">*</span>
              </Label>
              <Textarea
                id="rejection-response-reason"
                value={rejectionResponseReason}
                onChange={(e) => setRejectionResponseReason(e.target.value)}
                rows={3}
                maxLength={500}
                placeholder="Enter reason for the audit trail…"
                disabled={isLifecyclePending}
              />
            </div>
          </div>

          <DialogFooter className="flex-col sm:flex-row gap-2">
            <Button
              variant="outline"
              onClick={() => {
                setRejectionResponseDialogOpen(false)
                setRejectionResponseReason('')
                setRejectionResponseDecision(null)
              }}
              disabled={isLifecyclePending}
            >
              Close
            </Button>
            <Button
              variant="outline"
              onClick={() => handleRejectionResponse('decline')}
              disabled={isLifecyclePending || rejectionResponseDecision === 'cancel'}
            >
              {isLifecyclePending && rejectionResponseDecision === 'decline' ? 'Saving…' : 'Decline — Keep Valid'}
            </Button>
            <Button
              variant="destructive"
              onClick={() => handleRejectionResponse('cancel')}
              disabled={isLifecyclePending || rejectionResponseDecision === 'decline'}
            >
              {isLifecyclePending && rejectionResponseDecision === 'cancel' ? 'Saving…' : 'Accept — Cancel Document'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Cancel dialog ───────────────────────────────────────────── */}
      <Dialog
        open={cancelDialogOpen}
        onOpenChange={(open) => { if (!open && !isLifecyclePending) { setCancelDialogOpen(false); setCancelReason('') } }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Cancel Submission</DialogTitle>
            <DialogDescription>
              Cancel this submitted e-Invoice. This action is only available within 72 hours from the submission time.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="rounded-lg border p-4">
              <div className="text-sm text-muted-foreground">Selected Submission</div>
              <div className="mt-2 font-medium">{submissionTitle}</div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="cancel-reason">Reason</Label>
              <Textarea
                id="cancel-reason"
                value={cancelReason}
                onChange={(event) => setCancelReason(event.target.value)}
                rows={4}
                maxLength={500}
                placeholder="Optional reason recorded in the compliance audit trail"
                disabled={isLifecyclePending}
              />
              <div className="text-xs text-muted-foreground">
                Optional, up to 500 characters. This reason is stored permanently in the audit trail.
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => { setCancelDialogOpen(false); setCancelReason('') }} disabled={isLifecyclePending}>
              Close
            </Button>
            <Button variant="destructive" onClick={() => cancelSubmissionMutation.mutate(cancelReason.trim())} disabled={isLifecyclePending}>
              {isLifecyclePending ? 'Saving...' : 'Cancel Submission'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
