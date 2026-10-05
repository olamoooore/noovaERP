import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import api from '@/lib/api'

export interface NotificationPreferenceEvent {
  event: string
  label: string
  channels: Record<string, boolean>
}

const CHANNEL_LABELS: Record<string, string> = {
  in_app: 'In-app',
  mail: 'Email',
}

/**
 * Issue #903 (PC9): per-event, per-channel mute switches for the sales
 * notifications. Defaults follow the recipient's role; a switch off writes a
 * platform_notification_preferences row so the backend dispatcher drops that
 * channel (and the in-app inbox hides the event).
 */
export function NotificationPreferencesCard() {
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['notification-preferences'],
    queryFn: async () => {
      const res = await api.get('/notifications/preferences')
      return (res.data?.data ?? []) as NotificationPreferenceEvent[]
    },
  })

  const mutation = useMutation({
    mutationFn: async (vars: { event: string; channel: string; enabled: boolean }) => {
      await api.put('/notifications/preferences', vars)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notification-preferences'] })
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Icon icon="solar:bell-bing-bold-duotone" className="h-5 w-5" />
          Sales notification preferences
        </CardTitle>
        <CardDescription>
          Choose how sales events reach you. Defaults follow your role; switch a channel off to
          mute it for your account.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {isLoading ? (
          <div className="flex items-center justify-center py-6">
            <Icon icon="eos-icons:loading" className="h-6 w-6 animate-spin text-primary" />
          </div>
        ) : isError ? (
          <p className="py-4 text-sm text-muted-foreground">
            Could not load notification preferences. Please try again.
          </p>
        ) : (
          (data ?? []).map((item) => (
            <div
              key={item.event}
              data-testid={`pref-${item.event}`}
              className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
            >
              <span className="text-sm font-medium">{item.label}</span>
              <div className="flex items-center gap-4">
                {Object.entries(item.channels).map(([channel, enabled]) => (
                  <div key={channel} className="flex items-center gap-2">
                    <Label htmlFor={`${item.event}-${channel}`} className="text-xs text-muted-foreground">
                      {CHANNEL_LABELS[channel] ?? channel}
                    </Label>
                    <Switch
                      id={`${item.event}-${channel}`}
                      aria-label={`${item.label} ${CHANNEL_LABELS[channel] ?? channel}`}
                      checked={enabled}
                      disabled={mutation.isPending}
                      onCheckedChange={(checked) =>
                        mutation.mutate({ event: item.event, channel, enabled: checked })
                      }
                    />
                  </div>
                ))}
              </div>
            </div>
          ))
        )}
      </CardContent>
    </Card>
  )
}
