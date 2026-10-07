import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'

/**
 * Issue #411 (DX1): types, labels and the fetch for the per-line fulfilment
 * chain served by GET /sales/orders/{id}/fulfilment.
 */

export type LineFulfilmentStage = 'reserve' | 'deliver' | 'invoice' | 'pay'

/** Stages in chain order, as accepted by `GET /sales/orders?stuck_at=`. */
export const LINE_STUCK_STAGES: LineFulfilmentStage[] = ['reserve', 'deliver', 'invoice', 'pay']

export const LINE_STUCK_STAGE_LABELS: Record<LineFulfilmentStage, string> = {
  reserve: 'Reservation',
  deliver: 'Delivery',
  invoice: 'Invoicing',
  pay: 'Payment',
}

interface StageFigures {
  quantity: number | string
  amount: number | string
}

export interface SalesOrderLineFulfilment {
  sales_order_line_id: number
  product_name?: string | null
  description?: string | null
  delivery_tracked?: boolean
  stuck_at: LineFulfilmentStage | null
  ordered: StageFigures
  reserved: StageFigures
  delivered: StageFigures & {
    delivery_orders?: Array<{ id: number; delivery_order_number: string | null; status?: string | null; quantity?: number | string }>
  }
  invoiced: StageFigures & {
    invoices?: Array<{
      id: number
      invoice_number: string | null
      status?: string | null
      quantity?: number | string
      amount?: number | string
      paid_amount?: number | string
    }>
  }
  paid: StageFigures & {
    payments?: Array<{
      id: number
      payment_number: string | null
      payment_date?: string | null
      invoice_id?: number
      applied_amount?: number | string
    }>
  }
}

export interface SalesOrderFulfilment {
  sales_order_id: number
  sales_order_number?: string
  lines: SalesOrderLineFulfilment[]
}

export function isLineFulfilmentStage(value: string | null | undefined): value is LineFulfilmentStage {
  return value != null && (LINE_STUCK_STAGES as string[]).includes(value)
}

/**
 * The order's line chain, keyed under the order's own query key so the order
 * refresh after an action also refreshes the bars. Users without sales.view
 * (or out of the order's entity scope) get no data and no bar.
 */
export function useSalesOrderFulfilment(orderId: number | string | null | undefined) {
  return useQuery({
    queryKey: ['sales-order', orderId != null ? String(orderId) : orderId, 'fulfilment'],
    queryFn: async () => {
      const response = await api.get(`/sales/orders/${orderId}/fulfilment`)
      const data = response.data as Partial<SalesOrderFulfilment> | null

      return Array.isArray(data?.lines) ? (data as SalesOrderFulfilment) : null
    },
    enabled: orderId != null && orderId !== '',
    retry: false,
  })
}
