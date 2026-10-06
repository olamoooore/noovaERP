import { Link } from 'react-router-dom'
import { cn } from '@/lib/utils'
import {
  formatProgressQty,
  progressClassName,
  type DeliveryOrderInvoicingProgress,
  type InvoiceDeliveryProgress,
} from './documentProgress'

export type { DeliveryOrderInvoicingProgress, InvoiceDeliveryProgress } from './documentProgress'

/**
 * Issue #688 (INV-UX2): "Delivered 6/10" on invoices and "Invoiced 10/10" on
 * delivery orders, header and per line, with links to the counterpart
 * documents. The figures are computed server-side
 * (`App\Services\SalesDocumentProgress`) from the existing invoice / delivery
 * link data — this file only presents them.
 */

export function ProgressChip({
  label,
  done,
  total,
  className,
}: {
  label: string
  done: number
  total: number
  className?: string
}) {
  const text = `${label} ${formatProgressQty(done)}/${formatProgressQty(total)}`

  return (
    <span
      className={cn(
        'inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium',
        progressClassName(Number(done), Number(total)),
        className,
      )}
      title={text}
    >
      {text}
    </span>
  )
}

/** Header chip for an invoice; nothing when the invoice has no delivery to track. */
export function InvoiceDeliveryChip({
  progress,
  className,
}: {
  progress: InvoiceDeliveryProgress | null | undefined
  className?: string
}) {
  if (!progress || progress.status === 'not_tracked') return null

  return (
    <ProgressChip
      label="Delivered"
      done={Number(progress.delivered_quantity)}
      total={Number(progress.quantity)}
      className={className}
    />
  )
}

/** Header chip for a delivery order; nothing when it has nothing to bill. */
export function DeliveryOrderInvoicingChip({
  progress,
  className,
}: {
  progress: DeliveryOrderInvoicingProgress | null | undefined
  className?: string
}) {
  if (!progress || progress.status === 'nothing_to_invoice') return null

  return (
    <ProgressChip
      label="Invoiced"
      done={Number(progress.invoiced_quantity)}
      total={Number(progress.quantity)}
      className={className}
    />
  )
}

export function InvoiceLineDeliveryChip({
  progress,
  lineId,
}: {
  progress: InvoiceDeliveryProgress | null | undefined
  lineId: number | string | null | undefined
}) {
  const line = lineId != null ? progress?.lines?.[String(lineId)] : undefined
  if (!line) return null

  return <ProgressChip label="Delivered" done={Number(line.delivered_quantity)} total={Number(line.quantity)} />
}

export function DeliveryOrderLineInvoicingChip({
  progress,
  lineId,
}: {
  progress: DeliveryOrderInvoicingProgress | null | undefined
  lineId: number | string | null | undefined
}) {
  const line = lineId != null ? progress?.lines?.[String(lineId)] : undefined
  if (!line) return null

  return <ProgressChip label="Invoiced" done={Number(line.invoiced_quantity)} total={Number(line.quantity)} />
}

/** Header chip plus links to the delivery orders shipping this invoice. */
export function InvoiceDeliveryProgressSummary({ progress }: { progress: InvoiceDeliveryProgress | null | undefined }) {
  if (!progress || progress.status === 'not_tracked') return null

  return (
    <div className="flex flex-wrap items-center gap-2 text-xs" data-testid="invoice-delivery-progress">
      <InvoiceDeliveryChip progress={progress} />
      {(progress.delivery_orders ?? []).map((deliveryOrder) => (
        <Link
          key={deliveryOrder.id}
          to={`/delivery-orders/${deliveryOrder.id}`}
          className="text-blue-600 hover:underline"
        >
          {deliveryOrder.delivery_order_number || `DO #${deliveryOrder.id}`}
        </Link>
      ))}
    </div>
  )
}

/** Header chip plus links to the invoices billing this delivery order. */
export function DeliveryOrderInvoicingProgressSummary({
  progress,
}: {
  progress: DeliveryOrderInvoicingProgress | null | undefined
}) {
  if (!progress || progress.status === 'nothing_to_invoice') return null

  return (
    <div className="flex flex-wrap items-center gap-2 text-xs" data-testid="delivery-order-invoicing-progress">
      <DeliveryOrderInvoicingChip progress={progress} />
      {(progress.invoices ?? []).map((invoice) => (
        <Link key={invoice.id} to={`/invoices/${invoice.id}`} className="text-blue-600 hover:underline">
          {invoice.invoice_number || `Invoice #${invoice.id}`}
        </Link>
      ))}
    </div>
  )
}
