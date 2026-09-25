import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Separator } from '@/components/ui/separator'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

/**
 * Issue #149: online quotation acceptance / eSignature portal.
 *
 * Public (no auth) page rendered at /quotation-accept/:token. Calls the
 * public API endpoints added in this commit:
 *
 *   GET  /api/v1/quotation-accept/{token}
 *   POST /api/v1/quotation-accept/{token}/accept
 *
 * On successful acceptance the customer's typed name + email + optional
 * signature image are recorded and the SalesOrder transitions from `draft`
 * to `confirmed` via the existing state machine, with the audit trail
 * distinguishing `source = customer_portal` from an internal confirmation.
 */

interface QuotationSnapshot {
  company?: { name?: string; logo_url?: string }
  order_number: string
  status: string
  customer_name: string | null
  subtotal: number
  tax_amount: number
  total: number
  currency_code: string
  order_date: string | null
  required_date: string | null
  notes: string | null
  lines: Array<{
    description: string
    quantity: number
    unit_price: number
    line_total: number
  }>
  already_accepted: boolean
  signer_name: string | null
  signer_email: string | null
  accepted_at: string | null
}

const currency = (n: number, code: string) =>
  new Intl.NumberFormat('en-US', { style: 'currency', currency: code || 'USD', minimumFractionDigits: 2 }).format(Number(n) || 0)

const formatDate = (iso: string | null) => {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
  } catch {
    return iso
  }
}

export default function QuotationAcceptancePage() {
  const { token } = useParams<{ token: string }>()
  const { toast } = useToast()

  const [signerName, setSignerName] = useState('')
  const [signerEmail, setSignerEmail] = useState('')
  const [agreed, setAgreed] = useState(false)
  const [signatureDataUrl, setSignatureDataUrl] = useState<string | null>(null)
  const [isDrawing, setIsDrawing] = useState(false)

  const { data, isLoading, error } = useQuery({
    queryKey: ['quotation-acceptance', token],
    queryFn: async () => {
      const res = await api.get(`/quotation-accept/${token}`)
      return res.data as QuotationSnapshot
    },
    enabled: !!token,
    retry: false,
  })

  const acceptMutation = useMutation({
    mutationFn: async () => {
      const res = await api.post(`/quotation-accept/${token}/accept`, {
        signer_name: signerName,
        signer_email: signerEmail,
        agreed: agreed,
        signature: signatureDataUrl,
      })
      return res.data
    },
    onSuccess: () => {
      toast({ title: 'Quotation accepted', description: 'Thank you — your acceptance has been recorded.' })
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

  useEffect(() => {
    document.title = data?.order_number ? `Quotation ${data.order_number}` : 'Quotation acceptance'
  }, [data?.order_number])

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50">
        <div className="text-center">
          <Icon icon="eos-icons:loading" className="h-12 w-12 animate-spin text-primary mx-auto" />
          <p className="mt-3 text-sm text-muted-foreground">Loading quotation…</p>
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

  return (
    <div className="min-h-screen bg-slate-50">
      <div className="bg-white border-b">
        <div className="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
          <div className="flex items-center gap-3">
            {data.company?.logo_url ? (
              <img
                src={data.company.logo_url}
                alt={data.company?.name || 'Company logo'}
                className="h-10 max-w-[160px] object-contain"
              />
            ) : (
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                <Icon icon="solar:document-text-bold-duotone" className="h-6 w-6 text-primary" />
              </div>
            )}
            <div>
              {data.company?.name ? (
                <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{data.company.name}</div>
              ) : null}
              <div className="text-sm font-medium">Quotation acceptance portal</div>
            </div>
          </div>
          <Badge variant={data.already_accepted ? 'default' : 'outline'} className="capitalize">
            {data.already_accepted ? 'Accepted' : 'Awaiting your acceptance'}
          </Badge>
        </div>
      </div>

      <div className="max-w-4xl mx-auto px-6 py-8 space-y-6">
        <Card>
          <CardContent className="p-8">
            <div className="flex flex-wrap items-start justify-between gap-4 mb-6">
              <div>
                <div className="text-xs uppercase text-muted-foreground tracking-wide mb-1">Quotation</div>
                <h1 className="text-3xl font-bold">{data.order_number}</h1>
              </div>
              <div className="text-right text-sm space-y-1">
                <div className="flex items-center gap-2">
                  <span className="text-muted-foreground">Issued:</span>
                  <span className="font-medium">{formatDate(data.order_date)}</span>
                </div>
                {data.required_date && (
                  <div className="flex items-center gap-2">
                    <span className="text-muted-foreground">Required by:</span>
                    <span className="font-medium">{formatDate(data.required_date)}</span>
                  </div>
                )}
                <Badge variant="secondary" className="capitalize mt-1">{data.status}</Badge>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
              <div className="rounded-lg bg-slate-50 p-4">
                <div className="text-xs uppercase text-muted-foreground mb-1">Quotation for</div>
                <div className="font-semibold">{data.customer_name}</div>
              </div>
            </div>

            <Separator className="my-6" />

            <div className="space-y-2">
              <div className="grid grid-cols-12 text-xs uppercase text-muted-foreground px-2">
                <div className="col-span-6">Description</div>
                <div className="col-span-2 text-right">Qty</div>
                <div className="col-span-2 text-right">Unit Price</div>
                <div className="col-span-2 text-right">Total</div>
              </div>
              {data.lines.map((line, i) => (
                <div key={i} className="grid grid-cols-12 text-sm px-2 py-2 border-b last:border-b-0">
                  <div className="col-span-6">{line.description}</div>
                  <div className="col-span-2 text-right">{line.quantity}</div>
                  <div className="col-span-2 text-right">{currency(line.unit_price, data.currency_code)}</div>
                  <div className="col-span-2 text-right font-medium">{currency(line.line_total, data.currency_code)}</div>
                </div>
              ))}
            </div>

            <Separator className="my-6" />

            <div className="flex justify-end">
              <div className="w-full max-w-xs space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span>{currency(data.subtotal, data.currency_code)}</span>
                </div>
                {data.tax_amount > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Tax</span>
                    <span>{currency(data.tax_amount, data.currency_code)}</span>
                  </div>
                )}
                <Separator />
                <div className="flex justify-between text-base font-semibold">
                  <span>Total</span>
                  <span>{currency(data.total, data.currency_code)}</span>
                </div>
              </div>
            </div>

            {data.notes && (
              <div className="mt-6 text-xs">
                <div className="font-semibold uppercase text-muted-foreground mb-1">Notes</div>
                <p className="whitespace-pre-wrap">{data.notes}</p>
              </div>
            )}
          </CardContent>
        </Card>

        {!data.already_accepted && (
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
                  onChange={setSignatureDataUrl}
                  isDrawing={isDrawing}
                  setIsDrawing={setIsDrawing}
                />

                <div className="flex items-start gap-2">
                  <Checkbox
                    id="agreed"
                    checked={agreed}
                    onCheckedChange={(v) => setAgreed(!!v)}
                  />
                  <Label htmlFor="agreed" className="text-sm leading-snug cursor-pointer">
                    I agree that the quotation details above are accurate and I accept the offer described herein.
                    My electronic signature has the same legal effect as a handwritten signature.
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

        {data.already_accepted && (
          <Card className="border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/40">
            <CardContent className="py-8 text-center">
              <Icon icon="solar:check-circle-bold-duotone" className="h-12 w-12 text-green-600 mx-auto mb-3" />
              <h3 className="text-lg font-semibold text-green-900 dark:text-green-200">Quotation accepted</h3>
              <p className="text-sm text-green-700 dark:text-green-300 mt-1">
                {data.signer_name && `Signed by ${data.signer_name} `}
                {data.accepted_at && `on ${formatDate(data.accepted_at)}`}
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

function SignaturePad({
  onChange,
  isDrawing,
  setIsDrawing,
}: {
  onChange: (dataUrl: string | null) => void
  isDrawing: boolean
  setIsDrawing: (v: boolean) => void
}) {
  const [canvasRef, setCanvasRef] = useState<HTMLCanvasElement | null>(null)
  const [hasContent, setHasContent] = useState(false)

  useEffect(() => {
    if (!canvasRef) return
    const ctx = canvasRef.getContext('2d')
    if (!ctx) return
    ctx.strokeStyle = '#0f172a'
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
  }, [canvasRef])

  const start = (e: React.PointerEvent<HTMLCanvasElement>) => {
    setIsDrawing(true)
    const rect = canvasRef!.getBoundingClientRect()
    const ctx = canvasRef!.getContext('2d')!
    ctx.beginPath()
    ctx.moveTo(e.clientX - rect.left, e.clientY - rect.top)
    canvasRef!.setPointerCapture(e.pointerId)
  }

  const move = (e: React.PointerEvent<HTMLCanvasElement>) => {
    if (!isDrawing || !canvasRef) return
    const rect = canvasRef.getBoundingClientRect()
    const ctx = canvasRef.getContext('2d')!
    ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top)
    ctx.stroke()
    setHasContent(true)
  }

  const end = () => {
    if (!canvasRef) return
    setIsDrawing(false)
    if (hasContent) {
      onChange(canvasRef.toDataURL('image/png'))
    }
  }

  const clear = () => {
    if (!canvasRef) return
    const ctx = canvasRef.getContext('2d')!
    ctx.clearRect(0, 0, canvasRef.width, canvasRef.height)
    setHasContent(false)
    onChange(null)
  }

  return (
    <div className="space-y-2">
      <Label>Signature</Label>
      <div className="rounded-md border bg-white">
        <canvas
          ref={setCanvasRef}
          width={500}
          height={140}
          className="w-full touch-none"
          onPointerDown={start}
          onPointerMove={move}
          onPointerUp={end}
          onPointerCancel={end}
        />
      </div>
      <div className="flex items-center justify-between text-xs text-muted-foreground">
        <span>Sign with your mouse or finger above</span>
        <Button type="button" variant="ghost" size="sm" onClick={clear} disabled={!hasContent}>
          <Icon icon="solar:close-circle-bold" className="mr-1 h-3.5 w-3.5" />
          Clear
        </Button>
      </div>
    </div>
  )
}
