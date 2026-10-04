import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import api from '@/lib/api'
import { formatDateTime } from '@/lib/utils'
import {
  activitySubjectLabel,
  activitySubjectRoute,
  activityTypeMeta,
  type MyActivitiesResponse,
  type MyActivity,
} from '@/lib/activityLinks'

function ActivityRow({
  activity,
  busy,
  onComplete,
  onSnooze,
}: {
  activity: MyActivity
  busy: boolean
  onComplete: (id: number) => void
  onSnooze: (id: number, days: number) => void
}) {
  const typeMeta = activityTypeMeta(activity.activity_type)
  const route = activitySubjectRoute(activity)
  const label = activitySubjectLabel(activity)

  return (
    <div className="flex items-start gap-3 px-4 py-3">
      <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
        <Icon icon={typeMeta.icon} className="h-4 w-4" />
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="truncate text-sm font-medium">{activity.summary}</span>
          <Badge variant="outline" className="h-5 px-1.5 text-[10px]">
            {typeMeta.label}
          </Badge>
        </div>
        <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
          {route ? (
            <Link
              to={route}
              className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
            >
              <Icon icon="solar:document-text-bold-duotone" className="h-3.5 w-3.5" />
              {label}
            </Link>
          ) : (
            <span className="inline-flex items-center gap-1">
              <Icon icon="solar:document-text-bold-duotone" className="h-3.5 w-3.5" />
              {label}
            </span>
          )}
          {activity.due_date && <span>Due {formatDateTime(activity.due_date)}</span>}
        </div>
      </div>

      <div className="flex shrink-0 items-center gap-1.5">
        <Button
          size="sm"
          variant="outline"
          disabled={busy}
          onClick={() => onComplete(activity.id)}
        >
          <Icon icon="solar:check-circle-bold-duotone" className="mr-1 h-4 w-4" />
          Mark done
        </Button>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button size="sm" variant="ghost" disabled={busy} aria-label="Snooze activity">
              <Icon icon="solar:alarm-snooze-bold-duotone" className="mr-1 h-4 w-4" />
              Snooze
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={() => onSnooze(activity.id, 1)}>Tomorrow</DropdownMenuItem>
            <DropdownMenuItem onClick={() => onSnooze(activity.id, 3)}>In 3 days</DropdownMenuItem>
            <DropdownMenuItem onClick={() => onSnooze(activity.id, 7)}>Next week</DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  )
}

export default function MyActivitiesPage() {
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['activities', 'mine'],
    queryFn: async () => {
      const res = await api.get('/activities/mine')
      return res.data.data as MyActivitiesResponse
    },
    refetchInterval: 60000,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['activities'] })

  const completeMutation = useMutation({
    mutationFn: (id: number) => api.post(`/activities/${id}/complete`),
    onSuccess: invalidate,
  })

  const snoozeMutation = useMutation({
    mutationFn: ({ id, days }: { id: number; days: number }) => {
      const due = new Date(Date.now() + days * 24 * 60 * 60 * 1000)
      due.setHours(9, 0, 0, 0)
      return api.post(`/activities/${id}/snooze`, { due_date: due.toISOString() })
    },
    onSuccess: invalidate,
  })

  const busy = completeMutation.isPending || snoozeMutation.isPending

  const groups = [
    {
      key: 'overdue',
      title: 'Overdue',
      description: 'Past their due date',
      icon: 'solar:danger-triangle-bold-duotone',
      accent: 'text-red-600',
      dot: 'bg-red-500',
      items: data?.overdue ?? [],
    },
    {
      key: 'today',
      title: 'Today',
      description: 'Due today',
      icon: 'solar:clock-circle-bold-duotone',
      accent: 'text-amber-600',
      dot: 'bg-amber-500',
      items: data?.today ?? [],
    },
    {
      key: 'upcoming',
      title: 'Upcoming',
      description: 'Due later or without a due date',
      icon: 'solar:calendar-mark-bold-duotone',
      accent: 'text-sky-600',
      dot: 'bg-sky-500',
      items: data?.upcoming ?? [],
    },
  ]

  const total = data?.total ?? 0

  return (
    <div className="container mx-auto space-y-6 py-6">
      <div>
        <h1 className="text-2xl font-bold">My Activities</h1>
        <p className="text-sm text-muted-foreground">
          Follow-ups assigned to you across quotations, sales orders, delivery orders, invoices
          and credit notes.
        </p>
      </div>

      {isLoading ? (
        <Card>
          <CardContent className="flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground">
            <Icon icon="solar:refresh-circle-bold-duotone" className="h-5 w-5 animate-spin" />
            Loading activities…
          </CardContent>
        </Card>
      ) : isError ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center gap-2 py-12 text-sm text-muted-foreground">
            <Icon icon="solar:danger-triangle-bold-duotone" className="h-8 w-8 text-red-500" />
            Could not load your activities. Try again shortly.
          </CardContent>
        </Card>
      ) : total === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center gap-2 py-12 text-sm text-muted-foreground">
            <Icon
              icon="solar:checklist-minimalistic-bold-duotone"
              className="h-10 w-10 text-green-600 opacity-70"
            />
            You have no open activities.
          </CardContent>
        </Card>
      ) : (
        groups.map((group) => (
          <Card key={group.key}>
            <div className="flex items-center justify-between border-b px-4 py-3">
              <div className="flex items-center gap-2">
                <Icon icon={group.icon} className={`h-5 w-5 ${group.accent}`} />
                <div>
                  <div className="flex items-center gap-2 text-sm font-semibold">
                    {group.title}
                    <span className={`inline-block h-2 w-2 rounded-full ${group.dot}`} />
                    <span className="text-xs font-normal text-muted-foreground">
                      {group.items.length}
                    </span>
                  </div>
                  <div className="text-xs text-muted-foreground">{group.description}</div>
                </div>
              </div>
            </div>
            <CardContent className="p-0">
              {group.items.length === 0 ? (
                <div className="px-4 py-6 text-sm text-muted-foreground">
                  Nothing {group.title.toLowerCase()}.
                </div>
              ) : (
                <div className="divide-y">
                  {group.items.map((activity) => (
                    <ActivityRow
                      key={activity.id}
                      activity={activity}
                      busy={busy}
                      onComplete={(id) => completeMutation.mutate(id)}
                      onSnooze={(id, days) => snoozeMutation.mutate({ id, days })}
                    />
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        ))
      )}
    </div>
  )
}
