import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import api from '@/lib/api'

export interface NotificationPreferenceEvent {
  event: string
  label: string
  channels: Record<string, boolean>
  is_default?: Record<string, boolean>
  available?: Record<string, boolean>
}

const CHANNELS = ['in_app', 'mail', 'whatsapp'] as const

const CHANNEL_LABELS: Record<string, string> = {
  in_app: 'In-app',
  mail: 'Email',
  whatsapp: 'WhatsApp',
}

/**
 * Issue #907 (PCU3): the per-user notification preference matrix.
 *
 * Rows are sales events, columns are channels. Role/catalogue defaults arrive
 * pre-ticked (the API marks untouched cells with `is_default`); a switch
 * writes the same platform_notification_preferences row as #903. WhatsApp is
 * a stored-preference column only — its transport is not shipped, so it
 * defaults off and is never delivered.
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
          Choose how each sales event reaches you. Defaults follow your role and are pre-ticked;
          switch a channel off to mute it for your account. WhatsApp is stored now and delivered
          once its transport is enabled.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="flex items-center justify-center py-6">
            <Icon icon="eos-icons:loading" className="h-6 w-6 animate-spin text-primary" />
          </div>
        ) : isError ? (
          <p className="py-4 text-sm text-muted-foreground">
            Could not load notification preferences. Please try again.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] border-separate border-spacing-0">
              <thead>
                <tr>
                  <th className="border-b pb-2 pr-4 text-left text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Event
                  </th>
                  {CHANNELS.map((channel) => (
                    <th
                      key={channel}
                      className="border-b px-4 pb-2 text-center text-xs font-medium uppercase tracking-wide text-muted-foreground"
                    >
                      {CHANNEL_LABELS[channel]}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {(data ?? []).map((item) => (
                  <tr key={item.event} data-testid={`pref-${item.event}`}>
                    <td className="border-b py-2 pr-4 text-sm font-medium">{item.label}</td>
                    {CHANNELS.map((channel) => {
                      const available = item.available?.[channel] ?? true
                      const enabled = item.channels?.[channel] ?? false
                      const isDefault = item.is_default?.[channel] ?? false

                      return (
                        <td key={channel} className="border-b px-4 py-2 text-center align-middle">
                          <div className="flex flex-col items-center gap-1">
                            <Switch
                              id={`${item.event}-${channel}`}
                              aria-label={`${item.label} ${CHANNEL_LABELS[channel]}`}
                              checked={enabled}
                              disabled={!available || mutation.isPending}
                              onCheckedChange={(checked) =>
                                mutation.mutate({ event: item.event, channel, enabled: checked })
                              }
                            />
                            {isDefault && (
                              <span
                                data-testid={`default-${item.event}-${channel}`}
                                className="text-[10px] text-muted-foreground"
                                title="Your role's default — not changed yet"
                              >
                                default
                              </span>
                            )}
                          </div>
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
