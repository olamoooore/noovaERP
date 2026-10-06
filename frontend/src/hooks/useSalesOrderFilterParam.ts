import { useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'

/**
 * #403: the Sales Order page's smart buttons open `/invoices?sales_order_id=<id>`
 * and `/delivery-orders?sales_order_id=<id>`. List pages read that param from the
 * URL (so links and reloads keep the filter) and drop it from the URL on clear.
 */
export function useSalesOrderFilterParam() {
  const [searchParams, setSearchParams] = useSearchParams()
  const raw = searchParams.get('sales_order_id')
  const salesOrderId = raw && /^\d+$/.test(raw) ? raw : ''

  const clear = useCallback(() => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      next.delete('sales_order_id')
      return next
    })
  }, [setSearchParams])

  return { salesOrderId, clear }
}
