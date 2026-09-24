import { useState, useEffect, useRef } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { SignaturePad } from '@/components/signature/SignaturePad'
import { DocumentItemsTable } from '@/components/public/DocumentItemsTable'
import { Separator } from '@/components/ui/separator'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface AcceptanceResponse {
  invoice: {
    id: number
    invoice_number: string
    customer_name: string
    customer_email: string | null
    subtotal: number
    tax_amount: number
    discount_amount: number
    total: number
    paid_amount: number
    balance: number
    currency_code: string
    invoice_date: string
    due_date: string
    status: string
    notes: string
    terms: string
    lines: Array<{
      id: number
      description: string
      quantity: number
      unit_price: number
      line_type?: string | null
      product_name?: string | null
      sku?: string | null
      model_number?: string | null
      product_notes?: string | null
      patient_name?: string | null
      lot_number?: string | null
      serial_number?: string | null
      expiry_date?: string | null
      unit?: string | null
      tax_rate: number
      tax_amount: number
      discount_amount: number
      line_total: number
    }>
    payment_link_url: string | null
  }
  signer: {
    name: string | null
    email: string | null
    signed_at: string | null
    signer_ip: string | null
  }
  already_accepted: boolean
  expired: boolean
  revoked?: boolean
  revoked_at?: string | null
  withdrawn?: boolean
  expires_at: string | null
  status_trail: Array<{
    key: string
    label: string
    completed: boolean
    date: string | null
  }>
}

const currency = (n: number, code: string) =>
  new Intl.NumberFormat('en-US', { style: 'currency', currency: code || 'USD', minimumFractionDigits: 2 }).format(n)

const formatDate = (iso: string | null) => {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
  } catch {
    return iso
  }
}

export default function InvoiceAcceptancePage() {
  const { token } = useParams<{ token: string }>()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [signerName, setSignerName] = useState('')
  const [signerEmail, setSignerEmail] = useState('')
  const [agreed, setAgreed] = useState(false)
  const [signatureDataUrl, setSignatureDataUrl] = useState<string | null>(null)
  const [paymentFiles, setPaymentFiles] = useState<File[]>([])
  const paymentFileInputRef = useRef<HTMLInputElement | null>(null)
  const [paymentNotes, setPaymentNotes] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['invoice-acceptance', token],
    queryFn: async () => {
      const res = await api.get(`/invoice-accept/${token}`)
      return res.data as AcceptanceResponse
    },
    enabled: !!token,
    retry: false,
  })

  const acceptMutation = useMutation({
    mutationFn: async () => {
      const res = await api.post(`/invoice-accept/${token}/accept`, {
        signer_name: signerName,
        signer_email: signerEmail,
        agreed: agreed,
        signature: signatureDataUrl,
      })
      return res.data
    },
    onSuccess: () => {
      // Re-read so the page shows the accepted state instead of the form.
      queryClient.invalidateQueries({ queryKey: ['invoice-acceptance', token] })
      toast({ title: 'Invoice accepted', description: 'Thank you — your acceptance has been recorded.' })
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not submit acceptance',
        description: err.response?.data?.error || err.response?.data?.message || 'Please try again.',
      })
    },
  })

  const handleAccept = (e: React.FormEvent) => {
    e.preventDefault()
    if (!signerName || !signerEmail) {
      toast({ variant: 'destructive', title: 'Name and email required' })
      return
    }
    if (!agreed) {
      toast({ variant: 'destructive', title: 'Please confirm agreement' })
      return
    }
    acceptMutation.mutate()
  }

  const uploadEvidenceMutation = useMutation({
    mutationFn: async () => {
      if (paymentFiles.length === 0) throw new Error('No file selected')
      const form = new FormData()
      paymentFiles.forEach((f) => form.append('files[]', f))
      if (paymentNotes) form.append('notes', paymentNotes)
      form.append('customer_name', signerName || '')
      form.append('customer_email', signerEmail || '')
      const res = await api.post(`/invoice-accept/${token}/evidence`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return res.data
    },
    onSuccess: (data: any) => {
      setPaymentFiles([])
      setPaymentNotes('')
      toast({
        title: 'Evidence uploaded',
        description:
          (data?.count ?? 1) > 1
            ? `${data.count} files have been sent to the vendor.`
            : 'Your file has been sent to the vendor.',
      })
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not upload evidence',
        description: err.response?.data?.error || err.response?.data?.message || 'Please try again.',
      })
    },
  })

  const handleUploadEvidence = (e: React.FormEvent) => {
    e.preventDefault()
    if (paymentFiles.length === 0) {
      toast({ variant: 'destructive', title: 'Please choose at least one file to upload' })
      return
    }
    uploadEvidenceMutation.mutate()
  }

  useEffect(() => {
    document.title = data?.invoice ? `Invoice ${data.invoice.invoice_number}` : 'Invoice'
  }, [data?.invoice])

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50">
        <div className="text-center">
          <Icon icon="eos-icons:loading" className="h-12 w-12 animate-spin text-primary mx-auto" />
          <p className="mt-3 text-sm text-muted-foreground">Loading invoice…</p>
        </div>
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50 p-6">
        <Card className="max-w-md w-full">
          <CardContent className="py-12 text-center">
            <Icon icon="solar:danger-triangle-bold-duotone" className="h-12 w-12 text-red-500 mx-auto mb-3" />
            <h2 className="text-lg font-semibold">Invalid or expired link</h2>
            <p className="text-sm text-muted-foreground mt-2">
              This acceptance link is invalid or has expired. Please contact your vendor to request a new one.
            </p>
          </CardContent>
        </Card>
      </div>
    )
  }

  const inv = data.invoice
  const isPaid = inv.paid_amount >= inv.total && inv.total > 0
  const isFullyPaid = isPaid

  return (
    <div className="min-h-screen bg-slate-50">
      <div className="bg-white border-b">
        <div className="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center">
              <Icon icon="solar:bill-list-bold-duotone" className="h-6 w-6 text-primary" />
            </div>
            <div>
              <div className="text-xs text-muted-foreground uppercase tracking-wide">NoovaERP</div>
              <div className="text-sm font-medium">Invoice acceptance portal</div>
            </div>
          </div>
          <Badge
            variant={data.revoked ? 'destructive' : data.already_accepted ? 'default' : 'outline'}
            className="capitalize"
          >
            {data.revoked
              ? 'Withdrawn'
              : data.already_accepted
                ? 'Accepted'
                : 'Awaiting your acceptance'}
          </Badge>
        </div>
      </div>

      <div className="max-w-4xl mx-auto px-6 py-8 space-y-6">
        {data.revoked && (
          <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40">
            <div className="flex items-start gap-3">
              <Icon icon="solar:danger-triangle-bold" className="h-5 w-5 text-amber-600 mt-0.5" />
              <div>
                <div className="font-medium text-amber-900 dark:text-amber-200">
                  This invoice has been withdrawn
                </div>
                <p className="text-sm text-amber-700 dark:text-amber-300">
                  The vendor has withdrawn this invoice and it is being reissued. This link is no longer
                  valid — please disregard any earlier copy and wait for the updated invoice.
                </p>
              </div>
            </div>
          </div>
        )}

        {!data.revoked && data.expired && !data.already_accepted && (
          <div className="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/40">
            <div className="flex items-start gap-3">
              <Icon icon="solar:danger-triangle-bold" className="h-5 w-5 text-red-600 mt-0.5" />
              <div>
                <div className="font-medium text-red-900 dark:text-red-200">This acceptance link has expired</div>
                <p className="text-sm text-red-700 dark:text-red-300">
                  Please contact your vendor to issue a new acceptance link.
                </p>
              </div>
            </div>
          </div>
        )}

        {/* Status Trail */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Invoice status</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-center gap-1.5 overflow-x-auto pb-0.5">
              {data.status_trail.map((step, i) => (
                <div key={step.key} className="flex shrink-0 items-center gap-1.5">
                  <div className={`flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] ${
                    step.completed ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'
                  }`}>
                    {step.completed ? (
                      <Icon icon="solar:check-circle-bold" className="h-3.5 w-3.5" />
                    ) : (
                      <Icon icon="solar:clock-circle-bold" className="h-3.5 w-3.5" />
                    )}
                    <span className="font-medium">{step.label}</span>
                  </div>
                  {i < data.status_trail.length - 1 && (
                    <Icon icon="solar:arrow-right-bold" className="h-3 w-3 shrink-0 text-slate-400" />
                  )}
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Invoice Header */}
        <Card>
          <CardContent className="p-8">
            <div className="flex flex-wrap items-start justify-between gap-4 mb-6">
              <div>
                <div className="text-xs uppercase text-muted-foreground tracking-wide mb-1">Invoice</div>
                <h1 className="text-3xl font-bold">{inv.invoice_number}</h1>
              </div>
              <div className="text-right text-sm space-y-1">
                <div className="flex items-center gap-2">
                  <span className="text-muted-foreground">Issued:</span>
                  <span className="font-medium">{formatDate(inv.invoice_date)}</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-muted-foreground">Due:</span>
                  <span className="font-medium">{formatDate(inv.due_date)}</span>
                </div>
                <Badge variant={isFullyPaid ? 'default' : 'secondary'} className="capitalize mt-1">
                  {inv.status}
                </Badge>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
              <div className="rounded-lg bg-slate-50 p-4">
                <div className="text-xs uppercase text-muted-foreground mb-1">Bill to</div>
                <div className="font-semibold">{inv.customer_name}</div>
                {inv.customer_email && (
                  <div className="text-sm text-muted-foreground">{inv.customer_email}</div>
                )}
              </div>
            </div>

            <Separator className="my-6" />

            {/* Line items */}
            <DocumentItemsTable lines={inv.lines} currencyCode={inv.currency_code} />

            <Separator className="my-6" />

            <div className="flex justify-end">
              <div className="w-full max-w-xs space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span>{currency(inv.subtotal, inv.currency_code)}</span>
                </div>
                {inv.tax_amount > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Tax</span>
                    <span>{currency(inv.tax_amount, inv.currency_code)}</span>
                  </div>
                )}
                {inv.discount_amount > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Discount</span>
                    <span className="text-red-600">−{currency(inv.discount_amount, inv.currency_code)}</span>
                  </div>
                )}
                <Separator />
                <div className="flex justify-between text-base font-semibold">
                  <span>Total</span>
                  <span>{currency(inv.total, inv.currency_code)}</span>
                </div>
                {inv.paid_amount > 0 && (
                  <>
                    <div className="flex justify-between text-sm">
                      <span className="text-muted-foreground">Paid</span>
                      <span className="text-green-600">{currency(inv.paid_amount, inv.currency_code)}</span>
                    </div>
                    <div className="flex justify-between text-base font-bold">
                      <span>Balance due</span>
                      <span className="text-primary">{currency(inv.balance, inv.currency_code)}</span>
                    </div>
                  </>
                )}
              </div>
            </div>

            {(inv.notes || inv.terms) && (
              <div className="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                {inv.notes && (
                  <div>
                    <div className="font-semibold uppercase text-muted-foreground mb-1">Notes</div>
                    <p className="whitespace-pre-wrap">{inv.notes}</p>
                  </div>
                )}
                {inv.terms && (
                  <div>
                    <div className="font-semibold uppercase text-muted-foreground mb-1">Terms</div>
                    <p className="whitespace-pre-wrap">{inv.terms}</p>
                  </div>
                )}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Pay section if balance > 0 */}
        {!data.revoked && !isFullyPaid && inv.balance > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Payment</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <p className="text-sm text-muted-foreground">
                Amount due: <span className="font-bold text-foreground">{currency(inv.balance, inv.currency_code)}</span>
              </p>
              {inv.payment_link_url ? (
                <a
                  href={inv.payment_link_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center justify-center rounded-md bg-primary px-6 py-3 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90"
                >
                  <Icon icon="solar:card-bold-duotone" className="mr-2 h-5 w-5" />
                  Pay {currency(inv.balance, inv.currency_code)} online
                </a>
              ) : (
                <p className="text-sm text-muted-foreground italic">
                  Please contact your vendor for offline payment details (bank transfer, cheque, etc.).
                </p>
              )}
            </CardContent>
          </Card>
        )}

        {/* Evidence upload — before the acceptance submit, so the customer
            can attach a receipt, signed document, or any supporting file */}
        {!data.revoked && !isFullyPaid && inv.balance > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Evidence</CardTitle>
              <p className="text-sm text-muted-foreground">
                Attach a payment receipt, signed document, or any supporting file for the vendor to review.
              </p>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleUploadEvidence} className="space-y-4">
                <div className="space-y-2">
                  <Label>Evidence files</Label>
                  <div className="flex flex-wrap gap-2">
                    {paymentFiles.map((file, idx) => (
                      <FileThumbnail
                        key={`${file.name}-${file.lastModified}-${idx}`}
                        file={file}
                        onRemove={() => setPaymentFiles((prev) => prev.filter((_, i) => i !== idx))}
                      />
                    ))}

                    <button
                      type="button"
                      onClick={() => paymentFileInputRef.current?.click()}
                      title="Add file"
                      className="flex h-20 w-20 flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed text-muted-foreground transition-colors hover:border-primary hover:text-primary"
                    >
                      <Icon icon="solar:add-circle-bold-duotone" className="h-6 w-6" />
                      <span className="text-[10px] font-medium">Add</span>
                    </button>
                  </div>
                  <input
                    ref={paymentFileInputRef}
                    type="file"
                    multiple
                    accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
                    className="hidden"
                    onChange={(e) => {
                      const picked = Array.from(e.target.files ?? [])
                      if (picked.length) {
                        setPaymentFiles((prev) => [...prev, ...picked])
                      }
                      e.target.value = ''
                    }}
                  />
                  <p className="text-xs text-muted-foreground">
                    PDF or image, up to 10 MB each.
                    {paymentFiles.length > 0
                      ? ` ${paymentFiles.length} file${paymentFiles.length === 1 ? '' : 's'} ready to upload.`
                      : ''}
                  </p>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="payment-notes">Reference / note (optional)</Label>
                  <Input
                    id="payment-notes"
                    placeholder="e.g. Transfer ref 12345678"
                    value={paymentNotes}
                    onChange={(e) => setPaymentNotes(e.target.value)}
                  />
                </div>
                <Button
                  type="submit"
                  disabled={uploadEvidenceMutation.isPending || paymentFiles.length === 0}
                  className="w-full md:w-auto"
                >
                  {uploadEvidenceMutation.isPending ? (
                    <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2" />
                  ) : (
                    <Icon icon="solar:upload-bold-duotone" className="mr-2 h-4 w-4" />
                  )}
                  {uploadEvidenceMutation.isPending
                    ? 'Uploading…'
                    : paymentFiles.length > 1
                      ? `Upload ${paymentFiles.length} files`
                      : 'Upload evidence'}
                </Button>
              </form>
            </CardContent>
          </Card>
        )}

        {/* Acceptance section */}
        {!data.revoked && !data.already_accepted && !data.expired && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Acceptance & signature</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleAccept} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="signer-name">Full name</Label>
                    <Input
                      id="signer-name"
                      placeholder="Your full name"
                      value={signerName}
                      onChange={(e) => setSignerName(e.target.value)}
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="signer-email">Email address</Label>
                    <Input
                      id="signer-email"
                      type="email"
                      placeholder="you@company.com"
                      value={signerEmail}
                      onChange={(e) => setSignerEmail(e.target.value)}
                      required
                    />
                  </div>
                </div>

                <SignaturePad
                  label="Signature"
                  value={signatureDataUrl}
                  onChange={setSignatureDataUrl}
                />

                <div className="flex items-start gap-2">
                  <Checkbox
                    id="agreed"
                    checked={agreed}
                    onCheckedChange={(v) => setAgreed(!!v)}
                  />
                  <Label htmlFor="agreed" className="text-sm leading-snug cursor-pointer">
                    I agree that the invoice details above are accurate and I accept the goods/services
                    described herein. My electronic signature has the same legal effect as a handwritten signature.
                  </Label>
                </div>

                <Button
                  type="submit"
                  disabled={acceptMutation.isPending || !agreed}
                  className="w-full md:w-auto"
                >
                  {acceptMutation.isPending ? (
                    <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2" />
                  ) : (
                    <Icon icon="solar:check-circle-bold-duotone" className="mr-2 h-5 w-5" />
                  )}
                  Submit acceptance
                </Button>
              </form>
            </CardContent>
          </Card>
        )}

        {!data.revoked && data.already_accepted && (
          <Card className="border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/40">
            <CardContent className="py-8 text-center">
              <Icon icon="solar:check-circle-bold-duotone" className="h-12 w-12 text-green-600 mx-auto mb-3" />
              <h3 className="text-lg font-semibold text-green-900 dark:text-green-200">Invoice accepted</h3>
              <p className="text-sm text-green-700 dark:text-green-300 mt-1">
                {data.signer.name && `Signed by ${data.signer.name} `}
                {data.signer.signed_at && `on ${formatDate(data.signer.signed_at)}`}
              </p>
            </CardContent>
          </Card>
        )}

        <div className="text-center text-xs text-muted-foreground">
          <p>This is a secure portal — your data is encrypted in transit. Powered by NoovaERP.</p>
        </div>
      </div>
    </div>
  )
}

function FileThumbnail({ file, onRemove }: { file: File; onRemove: () => void }) {
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)

  useEffect(() => {
    if (!file.type.startsWith('image/')) return
    const url = URL.createObjectURL(file)
    setPreviewUrl(url)
    return () => URL.revokeObjectURL(url)
  }, [file])

  return (
    <div className="group relative h-20 w-20 overflow-hidden rounded-md border bg-muted">
      {previewUrl ? (
        <img src={previewUrl} alt={file.name} className="h-full w-full object-cover" />
      ) : (
        <div className="flex h-full w-full flex-col items-center justify-center gap-1 px-1 text-muted-foreground">
          <Icon icon="solar:file-text-bold-duotone" className="h-6 w-6" />
          <span className="w-full truncate text-center text-[9px]">{file.name}</span>
        </div>
      )}
      <button
        type="button"
        onClick={onRemove}
        title={`Remove ${file.name}`}
        className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white opacity-0 transition-opacity group-hover:opacity-100"
      >
        <Icon icon="solar:close-circle-bold" className="h-3.5 w-3.5" />
      </button>
    </div>
  )
}
