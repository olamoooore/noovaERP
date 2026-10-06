/**
 * #399 (SU1): one frontend definition of the Sales Order lifecycle, shared by
 * the order list and the order detail page so the two can no longer drift.
 *
 * Document statuses mirror the backend transition table
 * (`Modules\Sales\Services\OrderLifecycleService::TRANSITIONS`):
 * draft -> confirmed -> allocated -> fulfilled -> invoiced, plus cancelled.
 *
 * Delivery and invoice progress are kept separate from the document status
 * (#371 / #392 design): invoice progress comes from the API's appended
 * `invoice_status`; delivery progress is derived from the line quantities.
 */

export const SALES_ORDER_STATUSES = ['draft', 'confirmed', 'allocated', 'fulfilled', 'invoiced', 'cancelled'] as const

export type SalesOrderStatus = (typeof SALES_ORDER_STATUSES)[number]

export interface StatusPresentation {
  label: string
  className: string
}

const FALLBACK_CLASS = 'bg-gray-100 text-gray-800'

export const SALES_ORDER_STATUS_META: Record<SalesOrderStatus, StatusPresentation> = {
  draft: { label: 'Draft', className: 'bg-slate-100 text-slate-800' },
  confirmed: { label: 'Confirmed', className: 'bg-blue-100 text-blue-800' },
  allocated: { label: 'Allocated', className: 'bg-yellow-100 text-yellow-800' },
  fulfilled: { label: 'Fulfilled', className: 'bg-green-100 text-green-800' },
  invoiced: { label: 'Invoiced', className: 'bg-purple-100 text-purple-800' },
  cancelled: { label: 'Cancelled', className: 'bg-red-100 text-red-800' },
}

/** "partially_delivered" -> "Partially delivered" for any status we do not know. */
function humanize(value: string): string {
  const text = value.replace(/_/g, ' ').trim()
  return text ? text.charAt(0).toUpperCase() + text.slice(1) : 'Unknown'
}

export function getSalesOrderStatusMeta(status: string | null | undefined): StatusPresentation {
  const key = String(status ?? '') as SalesOrderStatus
  return SALES_ORDER_STATUS_META[key] ?? { label: humanize(String(status ?? '')), className: FALLBACK_CLASS }
}

export type SalesOrderDeliveryStatus = 'nothing_to_deliver' | 'to_deliver' | 'partially_delivered' | 'fully_delivered'

export const SALES_ORDER_DELIVERY_STATUS_META: Record<SalesOrderDeliveryStatus, StatusPresentation> = {
  nothing_to_deliver: { label: 'Nothing to deliver', className: 'bg-gray-100 text-gray-800' },
  to_deliver: { label: 'To deliver', className: 'bg-orange-100 text-orange-800' },
  partially_delivered: { label: 'Partially delivered', className: 'bg-yellow-100 text-yellow-800' },
  fully_delivered: { label: 'Fully delivered', className: 'bg-green-100 text-green-800' },
}

/** Invoice progress values returned by `SalesOrder::invoiceStatus()` (#371). */
export type SalesOrderInvoiceStatus = 'nothing_to_invoice' | 'to_invoice' | 'partially_invoiced' | 'fully_invoiced'

export const SALES_ORDER_INVOICE_STATUS_META: Record<SalesOrderInvoiceStatus, StatusPresentation> = {
  nothing_to_invoice: { label: 'Nothing to invoice', className: 'bg-gray-100 text-gray-800' },
  to_invoice: { label: 'To invoice', className: 'bg-orange-100 text-orange-800' },
  partially_invoiced: { label: 'Partially invoiced', className: 'bg-yellow-100 text-yellow-800' },
  fully_invoiced: { label: 'Fully invoiced', className: 'bg-green-100 text-green-800' },
}

export function getSalesOrderInvoiceStatusMeta(status: string | null | undefined): StatusPresentation | null {
  if (!status) return null
  return (
    SALES_ORDER_INVOICE_STATUS_META[status as SalesOrderInvoiceStatus] ?? {
      label: humanize(status),
      className: FALLBACK_CLASS,
    }
  )
}

export interface DeliverableLine {
  line_type?: string | null
  quantity: number | string
  quantity_delivered?: number | string | null
  quantity_cancelled?: number | string | null
}

const num = (value: number | string | null | undefined) => {
  const parsed = Number(value ?? 0)
  return Number.isFinite(parsed) ? parsed : 0
}

/**
 * Delivery progress over product lines: ordered minus cancelled quantity is
 * what has to ship; delivered quantity is what has shipped.
 */
export function getSalesOrderDeliveryStatus(lines: DeliverableLine[]): SalesOrderDeliveryStatus {
  let hasDeliverable = false
  let hasDelivered = false
  let hasRemaining = false

  for (const line of lines) {
    if ((line.line_type ?? 'product') !== 'product') continue
    const deliverable = Math.max(num(line.quantity) - num(line.quantity_cancelled), 0)
    if (deliverable <= 0.0001) continue

    hasDeliverable = true
    const delivered = num(line.quantity_delivered)
    if (delivered > 0.0001) hasDelivered = true
    if (delivered + 0.0001 < deliverable) hasRemaining = true
  }

  if (!hasDeliverable) return 'nothing_to_deliver'
  if (!hasDelivered) return 'to_deliver'
  return hasRemaining ? 'partially_delivered' : 'fully_delivered'
}
