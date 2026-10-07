import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'

/**
 * #553 pricelist display mode from the sales document defaults (readable by
 * every sales role; GET /settings/sales itself is OWNER/ADMIN-only). Shares
 * the cache key the document forms already use.
 */
export function useSalesPricelistDisplayMode(): string | null {
  const { data } = useQuery({
    queryKey: ['sales-settings', 'defaults'],
    queryFn: async () => {
      const res = await api.get('/settings/sales/defaults')
      return res.data?.data ?? null
    },
    staleTime: 5 * 60 * 1000,
    retry: false,
  })
  const mode = (data as { pricelist?: { display?: unknown } } | null | undefined)?.pricelist?.display
  return typeof mode === 'string' ? mode : null
}
