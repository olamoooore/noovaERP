import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import * as pdfjsLib from 'pdfjs-dist'
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'

import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { SignaturePad } from '@/components/signature/SignaturePad'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl

type SignMethod = 'draw' | 'type' | 'upload'

interface SignResponse {
  request: {
    id: number
    document_type: string
    document_number: string | null
    status: string
    signer_name: string | null
    signer_email: string | null
    signed_at: string | null
  }
  document: { type: string; number: string | null }
  open: boolean
  expired: boolean
  signed: boolean
}

type FieldKind = 'signature' | 'date' | 'name'

interface FieldRect {
  kind: FieldKind
  page: number
  x: number
  y: number
  width: number
  height: number
  pdf: { x: number; y: number; width: number; height: number }
}

interface RenderedPage {
  url: string
  width: number
  height: number
  fields: FieldRect[]
}

const DOCUMENT_LABEL: Record<string, string> = {
  invoice: 'Invoice',
  quotation: 'Quotation',
  delivery_order: 'Delivery order',
}

const FIELD_WIDTH_PT: Record<FieldKind, number> = { signature: 150, date: 112, name: 120 }

// Vertical nudge for the detected fields, in rendered pixels (negative = up),
// applied to both the on-page position and the stamped position. The
// signature/date lines are located by their printed text, so this only
// fine-tunes alignment with the underline.
const FIELD_Y_OFFSET_PX = -67

function todayLocal(): string {
  const d = new Date()
  const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000)
  return local.toISOString().slice(0, 10)
}

/** Render a typed name into a signature image (script font), like DocuSign/Odoo. */
function renderTypedSignature(text: string): string {
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  if (!ctx) return ''

  const font = '44px "Segoe Script", "Brush Script MT", cursive'
  ctx.font = font
  const width = Math.max(220, Math.ceil(ctx.measureText(text).width) + 48)
  canvas.width = width
  canvas.height = 110

  const draw = canvas.getContext('2d')!
  draw.clearRect(0, 0, width, 110)
  draw.font = font
  draw.fillStyle = '#0f172a'
  draw.textBaseline = 'middle'
  draw.fillText(text, 24, 55)

  return canvas.toDataURL('image/png')
}

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = reject
    img.src = src
  })
}

/**
 * Crop the signature to its ink bounds (plus a small margin) so it fills the
 * signing field instead of floating in a mostly-empty canvas. Applied to the
 * preview and the submitted image, so the stamped signature matches.
 */
async function trimSignature(dataUrl: string): Promise<string> {
  const img = await loadImage(dataUrl)
  const source = document.createElement('canvas')
  source.width = img.naturalWidth || img.width
  source.height = img.naturalHeight || img.height
  const ctx = source.getContext('2d')
  if (!ctx || source.width === 0 || source.height === 0) return dataUrl

  ctx.drawImage(img, 0, 0)
  const { width, height } = source
  const pixels = ctx.getImageData(0, 0, width, height).data

  let minX = width
  let minY = height
  let maxX = -1
  let maxY = -1
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = (y * width + x) * 4
      const ink = pixels[i + 3] > 20 && (pixels[i] < 245 || pixels[i + 1] < 245 || pixels[i + 2] < 245)
      if (!ink) continue
      if (x < minX) minX = x
      if (x > maxX) maxX = x
      if (y < minY) minY = y
      if (y > maxY) maxY = y
    }
  }

  if (maxX < 0 || maxY < 0) return dataUrl

  const pad = 6
  minX = Math.max(0, minX - pad)
  minY = Math.max(0, minY - pad)
  maxX = Math.min(width - 1, maxX + pad)
  maxY = Math.min(height - 1, maxY + pad)
  const w = maxX - minX + 1
  const h = maxY - minY + 1

  const out = document.createElement('canvas')
  out.width = w
  out.height = h
  const octx = out.getContext('2d')
  if (!octx) return dataUrl
  octx.drawImage(source, minX, minY, w, h, 0, 0, w, h)

  return out.toDataURL('image/png')
}

/**
 * Find the document's own signature lines ("Received by (Signature):" / "Date:")
 * and return their positions on the rendered page, so the fillable fields sit
 * exactly where the printed form expects them — instead of a floating box.
 */
/**
 * Match the signature label on any document type: delivery orders use
 * "Received by (Signature)", invoices use "Signature and Company Stamp",
 * etc. "Delivered by" is the company's courier, so it is never a signer field.
 */
function isSignatureLabel(text: string): boolean {
  if (text.includes('Delivered by')) return false
  return (
    text.includes('Received by') ||
    text.includes('Signature') ||
    text.includes('Signed') ||
    text.includes('Authorized')
  )
}

async function detectFields(page: any, pageNumber: number, viewport: any): Promise<FieldRect[]> {
  const content = await page.getTextContent()
  let signatureLabel: any = null
  const dates: any[] = []

  for (const item of content.items) {
    const text = (item.str || '').trim()
    if (!text) continue
    if (signatureLabel === null && isSignatureLabel(text)) signatureLabel = item
    if (text === 'Date' || text === 'Date:') dates.push(item)
  }

  if (signatureLabel === null) return []

  const pageHeightPt = viewport.height / viewport.scale
  const scale = viewport.scale

  const make = (item: any, kind: FieldKind): FieldRect => {
    const xPt = item.transform[4]
    const yFromBottom = item.transform[5]
    const labelWidth = item.width || 0
    const widthPt = Math.max(FIELD_WIDTH_PT[kind], labelWidth + 12)
    const heightPt = 32
    const topFromTopPt = pageHeightPt - (yFromBottom - 8) + FIELD_Y_OFFSET_PX / scale

    return {
      kind,
      page: pageNumber,
      x: xPt * scale,
      y: topFromTopPt * scale,
      width: widthPt * scale,
      height: heightPt * scale,
      pdf: { x: xPt, y: topFromTopPt, width: widthPt, height: heightPt },
    }
  }

  const fields: FieldRect[] = [make(signatureLabel, 'signature')]

  if (dates.length) {
    const referenceY = signatureLabel.transform[5]
    const date = dates.reduce((best, candidate) =>
      Math.abs(candidate.transform[5] - referenceY) < Math.abs(best.transform[5] - referenceY) ? candidate : best,
    )
    fields.push(make(date, 'date'))
  }

  // Customer-name box to the right of the signature, sized to the room before
  // the Date column (generous on invoices, tight on delivery orders).
  const signatureField = fields[0]
  const dateField = fields.find((field) => field.kind === 'date')
  const nameXpt = signatureField.pdf.x + signatureField.pdf.width + 8
  const nameLimit = (dateField ? dateField.pdf.x : signatureField.pdf.x + signatureField.pdf.width + 148) - 10
  const nameWidthPt = Math.max(28, Math.min(140, nameLimit - nameXpt))
  fields.push({
    kind: 'name',
    page: pageNumber,
    x: nameXpt * scale,
    y: signatureField.y,
    width: nameWidthPt * scale,
    height: signatureField.height,
    pdf: {
      x: nameXpt,
      y: signatureField.pdf.y,
      width: nameWidthPt,
      height: signatureField.pdf.height,
    },
  })

  return fields
}

export function EsignPage() {
  const { token } = useParams<{ token: string }>()
  const { toast } = useToast()

  const [method, setMethod] = useState<SignMethod>('draw')
  const [drawn, setDrawn] = useState<string | null>(null)
  const [typedName, setTypedName] = useState('')
  const [uploaded, setUploaded] = useState<string | null>(null)
  const [signerName, setSignerName] = useState('')
  const [signDate, setSignDate] = useState(todayLocal())
  const [signOpen, setSignOpen] = useState(false)
  const [uploadFile, setUploadFile] = useState<File | null>(null)

  const [pages, setPages] = useState<RenderedPage[]>([])
  const [pdfError, setPdfError] = useState(false)
  const [pdfReady, setPdfReady] = useState(false)
  const viewerRef = useRef<HTMLDivElement>(null)

  const { data, isLoading, isError } = useQuery<SignResponse>({
    queryKey: ['esign', token],
    queryFn: async () => {
      const res = await api.get(`/sign/${token}`)
      return res.data as SignResponse
    },
    enabled: !!token,
    retry: false,
  })

  const ready = Boolean(data?.open || data?.signed)

  // Render the document itself with pdf.js (crisp, at device pixel ratio) and
  // locate the signature lines so the fillable fields sit on the document.
  useEffect(() => {
    if (!ready || !token) return
    let cancelled = false

    ;(async () => {
      const response = await fetch(`/api/v1/sign/${token}/pdf`)
      if (!response.ok) throw new Error('pdf-fetch-failed')

      const buffer = await response.arrayBuffer()
      const pdf = await pdfjsLib.getDocument({ data: buffer }).promise

      const dpr = Math.min(window.devicePixelRatio || 1, 2)
      const logicalWidth = Math.min(viewerRef.current?.clientWidth || 820, 820)
      const rendered: RenderedPage[] = []

      for (let pageNo = 1; pageNo <= pdf.numPages; pageNo++) {
        const page = await pdf.getPage(pageNo)
        const base = page.getViewport({ scale: 1 })
        const logicalScale = logicalWidth / base.width
        const vpLogical = page.getViewport({ scale: logicalScale })
        const vpRender = page.getViewport({ scale: logicalScale * dpr })

        const canvas = document.createElement('canvas')
        canvas.width = vpRender.width
        canvas.height = vpRender.height
        await page.render({ canvasContext: canvas.getContext('2d')!, viewport: vpRender }).promise

        const fields = await detectFields(page, pageNo, vpLogical)
        rendered.push({
          url: canvas.toDataURL('image/png'),
          width: vpLogical.width,
          height: vpLogical.height,
          fields,
        })
      }

      if (!cancelled) {
        setPages(rendered)
        setPdfReady(true)
      }
    })().catch(() => {
      if (!cancelled) setPdfError(true)
    })

    return () => {
      cancelled = true
    }
  }, [ready, token])

  const rawSignature = useMemo(() => {
    if (method === 'draw') return drawn
    if (method === 'upload') return uploaded
    return typedName.trim() ? renderTypedSignature(typedName.trim()) : null
  }, [method, drawn, uploaded, typedName])

  const [signature, setSignature] = useState<string | null>(null)

  // Trimmed to its ink bounds so it fills the signing field (preview and PDF alike).
  useEffect(() => {
    if (!rawSignature) {
      setSignature(null)
      return
    }
    let cancelled = false
    trimSignature(rawSignature)
      .then((trimmed) => {
        if (!cancelled) setSignature(trimmed)
      })
      .catch(() => {
        if (!cancelled) setSignature(rawSignature)
      })

    return () => {
      cancelled = true
    }
  }, [rawSignature])

  const hasSignatureField = pages.some((page) => page.fields.some((f) => f.kind === 'signature'))

  const submit = useMutation({
    mutationFn: async () => {
      if (!signature) throw new Error('Please add a signature first.')
      const fields = pages
        .flatMap((page) => page.fields)
        .map((field) => ({
          kind: field.kind,
          page: field.page,
          x: field.pdf.x,
          y: field.pdf.y,
          width: field.pdf.width,
          height: field.pdf.height,
        }))
      const res = await api.post(`/sign/${token}`, {
        signature,
        method,
        signer_name: signerName.trim() || undefined,
        date: signDate || undefined,
        fields: fields.length ? fields : undefined,
      })
      return res.data
    },
    onSuccess: () => {
      toast({ title: 'Signed', description: 'Thank you — your signature has been recorded.' })
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not record your signature',
        description: err?.response?.data?.error || err?.message || 'Please try again.',
      })
    },
  })

  const onFilePicked = (file?: File) => {
    if (!file) return
    if (!file.type.startsWith('image/')) {
      toast({ variant: 'destructive', title: 'Please choose an image of your signature.' })
      return
    }
    const reader = new FileReader()
    reader.onload = () => setUploaded(String(reader.result))
    reader.readAsDataURL(file)
  }

  const uploadCopy = useMutation({
    mutationFn: async () => {
      if (!uploadFile) throw new Error('Please choose the signed document first.')
      const form = new FormData()
      form.append('file', uploadFile)
      if (signerName.trim()) form.append('signer_name', signerName.trim())
      const res = await api.post(`/sign/${token}/upload`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return res.data
    },
    onSuccess: () => {
      toast({ title: 'Signed copy received', description: 'Thank you — your signed document has been uploaded.' })
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not upload the signed copy',
        description: err?.response?.data?.error || err?.message || 'Please try again.',
      })
    },
  })

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <Icon icon="eos-icons:loading" className="h-10 w-10 animate-spin text-primary" />
      </div>
    )
  }

  if (isError || !data) {
    return <CenterCard icon="solar:close-circle-bold" tone="text-destructive" text="This signing link is invalid or has expired." />
  }

  const label = DOCUMENT_LABEL[data.request.document_type] ?? 'Document'
  const number = data.document.number ?? data.request.document_number ?? ''

  if (data.signed || submit.isSuccess || uploadCopy.isSuccess) {
    const uploaded = uploadCopy.isSuccess
    return (
      <CenterCard
        icon="solar:check-circle-bold-duotone"
        tone="text-emerald-600"
        title={uploaded ? 'Signed copy received' : 'Signed'}
        text={
          uploaded
            ? `Thank you — your signed copy of ${label} ${number} has been received.`
            : `${label} ${number} has been signed${data.request.signed_at ? ` on ${new Date(data.request.signed_at).toLocaleString()}` : ''}.`
        }
      >
        <Button asChild className="mt-6">
          <a href={`/api/v1/sign/${token}/pdf`} target="_blank" rel="noopener noreferrer">
            View document
          </a>
        </Button>
      </CenterCard>
    )
  }

  if (data.expired || !data.open) {
    return <CenterCard icon="solar:danger-triangle-bold-duotone" tone="text-amber-500" text="This signing link has expired." />
  }

  const abs = (rect: FieldRect): React.CSSProperties => ({
    position: 'absolute',
    left: rect.x,
    top: rect.y,
    width: rect.width,
    height: rect.height,
  })

  return (
    <div className="min-h-screen bg-slate-100 py-8">
      <div className="mx-auto max-w-4xl space-y-4 px-4">
        <div className="flex items-center justify-between rounded-xl border bg-card px-4 py-3">
          <div className="flex items-center gap-2">
            <Icon icon="solar:pen-new-square-bold-duotone" className="h-5 w-5 text-primary" />
            <span className="font-semibold">
              Sign {label} {number}
            </span>
          </div>
          <span className="text-xs text-muted-foreground">Fill the fields directly on the document</span>
        </div>

        {pdfError && (
          <Card>
            <CardContent className="py-8 text-center text-destructive">
              The document could not be loaded. Please try again.
            </CardContent>
          </Card>
        )}

        <div ref={viewerRef} className="space-y-3">
          {pages.map((page, index) => {
            const isLast = index === pages.length - 1
            const showFallback = isLast && !hasSignatureField

            return (
              <div
                key={index}
                className="relative mx-auto overflow-hidden rounded-md border bg-white shadow"
                style={{ width: page.width, height: page.height }}
              >
                <img src={page.url} alt={`page ${index + 1}`} className="block h-full w-full" />

                {page.fields.map((field, fieldIndex) => {
                  if (field.kind === 'signature') {
                    return (
                      <button
                        key={`sig-${fieldIndex}`}
                        type="button"
                        onClick={() => setSignOpen(true)}
                        className="absolute flex items-center justify-center rounded border-2 border-dashed border-primary/60 bg-primary/5 transition-colors hover:bg-primary/10"
                        style={abs(field)}
                      >
                        {signature ? (
                          <img src={signature} alt="Your signature" className="h-full w-full object-contain p-1" />
                        ) : (
                          <span className="flex items-center gap-2 text-sm text-primary">
                            <Icon icon="solar:pen-bold-duotone" className="h-4 w-4" />
                            Sign
                          </span>
                        )}
                      </button>
                    )
                  }

                  if (field.kind === 'name') {
                    return (
                      <input
                        key={`name-${fieldIndex}`}
                        type="text"
                        value={signerName}
                        onChange={(e) => setSignerName(e.target.value)}
                        placeholder="Name"
                        className="absolute border-b-2 border-primary/60 bg-white/60 px-1 text-sm font-medium text-slate-700 placeholder:text-slate-400 focus:border-primary focus:outline-none"
                        style={abs(field)}
                      />
                    )
                  }

                  return (
                    <input
                      key={`date-${fieldIndex}`}
                      type="date"
                      value={signDate}
                      onChange={(e) => setSignDate(e.target.value)}
                      className="absolute border-b-2 border-primary/60 bg-white/60 px-2 text-center text-sm font-medium text-slate-700 focus:border-primary focus:outline-none"
                      style={abs(field)}
                    />
                  )
                })}

                {showFallback && (
                  <button
                    type="button"
                    onClick={() => setSignOpen(true)}
                    className="absolute flex items-center justify-center rounded border-2 border-dashed border-primary/60 bg-primary/5 transition-colors hover:bg-primary/10"
                    style={{ left: '50%', top: '70%', width: '42%', height: '16%' }}
                  >
                    {signature ? (
                      <img src={signature} alt="Your signature" className="h-full w-full object-contain p-1" />
                    ) : (
                      <span className="flex items-center gap-2 text-sm text-primary">
                        <Icon icon="solar:pen-bold-duotone" className="h-4 w-4" />
                        Sign here
                      </span>
                    )}
                  </button>
                )}
              </div>
            )
          })}
        </div>

        {pdfReady && pages.length === 0 && (
          <Card>
            <CardContent className="py-8 text-center text-muted-foreground">No pages to display.</CardContent>
          </Card>
        )}

        <Card>
          <CardContent className="space-y-4 py-5">
            <Button className="w-full" disabled={!signature || submit.isPending} onClick={() => submit.mutate()}>
              {submit.isPending ? 'Signing…' : 'Sign and submit'}
            </Button>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="space-y-4 py-5">
            <div>
              <h3 className="font-medium">Prefer to sign on paper?</h3>
              <p className="text-sm text-muted-foreground">
                Download the document, sign it by hand, then upload a scan or photo of the signed copy here.
              </p>
            </div>

            <div className="flex flex-wrap items-center gap-3">
              <Button asChild variant="outline" className="h-8 rounded-full px-3 text-xs">
                <a href={`/api/v1/sign/${token}/pdf`} download={`${number || 'document'}.pdf`}>
                  <Icon icon="solar:download-bold-duotone" className="mr-1.5 h-4 w-4" />
                  Download document
                </a>
              </Button>

              <input
                type="file"
                accept="application/pdf,image/*"
                onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)}
                className="block text-sm text-muted-foreground file:mr-3 file:rounded-full file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-white"
              />

              <Button
                className="h-8 rounded-full px-3 text-xs"
                disabled={!uploadFile || uploadCopy.isPending}
                onClick={() => uploadCopy.mutate()}
              >
                {uploadCopy.isPending ? 'Uploading…' : 'Upload signed copy'}
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>

      <Dialog open={signOpen} onOpenChange={setSignOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Your signature</DialogTitle>
          </DialogHeader>

          <div className="flex gap-2">
            {(['draw', 'type', 'upload'] as SignMethod[]).map((option) => (
              <Button
                key={option}
                type="button"
                variant={method === option ? 'default' : 'outline'}
                className="h-8 rounded-full px-3 text-xs"
                onClick={() => setMethod(option)}
              >
                {option === 'draw' ? 'Draw' : option === 'type' ? 'Type' : 'Upload'}
              </Button>
            ))}
          </div>

          {method === 'draw' && (
            <SignaturePad
              label="Draw your signature"
              value={drawn}
              onChange={setDrawn}
              hint="Sign with your mouse, finger or stylus"
            />
          )}

          {method === 'type' && (
            <div className="space-y-2">
              <Label htmlFor="esign-typed">Type your full name</Label>
              <Input
                id="esign-typed"
                value={typedName}
                onChange={(e) => setTypedName(e.target.value)}
                placeholder="Jane Doe"
              />
              {typedName.trim() && (
                <div className="rounded-md border bg-white p-4">
                  <img src={renderTypedSignature(typedName.trim())} alt="Signature preview" className="max-h-20" />
                </div>
              )}
            </div>
          )}

          {method === 'upload' && (
            <div className="space-y-2">
              <Label>Upload an image of your signature</Label>
              <input
                type="file"
                accept="image/*"
                onChange={(e) => onFilePicked(e.target.files?.[0])}
                className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-full file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-white"
              />
              {uploaded && <img src={uploaded} alt="Signature preview" className="max-h-20 rounded-md border bg-white p-2" />}
            </div>
          )}

          <Separator />

          <DialogFooter>
            <Button variant="outline" onClick={() => setSignOpen(false)}>Cancel</Button>
            <Button onClick={() => setSignOpen(false)} disabled={!signature}>
              Place on document
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function CenterCard({
  icon,
  tone,
  title,
  text,
  children,
}: {
  icon: string
  tone: string
  title?: string
  text: string
  children?: React.ReactNode
}) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
      <Card className="w-full max-w-md text-center">
        <CardContent className="py-10">
          <Icon icon={icon} className={`mx-auto h-12 w-12 ${tone}`} />
          {title && <h1 className="mt-4 text-lg font-semibold">{title}</h1>}
          <p className="mt-1 text-muted-foreground">{text}</p>
          {children}
        </CardContent>
      </Card>
    </div>
  )
}

export default EsignPage
