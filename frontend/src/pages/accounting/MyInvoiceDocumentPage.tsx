import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import QRCode from 'react-qr-code'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import SignatureCanvas from 'react-signature-canvas'
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'

type HandwrittenSignatureMetadata = {
  data_url?: string
  signer_name?: string
  captured_at?: string
}

type SignatureMetadata = {
  enabled?: boolean
  mode?: string
  signature_method?: string
  handwritten_signature?: HandwrittenSignatureMetadata
}

type ComplianceSubmission = {
  id: number
  submission_number?: string | null
  provider_reference: string | null
  submission_status: string
  retry_count: number
  submitted_by?: number | null
  submitted_by_user?: { id: number; name: string } | null
  submitted_at?: string | null
  payload_hash?: string | null
  external_document_uuid?: string | null
}

type ComplianceDocument = {
  id: number
  document_number: string | null
  document_type: string
  compliance_status: string
  country_code: string
  total_amount?: number | null
  validation_link?: string | null
  signature_metadata?: SignatureMetadata | null
  signature_status?: string | null
  signature_signed_at?: string | null
  signature_reference_id?: string | null
  signature_certificate_subject?: string | null
  signature_certificate_issuer?: string | null
  signature_certificate_serial?: string | null
  qr_payload?: Record<string, unknown> | null
}

function exportHandwrittenSignature(signaturePad: SignatureCanvas) {
  const directDataUrl = signaturePad.toDataURL('image/png')
  if (directDataUrl?.startsWith('data:image/png;base64,') && directDataUrl.length > 'data:image/png;base64,'.length) {
    return directDataUrl
  }
  const trimmedCanvas = signaturePad.getTrimmedCanvas()
  const maxWidth = 320
  const maxHeight = 140
  const scale = Math.min(1, maxWidth / Math.max(1, trimmedCanvas.width), maxHeight / Math.max(1, trimmedCanvas.height))
  const targetCanvas = document.createElement('canvas')
  targetCanvas.width = Math.max(1, Math.round(trimmedCanvas.width * scale))
  targetCanvas.height = Math.max(1, Math.round(trimmedCanvas.height * scale))
  const context = targetCanvas.getContext('2d')
  if (!context) return trimmedCanvas.toDataURL('image/png')
  context.drawImage(trimmedCanvas, 0, 0, targetCanvas.width, targetCanvas.height)
  return targetCanvas.toDataURL('image/png')
}

function hasHandwrittenStroke(signaturePad: SignatureCanvas) {
  try {
    const strokes = signaturePad.toData()
    if (Array.isArray(strokes) && strokes.length > 0) return true
    return !signaturePad.isEmpty()
  } catch {
    return false
  }
}

export default function MyInvoiceDocumentPage() {
  const { id } = useParams<{ id: string }>()
  const documentId = Number(id)
  const navigate = useNavigate()
  const { toast } = useToast()
  const { role } = useAuthStore()
  const canSubmit = role === 'Owner' || role === 'Admin'

  const [signDialogOpen, setSignDialogOpen] = useState(false)
  const [signerName, setSignerName] = useState('')
  const [savedHandwrittenSignature, setSavedHandwrittenSignature] = useState<HandwrittenSignatureMetadata | null>(null)
  const signaturePadRef = useRef<SignatureCanvas | null>(null)

  const {
    data: documentDetailResponse,
    isLoading: isDocumentLoading,
    error: documentError,
    refetch: refetchDocument,
  } = useQuery({
    queryKey: ['myinvoice-document-detail', documentId],
    queryFn: async () => {
      const res = await api.get(`/accounting/compliance/documents/${documentId}`)
      return res.data as { data?: ComplianceDocument }
    },
    enabled: Boolean(documentId),
    retry: false,
  })

  const document = documentDetailResponse?.data ?? null

  const {
    data: submissionsResponse,
    isLoading: isSubmissionsLoading,
    refetch: refetchSubmissions,
  } = useQuery({
    queryKey: ['myinvoice-document-submissions', documentId],
    queryFn: async () => {
      const res = await api.get(`/accounting/compliance/documents/${documentId}/submissions`)
      return res.data as { data?: ComplianceSubmission[] }
    },
    enabled: Boolean(documentId),
  })

  const submissions = useMemo(() => submissionsResponse?.data ?? [], [submissionsResponse])

  useEffect(() => {
    if (!signDialogOpen) return
    const signaturePad = signaturePadRef.current
    if (!signaturePad) return
    const existingDataUrl = savedHandwrittenSignature?.data_url
    signaturePad.clear()
    if (!existingDataUrl) return
    const timer = window.setTimeout(() => {
      try { signaturePad.fromDataURL(existingDataUrl) } catch { /* keep dialog usable */ }
    }, 0)
    return () => { window.clearTimeout(timer) }
  }, [savedHandwrittenSignature, signDialogOpen])

  const openSignDialog = async () => {
    const localSig = document?.signature_metadata?.handwritten_signature ?? null
    setSignerName(localSig?.signer_name || '')
    setSavedHandwrittenSignature(localSig)
    setSignDialogOpen(true)
    try {
      const detail = await api.get(`/accounting/compliance/documents/${documentId}`)
      const freshSig = detail?.data?.data?.signature_metadata?.handwritten_signature ?? null
      setSavedHandwrittenSignature(freshSig)
      if (!localSig?.signer_name && freshSig?.signer_name) setSignerName(freshSig.signer_name)
    } catch { /* keep dialog interactive */ }
  }

  const closeSignDialog = (force = false) => {
    if (!force && signDocumentMutation.isPending) return
    setSignDialogOpen(false)
    setSignerName('')
    setSavedHandwrittenSignature(null)
  }

  const signDocumentMutation = useMutation({
    mutationFn: async (payload: { handwrittenSignatureDataUrl: string; signerName: string }) => {
      const res = await api.post(`/accounting/compliance/documents/${documentId}/sign`, {
        country_code: 'MY',
        handwritten_signature_data_url: payload.handwrittenSignatureDataUrl,
        signer_name: payload.signerName || undefined,
      })
      return res.data
    },
    onSuccess: async () => {
      closeSignDialog(true)
      toast({ title: 'Signature Saved', description: 'Handwritten e-signature and certificate signature metadata were saved.' })
      await refetchDocument()
    },
    onError: (error: any) => {
      const validationErrors = error?.response?.data?.errors
      const firstValidationError = validationErrors ? Object.values(validationErrors)[0] : null
      const firstValidationMessage = Array.isArray(firstValidationError) ? firstValidationError[0] : null
      toast({
        title: 'Signature Save Failed',
        description: firstValidationMessage || error?.response?.data?.message || 'Unable to generate signature artifacts.',
        variant: 'destructive',
      })
    },
  })

  const submitDocumentMutation = useMutation({
    mutationFn: async () => {
      const res = await api.post(`/accounting/compliance/documents/${documentId}/submit`, { country_code: 'MY' })
      return res.data
    },
    onSuccess: async () => {
      toast({ title: 'Submitted', description: 'Document submitted to MyInvoice sandbox.' })
      await refetchDocument()
      await refetchSubmissions()
    },
    onError: (error: any) => {
      toast({
        title: 'Submit Failed',
        description: error?.response?.data?.message || 'Unable to submit document.',
        variant: 'destructive',
      })
    },
  })

  const syncSubmissionMutation = useMutation({
    mutationFn: async (submissionId: number) => {
      const res = await api.post(`/accounting/compliance/submissions/${submissionId}/sync`)
      return res.data as { success?: boolean; updated?: boolean; message?: string }
    },
    onSuccess: async (result) => {
      toast({
        title: result?.updated ? 'Status Updated' : 'Sync Completed',
        description: result?.message || 'Submission status sync finished.',
      })
      await refetchSubmissions()
      await refetchDocument()
    },
    onError: (error: any) => {
      toast({
        title: 'Sync Failed',
        description: error?.response?.data?.message || 'Unable to sync submission.',
        variant: 'destructive',
      })
    },
  })

  const retrySubmissionMutation = useMutation({
    mutationFn: async (submissionId: number) => {
      const res = await api.post(`/accounting/compliance/submissions/${submissionId}/retry`)
      return res.data
    },
    onSuccess: async () => {
      toast({ title: 'Retry Triggered', description: 'Submission retry sent.' })
      await refetchSubmissions()
      await refetchDocument()
    },
    onError: (error: any) => {
      toast({
        title: 'Retry Failed',
        description: error?.response?.data?.message || 'Unable to retry submission.',
        variant: 'destructive',
      })
    },
  })

  if (isDocumentLoading) {
    return (
      <div className="p-6">
        <Card className="p-6">Loading document...</Card>
      </div>
    )
  }

  if (documentError || !document) {
    return (
      <div className="p-6 space-y-4">
        <Button variant="outline" onClick={() => navigate('/accounting/reporting/myinvoice')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Documents
        </Button>
        <Card className="p-6 text-sm text-amber-700 border-amber-200 bg-amber-50">
          Document could not be loaded.
        </Card>
      </div>
    )
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div className="flex items-center gap-3">
          <Button variant="outline" size="sm" onClick={() => navigate('/accounting/reporting/myinvoice')}>
            <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-2xl font-bold">Document #{document.id}</h1>
            <p className="text-sm text-muted-foreground">{document.document_number || 'No document number'}</p>
          </div>
        </div>
        <div className="flex gap-2 flex-wrap items-center">
          <Badge variant="secondary">{document.compliance_status}</Badge>
          {document.signature_metadata?.enabled && (
            <Badge>{document.signature_metadata.signature_method || 'RSA-SHA256'}</Badge>
          )}
          <Button variant="outline" size="sm" onClick={() => { refetchDocument(); refetchSubmissions() }}>
            <Icon icon="solar:refresh-linear" className="w-4 h-4 mr-2" />
            Refresh
          </Button>
          {canSubmit && (
            <Button variant="outline" size="sm" onClick={openSignDialog} disabled={signDocumentMutation.isPending}>
              E-Sign
            </Button>
          )}
          {canSubmit && (
            <Button size="sm" onClick={() => submitDocumentMutation.mutate()} disabled={submitDocumentMutation.isPending}>
              {submitDocumentMutation.isPending ? 'Submitting...' : 'Submit MY'}
            </Button>
          )}
        </div>
      </div>

      <Card className="p-6 space-y-4">
        <h2 className="text-lg font-semibold">Document Details</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Validation Link</div>
            <div className="mt-2 break-all">
              {document.validation_link ? (
                <a href={document.validation_link} target="_blank" rel="noreferrer" className="text-primary underline">
                  {document.validation_link}
                </a>
              ) : '-'}
            </div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Signature State</div>
            <div className="mt-2 font-medium">
              {document.signature_status || (document.signature_metadata?.enabled ? 'signed' : 'unsigned')}
            </div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground mb-2">Verification QR Code</div>
            {(() => {
              const url = typeof document.qr_payload === 'string' ? document.qr_payload : document.validation_link
              return url
                ? <QRCode value={url} size={128} />
                : <span className="text-xs text-muted-foreground">Available after MyInvois acceptance</span>
            })()}
          </div>
        </div>

        {document.signature_metadata?.handwritten_signature?.data_url && (
          <div className="rounded-lg border p-4">
            <div className="flex items-center justify-between gap-4 flex-wrap">
              <div>
                <div className="text-sm text-muted-foreground">Handwritten E-Signature</div>
                <div className="mt-1 text-sm font-medium">
                  {document.signature_metadata.handwritten_signature.signer_name || 'Unnamed signer'}
                </div>
                <div className="text-xs text-muted-foreground mt-1">
                  {document.signature_metadata.handwritten_signature.captured_at || 'Capture time unavailable'}
                </div>
              </div>
            </div>
            <div className="mt-4 rounded-md border bg-white p-3">
              <img
                src={document.signature_metadata.handwritten_signature.data_url}
                alt="Handwritten signature"
                className="max-h-40 w-full object-contain"
              />
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Signed At</div>
            <div className="mt-2 break-all">{document.signature_signed_at || '-'}</div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Signature ID</div>
            <div className="mt-2 break-all">{document.signature_reference_id || '-'}</div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Certificate Subject</div>
            <div className="mt-2 break-all">{document.signature_certificate_subject || '-'}</div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Certificate Issuer</div>
            <div className="mt-2 break-all">{document.signature_certificate_issuer || '-'}</div>
          </div>
          <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">Certificate Serial</div>
            <div className="mt-2 break-all">{document.signature_certificate_serial || '-'}</div>
          </div>
        </div>
      </Card>

      <Card className="p-6">
        <h2 className="text-lg font-semibold mb-4">Submission History</h2>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Ref</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Submitted By</TableHead>
              <TableHead>Submitted At</TableHead>
              <TableHead>Payload Hash</TableHead>
              <TableHead>Retries</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isSubmissionsLoading ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center text-muted-foreground">Loading submissions...</TableCell>
              </TableRow>
            ) : submissions.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center text-muted-foreground">No submissions for this document.</TableCell>
              </TableRow>
            ) : (
              submissions.map((submission) => (
                <TableRow key={submission.id}>
                  <TableCell className="font-mono text-sm font-semibold whitespace-nowrap">
                    {submission.submission_number ?? `#${submission.id}`}
                  </TableCell>
                  <TableCell>
                    <Badge variant={
                      submission.submission_status === 'rejection_requested' ? 'destructive' :
                      submission.submission_status === 'rejection_declined_pending_expiry' ? 'outline' :
                      'secondary'
                    }>
                      {submission.submission_status === 'rejection_requested'
                        ? '⚠ Buyer Rejection Pending'
                        : submission.submission_status === 'rejection_declined_pending_expiry'
                        ? '⏳ Rejection Declined'
                        : submission.submission_status}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                    {submission.submitted_by_user?.name ?? (submission.submitted_by ? `User #${submission.submitted_by}` : '-')}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                    {submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : '-'}
                  </TableCell>
                  <TableCell className="font-mono text-xs text-muted-foreground">
                    {submission.payload_hash ? submission.payload_hash.slice(0, 12) + '…' : '-'}
                  </TableCell>
                  <TableCell>{submission.retry_count}</TableCell>
                  <TableCell className="text-right">
                    <div className="flex gap-2 justify-end flex-wrap">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => navigate(`/accounting/reporting/myinvoice/${documentId}/${submission.id}`)}
                      >
                        Details
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => syncSubmissionMutation.mutate(submission.id)}
                        disabled={syncSubmissionMutation.isPending}
                      >
                        Sync
                      </Button>
                      {canSubmit && (
                        <Button
                          size="sm"
                          onClick={() => retrySubmissionMutation.mutate(submission.id)}
                          disabled={retrySubmissionMutation.isPending}
                        >
                          Retry
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={signDialogOpen} onOpenChange={(open) => (!open ? closeSignDialog() : undefined)}>
        <DialogContent className="sm:max-w-lg flex flex-col max-h-[90vh]">
          <DialogHeader>
            <DialogTitle>E-Sign Invoice</DialogTitle>
            <DialogDescription>
              Review the invoice below and save to generate the MyInvois e-signature from the configured organisation certificate.
            </DialogDescription>
          </DialogHeader>

          <div className="overflow-y-auto flex-1 min-h-0 pr-1">
          <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="rounded-lg border p-4">
                <div className="text-sm text-muted-foreground">Invoice Number</div>
                <div className="mt-2 font-medium">{document.document_number || '-'}</div>
              </div>
              <div className="rounded-lg border p-4">
                <div className="text-sm text-muted-foreground">Compliance Status</div>
                <div className="mt-2 font-medium">{document.compliance_status}</div>
              </div>
              <div className="rounded-lg border p-4">
                <div className="text-sm text-muted-foreground">Country</div>
                <div className="mt-2 font-medium">{document.country_code}</div>
              </div>
              <div className="rounded-lg border p-4">
                <div className="text-sm text-muted-foreground">Total Amount</div>
                <div className="mt-2 font-medium">{document.total_amount ?? '-'}</div>
              </div>
            </div>

            <div className="space-y-3 rounded-lg border p-4">
              <div className="flex items-center justify-between gap-3 flex-wrap">
                <div>
                  <Label htmlFor="doc-signer-name">Signer Name</Label>
                  <p className="text-xs text-muted-foreground mt-1">Optional display name stored with the handwritten capture.</p>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => signaturePadRef.current?.clear()}
                  disabled={signDocumentMutation.isPending}
                >
                  Clear Pad
                </Button>
              </div>
              <Input
                id="doc-signer-name"
                value={signerName}
                onChange={(event) => setSignerName(event.target.value)}
                placeholder="Enter signer name"
                disabled={signDocumentMutation.isPending}
              />
              {savedHandwrittenSignature?.data_url && (
                <div className="rounded-lg border bg-muted/20 p-3 space-y-2">
                  <div className="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                      <div className="text-sm font-medium">Saved Signature</div>
                      <div className="text-xs text-muted-foreground">
                        {savedHandwrittenSignature.signer_name || 'Unnamed signer'}
                        {savedHandwrittenSignature.captured_at ? ` • ${savedHandwrittenSignature.captured_at}` : ''}
                      </div>
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        const pad = signaturePadRef.current
                        if (!pad) return
                        pad.clear()
                        try { pad.fromDataURL(savedHandwrittenSignature.data_url as string) } catch { /* keep usable */ }
                      }}
                      disabled={signDocumentMutation.isPending}
                    >
                      Use Saved Signature
                    </Button>
                  </div>
                  <div className="rounded-md border bg-white p-2">
                    <img
                      src={savedHandwrittenSignature.data_url}
                      alt="Saved handwritten signature"
                      className="max-h-24 w-full object-contain"
                    />
                  </div>
                </div>
              )}
              <div className="rounded-lg border bg-white p-2">
                <SignatureCanvas
                  key={signDialogOpen ? 'open' : 'closed'}
                  ref={signaturePadRef}
                  penColor="#111827"
                  canvasProps={{
                    width: 520,
                    height: 220,
                    className: 'h-56 w-full rounded-md touch-none',
                  }}
                />
              </div>
              <div className="text-sm text-muted-foreground">
                Draw the handwritten e-signature here. The image is stored as operator acknowledgement, while the MyInvois cryptographic signature is still produced by the backend certificate.
              </div>
            </div>
          </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => closeSignDialog()} disabled={signDocumentMutation.isPending}>
              Cancel
            </Button>
            <Button
              onClick={() => {
                const signaturePad = signaturePadRef.current
                if (!signaturePad) {
                  toast({ title: 'Signature Save Failed', description: 'Signature pad is not ready. Re-open the dialog and try again.', variant: 'destructive' })
                  return
                }
                if (!hasHandwrittenStroke(signaturePad)) {
                  toast({ title: 'Signature Required', description: 'Draw a handwritten signature in the pad before saving.', variant: 'destructive' })
                  return
                }
                let handwrittenSignatureDataUrl = ''
                try {
                  handwrittenSignatureDataUrl = exportHandwrittenSignature(signaturePad)
                } catch {
                  toast({ title: 'Signature Save Failed', description: 'Unable to read signature data from the pad. Please clear and sign again.', variant: 'destructive' })
                  return
                }
                signDocumentMutation.mutate({ handwrittenSignatureDataUrl, signerName: signerName.trim() })
              }}
              disabled={signDocumentMutation.isPending}
            >
              {signDocumentMutation.isPending ? 'Saving...' : 'Save Signature'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
