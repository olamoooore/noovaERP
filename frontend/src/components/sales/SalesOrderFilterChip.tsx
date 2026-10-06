import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'

interface SalesOrderFilterChipProps {
  salesOrderId: string
  onClear: () => void
}

/** #403: removable "Sales order SO-xxx" chip shown while a list is filtered by order. */
export function SalesOrderFilterChip({ salesOrderId, onClear }: SalesOrderFilterChipProps) {
  const { data: orderNumber } = useQuery({
    queryKey: ['sales-order-filter-label', salesOrderId],
    queryFn: async () => {
      const res = await api.get(`/sales/orders/${salesOrderId}`)
      const order = res.data?.data ?? res.data
      return (order?.sales_order_number as string | undefined) ?? null
    },
    enabled: salesOrderId !== '',
    retry: false,
    staleTime: 5 * 60 * 1000,
  })

  if (!salesOrderId) return null

  const label = orderNumber || `#${salesOrderId}`

  return (
    <span
      data-testid="sales-order-filter-chip"
      className="inline-flex h-8 items-center gap-1.5 rounded-full border border-primary/40 bg-primary/10 pl-3 pr-1.5 text-xs font-medium text-primary"
    >
      <Icon icon="solar:cart-large-2-bold-duotone" className="h-3.5 w-3.5" />
      Sales order {label}
      <button
        type="button"
        data-testid="sales-order-filter-chip-remove"
        aria-label={`Remove sales order ${label} filter`}
        onClick={onClear}
        className="ml-0.5 rounded-full p-0.5 hover:bg-primary/20"
      >
        <Icon icon="solar:close-circle-bold" className="h-3.5 w-3.5" />
      </button>
    </span>
  )
}
