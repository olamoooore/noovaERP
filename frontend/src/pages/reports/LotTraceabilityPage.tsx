import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { formatDate, getApiErrorMessage } from '@/lib/utils'
import { traceabilityParams, type TraceType } from '@/lib/traceability'

/**
 * Issue #583 [SALES-ODOO] — lot/serial traceability report.
 *
 * Enter a lot or serial and follow it upstream (supplier receipt) and
 * downstream (stock moves → delivery order → hospital → medical case →
 * invoice). Patient names are masked by the backend unless the user holds
 * medical_cases.phi.view (#567) — the UI only displays what it is given.
 */

interface TraceEvent {
  segment: string
  event: string
  occurred_at: string | null
  reference_type: string
  reference_id: number
  reference: string | null
  title: string
  legal_entity_id: number | null
  details: Record<string, any>
}

interface TraceResult {
  query: { type: TraceType; value: string }
  phi_masked: boolean
  timeline: TraceEvent[]
  gaps: string[]
}

const SEGMENT_META: Record<string, { label: string; icon: string; className: string }> = {
  supplier_receipt: {
    label: 'Supplier receipt',
    icon: 'solar:box-bold-duotone',
    className: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
  },
  stock_move: {
    label: 'Stock move',
    icon: 'solar:transfer-horizontal-bold-duotone',
    className: 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300',
  },
  delivery: {
    label: 'Delivery / customer',
    icon: 'solar:delivery-bold-duotone',
    className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
  },
  medical_case: {
    label: 'Medical case',
    icon: 'solar:medical-kit-bold-duotone',
    className: 'bg-violet-100 text-violet-800 dark:bg-violet-950/60 dark:text-violet-300',
  },
  invoice: {
    label: 'Invoice',
    icon: 'solar:bill-list-bold-duotone',
    className: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
  },
}

const segmentMeta = (segment: string) =>
  SEGMENT_META[segment] ?? {
    label: segment.replace(/_/g, ' '),
    icon: 'solar:document-bold-duotone',
    className: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
  }

const detailRows = (event: TraceEvent): Array<[string, string]> => {
  const details = event.details ?? {}
  const rows: Array<[string, string]> = []

  const push = (label: string, value: unknown) => {
    if (value === null || value === undefined || value === '') return
    rows.push([label, String(value)])
  }

  push('Product', details.product_name || details.sku)
  push('Quantity', details.quantity ?? details.total)
  push('Warehouse', details.warehouse)
  push('Supplier', details.supplier_name || details.goods_receipt?.supplier_name)
  push('Purchase order', details.goods_receipt?.po_number)
  push('Customer', details.customer_name)
  push('Lot', details.lot_number || details.batch_number)
  push('Serial', details.serial_number)
  push('Case no.', details.case_no)
  push('Patient', details.patient_name)
  push('Status', details.status)
  push('Reference', details.reference ?? event.reference)

  return rows
}

export default function LotTraceabilityPage() {
  const [type, setType] = useState<TraceType>('lot')
  const [value, setValue] = useState('')
  const [submitted, setSubmitted] = useState<{ type: TraceType; value: string } | null>(null)
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['lot-traceability', submitted?.type, submitted?.value],
    queryFn: async () => {
      const response = await api.get('/reports/traceability', { params: traceabilityParams(submitted) })
      return response.data?.data as TraceResult
    },
    enabled: submitted !== null,
  })

  const search = (event?: React.FormEvent) => {
    event?.preventDefault()
    const trimmed = value.trim()
    if (!trimmed) return
    setSubmitted({ type, value: trimmed })
  }

  const exportCsv = async () => {
    if (!submitted) return
    setExporting(true)
    setExportError(null)

    try {
      const response = await api.get('/reports/traceability/export', {
        params: traceabilityParams(submitted),
        responseType: 'blob',
      })
      const blob = new Blob([response.data], { type: 'text/csv;charset=utf-8' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `traceability-${submitted.type}-${submitted.value.replace(/[^A-Za-z0-9._-]+/g, '-')}.csv`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
    } catch (err) {
      setExportError(getApiErrorMessage(err, 'The traceability export could not be generated.'))
    } finally {
      setExporting(false)
    }
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Lot &amp; Serial Traceability</h1>
          <p className="text-sm text-muted-foreground">
            Recall trace for a lot or serial: supplier receipt → stock moves → delivery order →
            hospital → medical case → invoice.
          </p>
        </div>
        <Button variant="outline" onClick={exportCsv} disabled={!submitted || isLoading || exporting}>
          <Icon icon="solar:download-minimalistic-bold-duotone" className="mr-2 h-4 w-4" />
          {exporting ? 'Exporting…' : 'Export CSV'}
        </Button>
      </div>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Trace a lot or serial</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={search} className="flex flex-wrap items-end gap-3">
            <div className="w-40 space-y-2">
              <Label htmlFor="traceability-type">Search by</Label>
              <Select value={type} onValueChange={(next) => setType(next as TraceType)}>
                <SelectTrigger id="traceability-type" aria-label="Search by">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="lot">Lot / batch</SelectItem>
                  <SelectItem value="serial">Serial number</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="min-w-64 flex-1 space-y-2">
              <Label htmlFor="traceability-value">
                {type === 'lot' ? 'Lot / batch number' : 'Serial number'}
              </Label>
              <Input
                id="traceability-value"
                value={value}
                onChange={(event) => setValue(event.target.value)}
                placeholder={type === 'lot' ? 'e.g. LOT-2026-001' : 'e.g. SN-000001'}
              />
            </div>
            <Button type="submit" disabled={!value.trim()}>
              <Icon icon="solar:magnifer-bold-duotone" className="mr-2 h-4 w-4" />
              Trace
            </Button>
          </form>
        </CardContent>
      </Card>

      {error && (
        <Card>
          <CardContent className="py-6 text-sm text-destructive">
            {getApiErrorMessage(error, 'The traceability report could not be generated.')}
          </CardContent>
        </Card>
      )}

      {exportError && (
        <Card>
          <CardContent className="py-4 text-sm text-destructive">{exportError}</CardContent>
        </Card>
      )}

      {isLoading && (
        <div className="py-10 text-center text-sm text-muted-foreground">Tracing {value}…</div>
      )}

      {data && !isLoading && (
        <>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
              <CardTitle className="text-base">
                {data.query.type === 'lot' ? 'Lot' : 'Serial'}: {data.query.value}
              </CardTitle>
              {data.phi_masked ? (
                <Badge variant="outline" className="border-amber-500 text-amber-700 dark:text-amber-300">
                  <Icon icon="solar:shield-keyhole-bold-duotone" className="mr-1 h-3.5 w-3.5" />
                  Patient names hidden
                </Badge>
              ) : (
                <Badge variant="outline" className="border-emerald-500 text-emerald-700 dark:text-emerald-300">
                  <Icon icon="solar:eye-bold-duotone" className="mr-1 h-3.5 w-3.5" />
                  Patient names visible
                </Badge>
              )}
            </CardHeader>
            <CardContent>
              {data.timeline.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">
                  No traceability records found for this {data.query.type}.
                </p>
              ) : (
                <ol className="space-y-4">
                  {data.timeline.map((event, index) => {
                    const meta = segmentMeta(event.segment)
                    return (
                      <li key={`${event.segment}-${event.event}-${event.reference_id}-${index}`} className="flex gap-3">
                        <div className="flex flex-col items-center">
                          <span className={`flex h-8 w-8 items-center justify-center rounded-full ${meta.className}`}>
                            <Icon icon={meta.icon} className="h-4 w-4" />
                          </span>
                          {index < data.timeline.length - 1 && <span className="mt-1 w-px flex-1 bg-border" />}
                        </div>
                        <div className="flex-1 pb-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{event.title}</span>
                            <Badge variant="outline">{meta.label}</Badge>
                            <span className="text-xs text-muted-foreground">
                              {event.occurred_at ? formatDate(event.occurred_at) : '—'}
                            </span>
                          </div>
                          <dl className="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            {detailRows(event).map(([label, detail]) => (
                              <div key={label} className="flex gap-2">
                                <dt className="text-muted-foreground">{label}:</dt>
                                <dd className="truncate">{detail}</dd>
                              </div>
                            ))}
                          </dl>
                          {event.legal_entity_id != null && (
                            <p className="mt-1 text-xs text-muted-foreground">
                              Legal entity #{event.legal_entity_id}
                            </p>
                          )}
                        </div>
                      </li>
                    )
                  })}
                </ol>
              )}
            </CardContent>
          </Card>

          {data.gaps.length > 0 && (
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-base">Traceability gaps</CardTitle>
              </CardHeader>
              <CardContent>
                <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                  {data.gaps.map((gap) => (
                    <li key={gap}>{gap}</li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          )}
        </>
      )}
    </div>
  )
}
