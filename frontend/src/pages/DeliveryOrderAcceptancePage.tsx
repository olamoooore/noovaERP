import { useState, useEffect, useRef } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface AcceptanceResponse {
  delivery_order: {
    id: number
    delivery_order_number: string
    customer_name: string
    customer_email: string | null
    delivery_date: string
    status: string
    notes: string | null
    total: number
    lines: Array<{
      id: number
      description: string
      quantity: number
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
  const [files, setFiles] = useState<File[]>([])
  const [signerName, setSignerName] = useState('')
  const [signerEmail, setSignerEmail] = useState('')
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
      if (files.length === 0) throw new Error('No file selected')
      const form = new FormData()
      files.forEach((f) => form.append('files[]', f))
      if (signerName) form.append('signer_name', signerName)
      if (signerEmail) form.append('signer_email', signerEmail)
      const res = await api.post(`/do-accept/${token}/evidence`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return res.data
    },
    onSuccess: (d: any) => {
      setFiles([])
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
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
              <Icon icon="solar:box-bold-duotone" className="h-6 w-6 text-primary" />
            </div>
            <div>
              <div className="text-xs uppercase tracking-wide text-muted-foreground">NoovaERP</div>
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

            <div className="mb-6 rounded-lg bg-slate-50 p-4">
              <div className="text-xs uppercase text-muted-foreground">Deliver to</div>
              <div className="font-semibold">{do_.customer_name}</div>
              {do_.customer_email && <div className="text-sm text-muted-foreground">{do_.customer_email}</div>}
            </div>

            <Separator className="my-6" />

            <div className="space-y-2">
              <div className="grid grid-cols-12 px-2 text-xs uppercase text-muted-foreground">
                <div className="col-span-7">Description</div>
                <div className="col-span-3 text-right">Qty</div>
                <div className="col-span-2 text-right">Total</div>
              </div>
              {do_.lines.map((line) => (
                <div key={line.id} className="grid grid-cols-12 border-b py-2 text-sm last:border-b-0">
                  <div className="col-span-7">{line.description}</div>
                  <div className="col-span-3 text-right">{line.quantity_delivered || line.quantity}</div>
                  <div className="col-span-2 text-right font-medium">
                    {new Intl.NumberFormat('en-US', { style: 'currency', currency: 'SGD' }).format(line.line_total)}
                  </div>
                </div>
              ))}
            </div>

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

              <div className="space-y-2">
                <Label>Signed document / evidence</Label>
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
                disabled={uploadMutation.isPending || files.length === 0}
                className="w-full md:w-auto"
              >
                {uploadMutation.isPending ? (
                  <div className="mr-2 h-4 w-4 animate-spin rounded-full border-b-2 border-white" />
                ) : (
                  <Icon icon="solar:upload-bold-duotone" className="mr-2 h-4 w-4" />
                )}
                {uploadMutation.isPending ? 'Uploading…' : files.length > 1 ? `Upload ${files.length} files` : 'Upload signed copy'}
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
          <p>This is a secure portal — your data is encrypted in transit. Powered by NoovaERP.</p>
        </div>
      </div>
    </div>
  )
}
