import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import api from '@/lib/api'
import type { MyActivitiesResponse } from '@/lib/activityLinks'

/**
 * Issue #637 — header badge with the number of open activities assigned to
 * the authenticated user. Polls the same query the My Activities page uses,
 * so completing or snoozing an activity updates both instantly.
 */
export function ActivitiesBadge() {
  const navigate = useNavigate()

  const { data } = useQuery({
    queryKey: ['activities', 'mine'],
    queryFn: async () => {
      const res = await api.get('/activities/mine')
      return res.data.data as MyActivitiesResponse
    },
    refetchInterval: 60000,
  })

  const total = data?.total ?? 0

  return (
    <Button
      variant="ghost"
      size="icon"
      aria-label="My activities"
      onClick={() => navigate('/activities')}
      className="relative h-10 w-10 rounded-full border border-border/70 bg-background shadow-sm transition-all hover:border-primary/40 hover:bg-accent hover:shadow-md"
    >
      <Icon icon="solar:checklist-minimalistic-bold-duotone" className="h-5 w-5" />
      {total > 0 && (
        <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold leading-none text-primary-foreground shadow-sm ring-2 ring-background">
          {total > 99 ? '99+' : total}
        </span>
      )}
    </Button>
  )
}
