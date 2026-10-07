import api from '@/lib/api'

/**
 * Issue #365: the Sales cockpit's action queues
 * (GET /sales/dashboard/action-queues). Each queue's `link` opens the matching
 * list with `?action_queue=<key>[&mine=1]`; the list pages forward those params
 * to their API, which applies the very same query the tile counted.
 */
export interface SalesActionQueueItem {
  id: number
  number: string
  customer: string | null
  date: string | null
  amount: number | null
  currency_code: string | null
  link: string
}

export interface SalesActionQueue {
  key: string
  label: string
  document: string
  count: number
  link: string
  secondary_link?: string
  items: SalesActionQueueItem[]
}

/**
 * Issue #410: the caller's own scheduled activities that are due (overdue or
 * today) on documents they may open. Always the caller's own work, so "Only my
 * documents" does not apply; the link opens My activities.
 */
export interface SalesActivitiesDue {
  label: string
  count: number
  overdue: number
  link: string
  items: SalesActionQueueItem[]
}

export interface SalesActionQueuesResponse {
  mine: boolean
  expiring_window_days: number
  queues: SalesActionQueue[]
  activities?: SalesActivitiesDue
}

export async function fetchSalesActionQueues(mine: boolean): Promise<SalesActionQueuesResponse> {
  const res = await api.get('/sales/dashboard/action-queues', { params: mine ? { mine: 1 } : {} })
  return res.data?.data ?? res.data
}

/** Chip labels for a list opened from a cockpit tile. */
export const SALES_ACTION_QUEUE_LABELS: Record<string, string> = {
  quotes_to_send: 'Quotes to send',
  quotes_expiring: 'Quotes expiring in 7 days',
  orders_to_deliver: 'Orders to deliver',
  orders_to_invoice: 'Orders to invoice',
  deliveries_to_invoice: 'Deliveries to invoice',
  invoices_to_send: 'Invoices to send',
  overdue_invoices: 'Overdue invoices',
}
