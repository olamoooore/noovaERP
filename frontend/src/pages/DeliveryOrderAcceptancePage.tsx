import { useState, useRef } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { useToast } from '@/hooks/use-toast'
import { SignaturePad } from '@/components/signature/SignaturePad'
import { DocumentItemsTable } from '@/components/public/DocumentItemsTable'
import api from '@/lib/api'

interface CompanyBranding {
  name?: string
  logo_url?: string
}

interface AcceptanceResponse {
  company?: CompanyBranding
  delivery_order: {
    id: number
    delivery_order_number: string
    customer_name: string
    customer_email: string | null
    delivery_date: string
    status: string
    notes: string | null
    total: number
    currency_code?: string | null
    expected_date?: string | null
    po_number?: string | null
    carrier?: string | null
    tracking_number?: string | null
    attention_to?: string | null
    salesperson?: string | null
    shipping_address?: string | null
    payment_terms?: string | null
    lines: Array<{
      id: number
      line_type?: string | null
      product_name?: string | null
      sku?: string | null
      description?: string | null
      model_number?: string | null
      product_notes?: string | null
      patient_name?: string | null
      lot_number?: string | null
      batch_number?: string | null
      serial_number?: string | null
      expiry_date?: string | null
      unit?: string | null
      quantity: number
      quantity_ordered?: number
      quantity_delivered: number
      unit_price: number
      line_total: number
    }>
  }
  signer: { name: string | null; email: string | null; signed_at: string | null }
  already_signed: boolean
  expired: boolean
  expires_at: string | null
}

const fmtMoney = (value: number, code?: string | null) => {
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: code && /^[A-Za-z]{3}$/.test(code) ? code : 'USD',
      minimumFractionDigits: 2,
    }).format(value ?? 0)
  } catch {
    return (value ?? 0).toFixed(2)
  }
}

const formatDate = (iso: string | null) => {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
  } catch {
    return iso
  }
}

export default function DeliveryOrderAcceptancePage() {
  const { token } = useParams<{ token: string }>()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [files, setFiles] = useState<File[]>([])
  const [signerName, setSignerName] = useState('')
  const [signerEmail, setSignerEmail] = useState('')
  const [signatureDataUrl, setSignatureDataUrl] = useState<string | null>(null)
  const fileInputRef = useRef<HTMLInputElement | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['do-acceptance', token],
    queryFn: async () => {
      const res = await api.get(`/do-accept/${token}`)
      return res.data as AcceptanceResponse
    },
    enabled: !!token,
    retry: false,
  })

  const uploadMutation = useMutation({
    mutationFn: async () => {
      if (files.length === 0 && !signatureDataUrl) {
        throw new Error('Upload a document or sign digitally.')
      }
      const form = new FormData()
      files.forEach((f) => form.append('files[]', f))
      if (signatureDataUrl) form.append('signature', signatureDataUrl)
      if (signerName) form.append('signer_name', signerName)
      if (signerEmail) form.append('signer_email', signerEmail)
      const res = await api.post(`/do-accept/${token}/evidence`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return res.data
    },
    onSuccess: (d: any) => {
      setFiles([])
      setSignatureDataUrl(null)
      // Re-read the document so the portal flips to "Received" and the form
      // is replaced — without this the customer stays on the upload screen
      // and appears able to submit again.
      queryClient.invalidateQueries({ queryKey: ['do-acceptance', token] })
      toast({
        title: 'Documents uploaded',
        description: (d?.count ?? 1) > 1 ? `${d.count} files sent to the vendor.` : 'Your file has been sent to the vendor.',
      })
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not upload',
        description: err.response?.data?.error || 'Please try again.',
      })
    },
  })

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <div className="text-center">
          <Icon icon="eos-icons:loading" className="h-12 w-12 animate-spin text-primary mx-auto" />
          <p className="mt-3 text-sm text-muted-foreground">Loading delivery order…</p>
        </div>
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <Card className="w-full max-w-md">
          <CardContent className="py-12 text-center">
            <Icon icon="solar:danger-triangle-bold-duotone" className="mx-auto mb-3 h-12 w-12 text-red-500" />
            <h2 className="text-lg font-semibold">Invalid or expired link</h2>
            <p className="mt-2 text-sm text-muted-foreground">
              This link is invalid or has expired. Please contact your vendor for a new one.
            </p>
          </CardContent>
        </Card>
      </div>
    )
  }

  const do_ = data.delivery_order

  return (
    <div className="min-h-screen bg-slate-50">
      <div className="border-b bg-white">
        <div className="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
          <div className="flex items-center gap-3">
            {data.company?.logo_url ? (
              <img
                src={data.company.logo_url}
                alt={data.company?.name || 'Company logo'}
                className="h-10 max-w-[160px] object-contain"
              />
            ) : (
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                <Icon icon="solar:box-bold-duotone" className="h-6 w-6 text-primary" />
              </div>
            )}
            <div>
              {data.company?.name ? (
                <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{data.company.name}</div>
              ) : null}
              <div className="text-sm font-medium">Delivery order portal</div>
            </div>
          </div>
          <Badge variant={data.already_signed ? 'default' : 'outline'} className="capitalize">
            {data.already_signed ? 'Received' : 'Awaiting your signature'}
          </Badge>
        </div>
      </div>

      <div className="mx-auto max-w-3xl space-y-6 px-6 py-8">
        {data.expired && !data.already_signed && (
          <div className="rounded-lg border border-red-200 bg-red-50 p-4">
            <div className="flex items-start gap-3">
              <Icon icon="solar:danger-triangle-bold" className="mt-0.5 h-5 w-5 text-red-600" />
              <div>
                <div className="font-medium text-red-900">This link has expired</div>
                <p className="text-sm text-red-700">Please contact your vendor to issue a new link.</p>
              </div>
            </div>
          </div>
        )}

        {/* Delivery order header */}
        <Card>
          <CardContent className="p-8">
            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
              <div>
                <div className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Delivery Order</div>
                <h1 className="text-3xl font-bold">{do_.delivery_order_number}</h1>
              </div>
              <div className="text-right text-sm">
                <div className="text-muted-foreground">Delivery date</div>
                <div className="font-medium">{formatDate(do_.delivery_date)}</div>
              </div>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="rounded-lg bg-slate-50 p-4">
                <div className="text-xs uppercase text-muted-foreground">Deliver to</div>
                <div className="font-semibold">{do_.customer_name}</div>
                {do_.attention_to && <div className="text-sm">Attn: {do_.attention_to}</div>}
                {do_.shipping_address && (
                  <div className="mt-1 whitespace-pre-wrap text-sm text-muted-foreground">{do_.shipping_address}</div>
                )}
                {do_.customer_email && <div className="text-sm text-muted-foreground">{do_.customer_email}</div>}
              </div>

              <dl className="space-y-1.5 rounded-lg border p-4 text-sm">
                {do_.po_number && (
                  <div className="flex justify-between gap-3"><dt className="text-muted-foreground">PO number</dt><dd className="font-medium">{do_.po_number}</dd></div>
                )}
                {do_.expected_date && (
                  <div className="flex justify-between gap-3"><dt className="text-muted-foreground">Expected</dt><dd className="font-medium">{formatDate(do_.expected_date)}</dd></div>
                )}
                {do_.carrier && (
                  <div className="flex justify-between gap-3"><dt className="text-muted-foreground">Carrier</dt><dd className="font-medium">{do_.carrier}</dd></div>
                )}
                {do_.tracking_number && (
                  <div className="flex justify-between gap-3"><dt className="text-muted-foreground">Tracking #</dt><dd className="font-medium">{do_.tracking_number}</dd></div>
                )}
                {do_.payment_terms && (
                  <div className="flex justify-between gap-3"><dt className="text-muted-foreground">Payment terms</dt><dd className="font-medium">{do_.payment_terms}</dd></div>
                )}
                {do_.salesperson && (
                  <div className="flex justify-between gap-3"><dt className="text-muted-foreground">Salesperson</dt><dd className="font-medium">{do_.salesperson}</dd></div>
                )}
                <div className="flex justify-between gap-3 border-t pt-1.5">
                  <dt className="font-medium">Total</dt>
                  <dd className="font-semibold">{fmtMoney(do_.total, do_.currency_code)}</dd>
                </div>
              </dl>
            </div>

            <Separator className="my-6" />

            <DocumentItemsTable
              lines={do_.lines}
              currencyCode={do_.currency_code}
              qtyHeader="Qty delivered"
              qtyFor={(line) => line.quantity_delivered || line.quantity || 0}
            />

            {do_.notes && (
              <>
                <Separator className="my-6" />
                <div>
                  <div className="mb-1 text-xs font-semibold uppercase text-muted-foreground">Notes</div>
                  <p className="whitespace-pre-wrap text-sm">{do_.notes}</p>
                </div>
              </>
            )}
          </CardContent>
        </Card>

        {/* Upload signed copy / evidence */}
        {!data.already_signed && !data.expired && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Sign & upload</CardTitle>
              <p className="text-sm text-muted-foreground">
                Download and sign this delivery order, then upload the signed copy (or any supporting document) below.
              </p>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="do-signer-name">Your name</Label>
                  <input
                    id="do-signer-name"
                    value={signerName}
                    onChange={(e) => setSignerName(e.target.value)}
                    placeholder="Full name"
                    className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="do-signer-email">Email</Label>
                  <input
                    id="do-signer-email"
                    type="email"
                    value={signerEmail}
                    onChange={(e) => setSignerEmail(e.target.value)}
                    placeholder="you@company.com"
                    className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                  />
                </div>
              </div>

              <div className="relative py-1 text-center">
                <Separator />
                <span className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 bg-card px-2 text-xs uppercase tracking-wide text-muted-foreground">
                  or sign digitally
                </span>
              </div>

              <SignaturePad
                label="Your signature"
                value={signatureDataUrl}
                onChange={setSignatureDataUrl}
                hint="Sign with your mouse, finger or stylus — no printing needed"
              />

              <div className="space-y-2">
                <Label>Signed document / evidence (optional if you signed above)</Label>
                <div className="flex flex-wrap gap-2">
                  {files.map((file, idx) => (
                    <div key={`${file.name}-${idx}`} className="group relative h-20 w-20 overflow-hidden rounded-md border bg-muted">
                      <div className="flex h-full w-full flex-col items-center justify-center gap-1 px-1 text-muted-foreground">
                        <Icon icon="solar:file-text-bold-duotone" className="h-6 w-6" />
                        <span className="w-full truncate text-center text-[9px]">{file.name}</span>
                      </div>
                      <button
                        type="button"
                        onClick={() => setFiles((prev) => prev.filter((_, i) => i !== idx))}
                        className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white opacity-0 transition-opacity group-hover:opacity-100"
                      >
                        <Icon icon="solar:close-circle-bold" className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  ))}
                  <button
                    type="button"
                    onClick={() => fileInputRef.current?.click()}
                    title="Add file"
                    className="flex h-20 w-20 flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed text-muted-foreground transition-colors hover:border-primary hover:text-primary"
                  >
                    <Icon icon="solar:add-circle-bold-duotone" className="h-6 w-6" />
                    <span className="text-[10px] font-medium">Add</span>
                  </button>
                </div>
                <input
                  ref={fileInputRef}
                  type="file"
                  multiple
                  accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
                  className="hidden"
                  onChange={(e) => {
                    const picked = Array.from(e.target.files ?? [])
                    if (picked.length) setFiles((prev) => [...prev, ...picked])
                    e.target.value = ''
                  }}
                />
                <p className="text-xs text-muted-foreground">PDF or image, up to 10 MB each.</p>
              </div>

              <Button
                onClick={() => uploadMutation.mutate()}
                disabled={uploadMutation.isPending || (files.length === 0 && !signatureDataUrl)}
                className="w-full md:w-auto"
              >
                {uploadMutation.isPending ? (
                  <div className="mr-2 h-4 w-4 animate-spin rounded-full border-b-2 border-white" />
                ) : (
                  <Icon icon="solar:upload-bold-duotone" className="mr-2 h-4 w-4" />
                )}
                {uploadMutation.isPending
                  ? 'Submitting…'
                  : files.length > 1
                    ? `Submit ${files.length} files`
                    : signatureDataUrl && files.length === 0
                      ? 'Submit signature'
                      : 'Submit signed copy'}
              </Button>
            </CardContent>
          </Card>
        )}

        {data.already_signed && (
          <Card className="border-green-200 bg-green-50">
            <CardContent className="py-8 text-center">
              <Icon icon="solar:check-circle-bold-duotone" className="mx-auto mb-3 h-12 w-12 text-green-600" />
              <h3 className="text-lg font-semibold text-green-900">Received</h3>
              <p className="mt-1 text-sm text-green-700">
                {data.signer.name && `Signed by ${data.signer.name} `}
                {data.signer.signed_at && `on ${formatDate(data.signer.signed_at)}`}
              </p>
            </CardContent>
          </Card>
        )}

        <div className="text-center text-xs text-muted-foreground">
          <p>This is a secure portal — your data is encrypted in transit.</p>
        </div>
      </div>
    </div>
  )
}
