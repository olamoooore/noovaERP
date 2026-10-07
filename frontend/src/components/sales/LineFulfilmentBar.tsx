import { useState } from 'react'
import { Link } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useAuthStore } from '@/store/authStore'
import { formatProgressQty } from './documentProgress'
import {
  LINE_STUCK_STAGE_LABELS,
  useSalesOrderFulfilment,
  type LineFulfilmentStage,
  type SalesOrderLineFulfilment,
} from './lineFulfilment'

export type { SalesOrderFulfilment, SalesOrderLineFulfilment } from './lineFulfilment'

/**
 * Issue #411 (DX1): per-line fulfilment chain ordered → reserved → delivered
 * → invoiced → paid. The figures come from GET /sales/orders/{id}/fulfilment
 * (`App\Services\SalesDocumentProgress::forSalesOrderLines`); this file only
 * presents them. Hovering (or focusing) a segment lists the documents behind
 * that stage.
 */

type SegmentKey = 'ordered' | 'reserved' | 'delivered' | 'invoiced' | 'paid'

const SEGMENTS: Array<{ key: SegmentKey; label: string; stage: LineFulfilmentStage | null; fill: string }> = [
  { key: 'ordered', label: 'Ordered', stage: null, fill: 'bg-slate-400' },
  { key: 'reserved', label: 'Reserved', stage: 'reserve', fill: 'bg-sky-500' },
  { key: 'delivered', label: 'Delivered', stage: 'deliver', fill: 'bg-indigo-500' },
  { key: 'invoiced', label: 'Invoiced', stage: 'invoice', fill: 'bg-amber-500' },
  { key: 'paid', label: 'Paid', stage: 'pay', fill: 'bg-emerald-500' },
]

const amountText = (value: number | string | undefined) =>
  Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

function SegmentDocuments({ line, segment }: { line: SalesOrderLineFulfilment; segment: SegmentKey }) {
  const empty = <li className="text-muted-foreground">No documents yet</li>

  if (segment === 'delivered') {
    const deliveryOrders = line.delivered.delivery_orders ?? []
    return (
      <ul className="space-y-0.5">
        {deliveryOrders.length === 0 && empty}
        {deliveryOrders.map((deliveryOrder) => (
          <li key={deliveryOrder.id}>
            <Link to={`/delivery-orders/${deliveryOrder.id}`} className="text-blue-600 hover:underline">
              {deliveryOrder.delivery_order_number || `DO #${deliveryOrder.id}`}
            </Link>{' '}
            <span className="text-muted-foreground">
              {formatProgressQty(deliveryOrder.quantity ?? 0)}
              {deliveryOrder.status ? ` · ${deliveryOrder.status}` : ''}
            </span>
          </li>
        ))}
      </ul>
    )
  }

  if (segment === 'invoiced') {
    const invoices = line.invoiced.invoices ?? []
    return (
      <ul className="space-y-0.5">
        {invoices.length === 0 && empty}
        {invoices.map((invoice) => (
          <li key={invoice.id}>
            <Link to={`/invoices/${invoice.id}`} className="text-blue-600 hover:underline">
              {invoice.invoice_number || `Invoice #${invoice.id}`}
            </Link>{' '}
            <span className="text-muted-foreground">
              {formatProgressQty(invoice.quantity ?? 0)} · {amountText(invoice.amount)}
            </span>
          </li>
        ))}
      </ul>
    )
  }

  if (segment === 'paid') {
    const payments = line.paid.payments ?? []
    return (
      <ul className="space-y-0.5">
        {payments.length === 0 && empty}
        {payments.map((payment, index) => (
          <li key={`${payment.id}-${payment.invoice_id ?? index}`}>
            {/* No payment detail page exists; the receipt opens on the invoice it settles. */}
            {payment.invoice_id ? (
              <Link to={`/invoices/${payment.invoice_id}`} className="text-blue-600 hover:underline">
                {payment.payment_number || `Payment #${payment.id}`}
              </Link>
            ) : (
              <span>{payment.payment_number || `Payment #${payment.id}`}</span>
            )}{' '}
            <span className="text-muted-foreground">{amountText(payment.applied_amount)}</span>
          </li>
        ))}
      </ul>
    )
  }

  if (segment === 'reserved' && line.delivery_tracked === false) {
    return <p className="text-muted-foreground">No stock to reserve or deliver</p>
  }

  return null
}

export function LineFulfilmentBar({ line, className }: { line: SalesOrderLineFulfilment; className?: string }) {
  const [open, setOpen] = useState<SegmentKey | null>(null)
  const ordered = Number(line.ordered.quantity) || 0

  return (
    <div className={cn('relative flex flex-col gap-1', className)} onMouseLeave={() => setOpen(null)}>
      <div role="group" aria-label="Fulfilment chain" className="flex w-40 gap-0.5">
        {SEGMENTS.map((segment) => {
          const quantity = Number(line[segment.key].quantity) || 0
          const share = ordered > 0 ? Math.min(100, Math.max(0, (quantity / ordered) * 100)) : 0
          const label =
            segment.key === 'ordered'
              ? `Ordered ${formatProgressQty(ordered)}`
              : `${segment.label} ${formatProgressQty(quantity)} of ${formatProgressQty(ordered)}`
          const stuck = segment.stage !== null && segment.stage === line.stuck_at

          return (
            <span
              key={segment.key}
              tabIndex={0}
              aria-label={label}
              title={label}
              onMouseEnter={() => setOpen(segment.key)}
              onFocus={() => setOpen(segment.key)}
              onBlur={() => setOpen(null)}
              className={cn(
                'relative h-2 flex-1 overflow-hidden rounded-sm bg-muted outline-none focus-visible:ring-2 focus-visible:ring-ring',
                stuck && 'ring-1 ring-destructive',
              )}
            >
              <span className={cn('absolute inset-y-0 left-0', segment.fill)} style={{ width: `${share}%` }} />
            </span>
          )
        })}
      </div>
      {line.stuck_at && (
        <span className="text-[11px] text-destructive">Stuck at {LINE_STUCK_STAGE_LABELS[line.stuck_at].toLowerCase()}</span>
      )}
      {open && (
        // pt-1 instead of a margin keeps the pointer inside the wrapper on the
        // way to the document links.
        <div role="tooltip" className="absolute left-0 top-full z-50 pt-1">
          <div className="min-w-[12rem] rounded-md border bg-popover px-3 py-2 text-left text-xs text-popover-foreground shadow-md">
          <p className="mb-1 font-medium">
            {SEGMENTS.find((segment) => segment.key === open)?.label} {formatProgressQty(line[open].quantity)} of{' '}
            {formatProgressQty(ordered)} · {amountText(line[open].amount)}
          </p>
          <SegmentDocuments line={line} segment={open} />
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * Issue #411: the converted order's line chain on a quotation. Shown only to
 * users who can open the order (the endpoint's sales.view gate).
 */
export function SalesOrderFulfilmentCard({ orderId }: { orderId: number | string | null | undefined }) {
  const canViewOrders = useAuthStore((state) => state.hasPermission('sales.view'))
  const { data: fulfilment } = useSalesOrderFulfilment(canViewOrders ? orderId : null)

  if (!fulfilment || fulfilment.lines.length === 0) return null

  return (
    <Card data-testid="order-fulfilment">
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2 text-base">
          Order fulfilment
          <Link to={`/sales/orders/${fulfilment.sales_order_id}`} className="text-sm font-normal text-blue-600 hover:underline">
            {fulfilment.sales_order_number || `SO #${fulfilment.sales_order_id}`}
          </Link>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <ul className="space-y-3">
          {fulfilment.lines.map((line) => (
            <li key={line.sales_order_line_id} className="flex flex-wrap items-start justify-between gap-4 text-sm">
              <span>{line.product_name || line.description || `Line ${line.sales_order_line_id}`}</span>
              <LineFulfilmentBar line={line} />
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}
