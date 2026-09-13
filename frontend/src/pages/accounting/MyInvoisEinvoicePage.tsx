import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
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

type ComplianceDocument = {
  id: number
  document_id: number | null
  document_number: string | null
  document_type: string
  compliance_status: string
  country_code: string
  total_amount?: number | null
  validation_link?: string | null
  signature_metadata?: SignatureMetadata | null
}

function getDocumentDetailPath(doc: ComplianceDocument): string | null {
  if (!doc.document_id) return null
  const id = doc.document_id
  switch (doc.document_type) {
    case 'invoice':
    case 'self_billed_invoice':
      return `/accounting/customers/bills/${id}`
    case 'credit_note':
    case 'self_billed_credit_note':
      return `/accounting/customers/credit-notes/${id}`
    case 'bill':
      return `/accounting/vendors/bills/${id}`
    case 'vendor_debit_note':
    case 'debit_note':
    case 'self_billed_debit_note':
      return `/accounting/vendors/debit-notes/${id}`
    case 'journal_entry':
      return `/accounting/core/journal-entries`
    default:
      return null
  }
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

export default function MyInvoisEinvoicePage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const { role } = useAuthStore()
  const canSubmit = role === 'Owner' || role === 'Admin'
  const [signDialogDocumentId, setSignDialogDocumentId] = useState<number | null>(null)
  const [signerName, setSignerName] = useState('')
  const [savedHandwrittenSignature, setSavedHandwrittenSignature] = useState<HandwrittenSignatureMetadata | null>(null)
  const signaturePadRef = useRef<SignatureCanvas | null>(null)

  const { data: tenantSettings, isLoading: isTenantLoading } = useQuery({
    queryKey: ['tenant-country-plugin-settings'],
    queryFn: async () => {
      const res = await api.get('/accounting/tenant/country-plugin-settings')
      return res.data as {
        tenant_country_code?: string | null
        country_plugin_enabled?: boolean
      }
    },
  })

  const isMyPluginEnabled =
    Boolean(tenantSettings?.country_plugin_enabled) &&
    String(tenantSettings?.tenant_country_code || '').toUpperCase() === 'MY'

  const { data: myConfig } = useQuery({
    queryKey: ['myinvois-config'],
    queryFn: async () => {
      const res = await api.get('/accounting/compliance/config/MY')
      return res.data?.data as {
        credentials_complete?: boolean
        missing_credentials?: string[]
      } | undefined
    },
    enabled: isMyPluginEnabled,
  })

  const {
    data: documentsResponse,
    refetch: refetchDocuments,
  } = useQuery({
    queryKey: ['myinvoice-compliance-documents'],
    queryFn: async () => {
      const res = await api.get('/accounting/compliance/documents', {
        params: { country_code: 'MY', per_page: 50 },
      })
      return res.data as { data?: ComplianceDocument[] }
    },
    enabled: isMyPluginEnabled,
  })

  const documents = useMemo(() => documentsResponse?.data ?? [], [documentsResponse])

  const pendingSignDocument = useMemo(
    () => documents.find((doc) => doc.id === signDialogDocumentId) ?? null,
    [documents, signDialogDocumentId]
  )

  useEffect(() => {
    if (!signDialogDocumentId) return
    const signaturePad = signaturePadRef.current
    if (!signaturePad) return
    const existingDataUrl = savedHandwrittenSignature?.data_url
    signaturePad.clear()
    if (!existingDataUrl) return
    const timer = window.setTimeout(() => {
      try { signaturePad.fromDataURL(existingDataUrl) } catch { /* keep dialog usable */ }
    }, 0)
    return () => { window.clearTimeout(timer) }
  }, [savedHandwrittenSignature, signDialogDocumentId])

  const openSignDialog = async (documentId: number) => {
    const doc = documents.find((entry) => entry.id === documentId)
    const localSig = doc?.signature_metadata?.handwritten_signature ?? null
    setSignerName(localSig?.signer_name || '')
    setSavedHandwrittenSignature(localSig)
    setSignDialogDocumentId(documentId)
    try {
      const detail = await api.get(`/accounting/compliance/documents/${documentId}`)
      const freshSig = detail?.data?.data?.signature_metadata?.handwritten_signature ?? null
      setSavedHandwrittenSignature(freshSig)
      if (!localSig?.signer_name && freshSig?.signer_name) setSignerName(freshSig.signer_name)
    } catch { /* keep dialog interactive */ }
  }

  const closeSignDialog = (force = false) => {
    if (!force && signDocumentMutation.isPending) return
    setSignDialogDocumentId(null)
    setSignerName('')
    setSavedHandwrittenSignature(null)
  }

  const signDocumentMutation = useMutation({
    mutationFn: async (payload: { documentId: number; handwrittenSignatureDataUrl: string; signerName: string }) => {
      const res = await api.post(`/accounting/compliance/documents/${payload.documentId}/sign`, {
        country_code: 'MY',
        handwritten_signature_data_url: payload.handwrittenSignatureDataUrl,
        signer_name: payload.signerName || undefined,
      })
      return res.data
    },
    onSuccess: async () => {
      closeSignDialog(true)
      toast({ title: 'Signature Saved', description: 'Handwritten e-signature and certificate signature metadata were saved.' })
      await refetchDocuments()
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
    mutationFn: async (documentId: number) => {
      const res = await api.post(`/accounting/compliance/documents/${documentId}/submit`, { country_code: 'MY' })
      return res.data
    },
    onSuccess: async () => {
      toast({ title: 'Submitted', description: 'Document submitted to MyInvois Portal.' })
      await refetchDocuments()
    },
    onError: (error: any) => {
      toast({
        title: 'Submit Failed',
        description: error?.response?.data?.message || 'Unable to submit document.',
        variant: 'destructive',
      })
    },
  })


  if (isTenantLoading) {
    return (
      <div className="p-6">
        <Card className="p-6">Loading country plugin settings...</Card>
      </div>
    )
  }

  if (!isMyPluginEnabled) {
    return (
      <div className="p-6">
        <Card className="p-6 space-y-2">
          <h2 className="text-xl font-semibold">MyInvoice UI Unavailable</h2>
          <p className="text-muted-foreground">
            Enable the MY country plugin in settings to test MyInvoice submission, sync, and e-signature from UI.
          </p>
        </Card>
      </div>
    )
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-3xl font-bold">MyInvois </h1>
          <p className="text-muted-foreground mt-1">
            Submit MY e-Invoices and inspect provider metadata like UUID, long ID and submission ID, Validation status, and e-signature details.
          </p>
        </div>
        {/* <div className="flex gap-2 flex-wrap">
          <Button variant="outline" onClick={() => refetchDocuments()} disabled={isDocumentsLoading}>
            <Icon icon="solar:refresh-linear" className="w-4 h-4 mr-2" />
            Refresh Documents
          </Button>
          <Button variant="outline" onClick={() => syncPendingMutation.mutate()} disabled={syncPendingMutation.isPending}>
            <Icon icon="solar:refresh-square-linear" className="w-4 h-4 mr-2" />
            {syncPendingMutation.isPending ? 'Syncing...' : 'Sync Pending (MY)'}
          </Button>
        </div> */}
      </div>

      {myConfig && myConfig.credentials_complete === false && (
        <div className="flex items-start gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-yellow-900 dark:border-yellow-700 dark:bg-yellow-950 dark:text-yellow-200">
          <Icon icon="solar:danger-triangle-bold" className="mt-0.5 h-5 w-5 shrink-0 text-yellow-600 dark:text-yellow-400" />
          <div className="flex-1 text-sm">
            <p className="font-semibold">MyInvois credentials incomplete</p>
            <p className="mt-0.5">
              Missing: {(myConfig.missing_credentials ?? []).join(', ')}.{' '}
              Submissions will be blocked until configured.{' '}
              <a
                href="/accounting/configuration/myinvois"
                className="underline font-medium hover:opacity-80"
                onClick={(e) => { e.preventDefault(); navigate('/accounting/configuration/myinvois') }}
              >
                Go to MyInvois Settings
              </a>
            </p>
          </div>
        </div>
      )}

      <Card className="p-6">
        <h2 className="text-lg font-semibold mb-4">MY Compliance Documents</h2>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>ID</TableHead>
              <TableHead>Number</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Signature</TableHead>
              <TableHead>Validation</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {documents.map((doc) => (
              <TableRow key={doc.id}>
                <TableCell>{doc.id}</TableCell>
                <TableCell>
                  {(() => {
                    const path = getDocumentDetailPath(doc)
                    return path ? (
                      <button
                        type="button"
                        className="text-primary underline underline-offset-2 hover:opacity-75 font-medium"
                        onClick={() => navigate(path)}
                      >
                        {doc.document_number || '-'}
                      </button>
                    ) : (
                      doc.document_number || '-'
                    )
                  })()}
                </TableCell>
                <TableCell>
                  <Badge variant="secondary">{doc.compliance_status}</Badge>
                </TableCell>
                <TableCell>
                  {doc.signature_metadata?.enabled ? (
                    <Badge>{doc.signature_metadata.mode || 'signed'}</Badge>
                  ) : (
                    <span className="text-muted-foreground">unsigned</span>
                  )}
                </TableCell>
                <TableCell>
                  {doc.validation_link ? (
                    <a href={doc.validation_link} target="_blank" rel="noreferrer" className="text-primary underline">
                      Open Link
                    </a>
                  ) : (
                    <span className="text-muted-foreground">-</span>
                  )}
                </TableCell>
                <TableCell className="text-right">
                  <div className="flex gap-2 justify-end flex-wrap">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => navigate(`/accounting/reporting/myinvoice/${doc.id}`)}
                    >
                      View
                    </Button>
                    {canSubmit && (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => openSignDialog(doc.id)}
                        disabled={signDocumentMutation.isPending}
                      >
                        E-Sign
                      </Button>
                    )}
                    {canSubmit && (
                      <Button
                        size="sm"
                        onClick={() => submitDocumentMutation.mutate(doc.id)}
                        disabled={submitDocumentMutation.isPending}
                      >
                        Submit
                      </Button>
                    )}
                  </div>
                </TableCell>
              </TableRow>
            ))}
            {documents.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="text-center text-muted-foreground">
                  No MY compliance documents found.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </Card>

      <Dialog open={Boolean(signDialogDocumentId)} onOpenChange={(open) => (!open ? closeSignDialog() : undefined)}>
        <DialogContent className="sm:max-w-lg flex flex-col max-h-[90vh]">
          <DialogHeader>
            <DialogTitle>E-Sign Invoice</DialogTitle>
            <DialogDescription>
              Review the invoice below and save to generate the MyInvois e-signature from the configured organisation certificate.
            </DialogDescription>
          </DialogHeader>

          <div className="overflow-y-auto flex-1 min-h-0 pr-1">
          {pendingSignDocument ? (
            <div className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="rounded-lg border p-4">
                  <div className="text-sm text-muted-foreground">Invoice Number</div>
                  <div className="mt-2 font-medium">{pendingSignDocument.document_number || '-'}</div>
                </div>
                <div className="rounded-lg border p-4">
                  <div className="text-sm text-muted-foreground">Compliance Status</div>
                  <div className="mt-2 font-medium">{pendingSignDocument.compliance_status}</div>
                </div>
                <div className="rounded-lg border p-4">
                  <div className="text-sm text-muted-foreground">Country</div>
                  <div className="mt-2 font-medium">{pendingSignDocument.country_code}</div>
                </div>
                <div className="rounded-lg border p-4">
                  <div className="text-sm text-muted-foreground">Total Amount</div>
                  <div className="mt-2 font-medium">{pendingSignDocument.total_amount ?? '-'}</div>
                </div>
              </div>

              <div className="space-y-3 rounded-lg border p-4">
                <div className="flex items-center justify-between gap-3 flex-wrap">
                  <div>
                    <Label htmlFor="myinvoice-signer-name">Signer Name</Label>
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
                  id="myinvoice-signer-name"
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
                    key={signDialogDocumentId ?? 'signature-pad'}
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
          ) : (
            <div className="text-sm text-muted-foreground">Loading selected invoice...</div>
          )}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => closeSignDialog()} disabled={signDocumentMutation.isPending}>
              Cancel
            </Button>
            <Button
              onClick={() => {
                if (!signDialogDocumentId) {
                  toast({ title: 'Signature Save Failed', description: 'No document selected for signing. Re-open the dialog and try again.', variant: 'destructive' })
                  return
                }
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
                signDocumentMutation.mutate({ documentId: signDialogDocumentId, handwrittenSignatureDataUrl, signerName: signerName.trim() })
              }}
              disabled={!signDialogDocumentId || signDocumentMutation.isPending}
            >
              {signDocumentMutation.isPending ? 'Saving...' : 'Save Signature'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
