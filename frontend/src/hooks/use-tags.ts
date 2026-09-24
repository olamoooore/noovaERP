import { useQuery } from '@tanstack/react-query'

import api from '@/lib/api'

export interface TagOption {
  id: number
  name: string
  slug: string
  color?: string
  usage_count?: number
}

/**
 * The tenant's tag list. Cached for a minute so many filter bars on one page
 * (or repeated mounts) only fetch once per tenant session.
 */
export function useTags() {
  return useQuery({
    queryKey: ['tags'],
    queryFn: async () => {
      const res = await api.get('/tags')
      return (res.data?.data ?? []) as TagOption[]
    },
    staleTime: 60_000,
  })
}

export default useTags
