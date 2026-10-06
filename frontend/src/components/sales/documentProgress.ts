/**
 * Issue #688 (INV-UX2): shapes of the server-computed delivery / invoicing
 * progress (`App\Services\SalesDocumentProgress`) and the presentation
 * helpers the chips share.
 */

export interface LineDeliveryProgress {
  quantity: number
  delivered_quantity: number
}

export interface LineInvoicingProgress {
  quantity: number
  invoiced_quantity: number
}

export type InvoiceDeliveryStatus = 'not_tracked' | 'not_delivered' | 'partially_delivered' | 'fully_delivered'

export type DeliveryOrderInvoicingStatus = 'nothing_to_invoice' | 'not_invoiced' | 'partially_invoiced' | 'fully_invoiced'

export interface InvoiceDeliveryProgress {
  status: InvoiceDeliveryStatus
  quantity: number
  delivered_quantity: number
  lines?: Record<string, LineDeliveryProgress>
  delivery_orders?: Array<{ id: number; delivery_order_number: string | null; status: string | null }>
}

export interface DeliveryOrderInvoicingProgress {
  status: DeliveryOrderInvoicingStatus
  quantity: number
  invoiced_quantity: number
  lines?: Record<string, LineInvoicingProgress>
  invoices?: Array<{ id: number; invoice_number: string | null; status: string | null; lifecycle_status: string | null }>
}

const EPSILON = 0.0001

export const formatProgressQty = (value: number | string | null | undefined) =>
  Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 })

/** Green when complete, amber when partly done, slate when nothing yet. */
export function progressClassName(done: number, total: number): string {
  if (total > EPSILON && done + EPSILON >= total) return 'bg-green-100 text-green-800'
  if (done > EPSILON) return 'bg-amber-100 text-amber-800'
  return 'bg-slate-100 text-slate-700'
}
