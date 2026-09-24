import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { ScrollArea } from '@/components/ui/scroll-area'
import api from '@/lib/api'
import { linkableRefs, refLabel, refRoute, resolveNotificationTarget } from '@/lib/notificationLinks'

export interface NotificationItem {
  id: string
  event: string
  channel: string
  subject: string
  body: string
  data: Record<string, any>
  status: 'pending' | 'sent' | 'failed' | 'skipped'
  error: string | null
  sent_at: string | null
  read_at: string | null
  created_at: string | null
}

const formatRelativeTime = (iso: string | null) => {
  if (!iso) return ''
  const then = new Date(iso).getTime()
  const now = Date.now()
  const diff = Math.max(0, Math.floor((now - then) / 1000))
  if (diff < 60) return `${diff}s ago`
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return `${Math.floor(diff / 86400)}d ago`
}

const eventIcon = (event: string) => {
  if (event.startsWith('approval.requested')) return 'solar:clipboard-check-bold-duotone text-orange-500'
  if (event.startsWith('approval.approved')) return 'solar:check-circle-bold-duotone text-green-600'
  if (event.startsWith('approval.rejected')) return 'solar:close-circle-bold-duotone text-red-600'
  if (event.startsWith('approval.escalated')) return 'solar:arrow-up-bold-duotone text-orange-600'
  if (event.startsWith('exception')) return 'solar:danger-triangle-bold-duotone text-red-500'
  return 'solar:bell-bold-duotone text-primary'
}

export function NotificationBell() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)

  const { data: countData } = useQuery({
    queryKey: ['notifications', 'unread-count'],
    queryFn: async () => {
      const res = await api.get('/notifications/unread-count')
      return res.data as { unread: number }
    },
    refetchInterval: 30000,
  })

  const { data: listData } = useQuery({
    queryKey: ['notifications', 'list'],
    queryFn: async () => {
      const res = await api.get('/notifications', { params: { limit: 20 } })
      return res.data as { data: NotificationItem[]; count: number }
    },
    enabled: open,
    refetchInterval: 60000,
  })

  const markReadMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.post(`/notifications/${id}/read`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  const markAllReadMutation = useMutation({
    mutationFn: async () => {
      await api.post('/notifications/mark-all-read')
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/notifications/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  const openNotificationTarget = (item: NotificationItem) => {
    const target = resolveNotificationTarget(item.data)
    if (!target) return
    setOpen(false)
    navigate(target.route)
  }

  const handleItemClick = (item: NotificationItem) => {
    if (!item.read_at) {
      markReadMutation.mutate(item.id)
    }
    openNotificationTarget(item)
  }

  const unread = countData?.unread ?? 0
  const items = listData?.data ?? []

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="relative h-10 w-10 rounded-full border border-border/70 bg-background shadow-sm transition-all hover:border-primary/40 hover:bg-accent hover:shadow-md"
          aria-label="Notifications"
        >
          <Icon icon="solar:bell-bing-bold-duotone" className="h-5 w-5" />
          {unread > 0 ? (
            <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-none text-white shadow-sm ring-2 ring-background">
              {unread > 99 ? '99+' : unread}
            </span>
          ) : (
            <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-red-500 shadow-sm ring-2 ring-background" />
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-96 p-0">
        <div className="flex items-center justify-between border-b px-4 py-3">
          <div className="font-semibold">Notifications</div>
          {unread > 0 && (
            <Button
              variant="ghost"
              size="sm"
              className="text-xs"
              onClick={() => markAllReadMutation.mutate()}
              disabled={markAllReadMutation.isPending}
            >
              Mark all read
            </Button>
          )}
        </div>
        <ScrollArea className="max-h-[420px]">
          {items.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-sm text-muted-foreground">
              <Icon icon="solar:bell-off-bold-duotone" className="mb-2 h-10 w-10 opacity-40" />
              No notifications yet
            </div>
          ) : (
            <div className="divide-y">
              {items.map((item) => (
                <div
                  key={item.id}
                  className={`group flex cursor-pointer gap-3 px-4 py-3 transition-colors hover:bg-muted/40 ${
                    !item.read_at ? 'bg-primary/5' : ''
                  }`}
                  onClick={() => handleItemClick(item)}
                >
                  <Icon icon={eventIcon(item.event)} className="mt-0.5 h-5 w-5 shrink-0" />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                      <div className="truncate text-sm font-medium">{item.subject}</div>
                      {!item.read_at && (
                        <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-primary" />
                      )}
                    </div>
                    <div className="line-clamp-2 text-xs text-muted-foreground">{item.body}</div>
                    {(() => {
                      const refs = linkableRefs(item.data)
                      if (refs.length === 0) return null
                      return (
                        <div className="mt-1 flex flex-wrap gap-1">
                          {refs.map((ref) => (
                            <button
                              key={`${ref.type}:${ref.id}`}
                              onClick={(e) => {
                                e.stopPropagation()
                                if (!item.read_at) markReadMutation.mutate(item.id)
                                setOpen(false)
                                const route = refRoute(ref)
                                if (route) navigate(route)
                              }}
                              className="rounded-full border px-1.5 py-0.5 text-[10px] text-muted-foreground transition-colors hover:border-primary/40 hover:text-primary"
                            >
                              {refLabel(ref)}
                            </button>
                          ))}
                        </div>
                      )
                    })()}
                    <div className="mt-1 flex items-center gap-2 text-[10px] text-muted-foreground">
                      <span>{formatRelativeTime(item.created_at)}</span>
                      <Badge variant="outline" className="h-4 px-1 text-[9px]">
                        {item.event}
                      </Badge>
                      <button
                        className="ml-auto opacity-0 transition-opacity hover:text-destructive group-hover:opacity-100"
                        onClick={(e) => {
                          e.stopPropagation()
                          deleteMutation.mutate(item.id)
                        }}
                      >
                        <Icon icon="solar:trash-bin-trash-bold" className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </ScrollArea>
        <div className="border-t px-4 py-2">
          <Button
            variant="ghost"
            size="sm"
            className="w-full justify-center text-xs"
            onClick={() => {
              setOpen(false)
              navigate('/notifications')
            }}
          >
            View all notifications
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  )
}
