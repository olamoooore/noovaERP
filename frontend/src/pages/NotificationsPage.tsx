import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import {Card, CardContent, CardHeader} from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {Tabs, TabsList, TabsTrigger} from '@/components/ui/tabs'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { formatDate } from '@/lib/utils'
import api from '@/lib/api'
import { linkableRefs, refLabel, refRoute, resolveNotificationTarget } from '@/lib/notificationLinks'
import type { NotificationItem } from '@/components/layout/NotificationBell'
import { notificationText } from '@/lib/notificationText'

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

const eventColor = (event: string) => {
  if (event.startsWith('approval.requested')) return 'border-l-orange-500'
  if (event.startsWith('approval.approved')) return 'border-l-green-500'
  if (event.startsWith('approval.rejected')) return 'border-l-red-500'
  if (event.startsWith('approval.escalated')) return 'border-l-orange-600'
  if (event.startsWith('exception')) return 'border-l-red-600'
  return 'border-l-primary'
}

export default function NotificationsPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [tab, setTab] = useState<'all' | 'unread' | 'approvals'>('all')
  const [eventFilter, setEventFilter] = useState<string>('all')

  const unreadOnly = tab === 'unread'
  const isApprovalOnly = tab === 'approvals'

  const { data, isLoading } = useQuery({
    queryKey: ['notifications', 'list', { unreadOnly, eventFilter }],
    queryFn: async () => {
      const params: Record<string, any> = { limit: 100 }
      if (unreadOnly) params.unread_only = 1
      if (eventFilter !== 'all') params.event = eventFilter
      const res = await api.get('/notifications', { params })
      return res.data as { data: NotificationItem[]; count: number }
    },
    refetchInterval: 30000,
  })

  const markReadMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.post(`/notifications/${id}/read`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const markAllReadMutation = useMutation({
    mutationFn: async () => {
      await api.post('/notifications/mark-all-read')
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/notifications/${id}`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const items = (data?.data ?? []).filter((item) => {
    if (isApprovalOnly) return item.event.startsWith('approval.')
    return true
  })

  const unreadCount = items.filter((i) => !i.read_at).length

  const handleClick = (item: NotificationItem) => {
    if (!item.read_at) markReadMutation.mutate(item.id)
    const target = resolveNotificationTarget(item.data)
    if (target) navigate(target.route)
  }

  return (
    <div className="container mx-auto py-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Notifications</h1>
          <p className="text-sm text-muted-foreground">
            Approval requests, system alerts, and other important events
          </p>
        </div>
        {unreadCount > 0 && (
          <Button
            variant="outline"
            onClick={() => markAllReadMutation.mutate()}
            disabled={markAllReadMutation.isPending}
          >
            <Icon icon="solar:check-read-bold-duotone" className="mr-2 h-4 w-4" />
            Mark all as read
          </Button>
        )}
      </div>

      <Card>
        <CardHeader>
          <div className="flex items-center justify-between gap-4">
            <Tabs value={tab} onValueChange={(v: any) => setTab(v)}>
              <TabsList>
                <TabsTrigger value="all">
                  All
                  {data?.count !== undefined && (
                    <Badge variant="secondary" className="ml-2 h-4 px-1.5 text-[10px]">
                      {data.count}
                    </Badge>
                  )}
                </TabsTrigger>
                <TabsTrigger value="unread">
                  Unread
                  {unreadCount > 0 && (
                    <Badge variant="destructive" className="ml-2 h-4 px-1.5 text-[10px]">
                      {unreadCount}
                    </Badge>
                  )}
                </TabsTrigger>
                <TabsTrigger value="approvals">Approvals</TabsTrigger>
              </TabsList>
            </Tabs>

            <div className="w-56">
              <Select value={eventFilter} onValueChange={setEventFilter}>
                <SelectTrigger>
                  <SelectValue placeholder="Filter by event" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All events</SelectItem>
                  <SelectItem value="approval.requested">Approval requested</SelectItem>
                  <SelectItem value="approval.approved">Approval approved</SelectItem>
                  <SelectItem value="approval.rejected">Approval rejected</SelectItem>
                  <SelectItem value="approval.escalated">Approval escalated</SelectItem>
                  <SelectItem value="exception.logged">Exception logged</SelectItem>
                  <SelectItem value="exception.assigned">Exception assigned</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : items.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16 text-muted-foreground">
              <Icon icon="solar:bell-off-bold-duotone" className="mb-3 h-12 w-12 opacity-40" />
              <p className="text-sm">No notifications to show</p>
            </div>
          ) : (
            <div className="space-y-2">
              {items.map((item) => {
                const text = notificationText(item)
                return (
                <div
                  key={item.id}
                  className={`group flex cursor-pointer gap-4 rounded-lg border border-l-4 bg-card p-4 transition-colors hover:bg-muted/30 ${eventColor(item.event)} ${
                    !item.read_at ? 'border-primary/20' : 'opacity-80'
                  }`}
                  onClick={() => handleClick(item)}
                >
                  <div className="flex-1 space-y-1">
                    <div className="flex items-center justify-between gap-2">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">{text.title}</span>
                        {!item.read_at && (
                          <span className="h-2 w-2 rounded-full bg-primary" />
                        )}
                      </div>
                      <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <span>{formatRelativeTime(item.created_at)}</span>
                        <button
                          className="opacity-0 transition-opacity hover:text-destructive group-hover:opacity-100"
                          onClick={(e) => {
                            e.stopPropagation()
                            deleteMutation.mutate(item.id)
                          }}
                          aria-label="Delete notification"
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                        </button>
                      </div>
                    </div>
                    <p className="text-sm text-muted-foreground whitespace-pre-wrap">{text.body}</p>
                    {(() => {
                      const refs = linkableRefs(item.data)
                      if (refs.length === 0) return null
                      return (
                        <div className="flex flex-wrap gap-1.5 pt-1">
                          {refs.map((ref) => (
                            <button
                              key={`${ref.type}:${ref.id}`}
                              onClick={(e) => {
                                e.stopPropagation()
                                if (!item.read_at) markReadMutation.mutate(item.id)
                                const route = refRoute(ref)
                                if (route) navigate(route)
                              }}
                              className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground transition-colors hover:border-primary/40 hover:text-primary"
                            >
                              <Icon icon="solar:link-bold" className="h-3 w-3" />
                              {refLabel(ref)}
                            </button>
                          ))}
                        </div>
                      )
                    })()}
                    <div className="flex items-center gap-2 pt-1">
                      <Badge variant="outline" className="text-[10px]">
                        {item.event}
                      </Badge>
                      {/* `status` tracks the MAIL leg only — the row itself is
                          the in-app notification and is always delivered. Label it
                          accordingly and explain the reason on hover, so a
                          non-deliverable recipient doesn't read as a failure. */}
                      <Badge
                        variant={
                          item.status === 'sent' ? 'secondary' :
                          item.status === 'failed' ? 'destructive' : 'outline'
                        }
                        className="text-[10px]"
                        title={item.error || undefined}
                      >
                        {item.status === 'sent' ? 'mail sent'
                          : item.status === 'failed' ? 'mail failed'
                          : item.status === 'skipped' ? 'mail skipped'
                          : 'sending…'}
                      </Badge>
                      {item.sent_at && (
                        <span className="text-[10px] text-muted-foreground">
                          Sent {formatDate(item.sent_at)}
                        </span>
                      )}
                    </div>
                  </div>
                </div>
                )
              })}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
