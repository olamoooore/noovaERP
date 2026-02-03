import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { ScrollArea } from '@/components/ui/scroll-area'
import api from '@/lib/api'

interface DeviceLog {
  id: number
  device_id: number
  event_type: string
  message: string
  metadata: Record<string, any>
  created_at: string
}

interface DeviceLogsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  deviceId: number | null
  deviceName: string
}

export function DeviceLogsDialog({ open, onOpenChange, deviceId, deviceName }: DeviceLogsDialogProps) {
  const { data: logs, isLoading } = useQuery<DeviceLog[]>({
    queryKey: ['device-logs', deviceId],
    queryFn: async () => {
      if (!deviceId) return []
      const response = await api.get(`/iot/devices/${deviceId}/logs`)
      return response.data
    },
    enabled: open && !!deviceId,
  })

  const getEventIcon = (eventType: string) => {
    switch (eventType) {
      case 'connected':
        return 'solar:link-circle-bold'
      case 'disconnected':
        return 'solar:link-broken-bold'
      case 'command_sent':
        return 'solar:arrow-right-bold'
      case 'response_received':
        return 'solar:arrow-left-bold'
      case 'error':
        return 'solar:danger-circle-bold'
      case 'payment_success':
        return 'solar:check-circle-bold'
      case 'payment_failed':
        return 'solar:close-circle-bold'
      default:
        return 'solar:info-circle-bold'
    }
  }

  const getEventColor = (eventType: string) => {
    switch (eventType) {
      case 'connected':
      case 'payment_success':
        return 'text-green-500'
      case 'disconnected':
        return 'text-gray-500'
      case 'error':
      case 'payment_failed':
        return 'text-red-500'
      case 'command_sent':
        return 'text-blue-500'
      case 'response_received':
        return 'text-purple-500'
      default:
        return 'text-muted-foreground'
    }
  }

  const formatTimestamp = (timestamp: string) => {
    const date = new Date(timestamp)
    return date.toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[700px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5 text-primary" />
            Device Logs
          </DialogTitle>
          <DialogDescription>
            Activity log for {deviceName}
          </DialogDescription>
        </DialogHeader>

        <ScrollArea className="h-[500px] pr-4">
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : logs && logs.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <Icon icon="solar:document-text-bold-duotone" className="h-12 w-12 text-muted-foreground mb-3" />
              <p className="text-muted-foreground">No logs available</p>
            </div>
          ) : (
            <div className="space-y-2">
              {logs?.map((log) => (
                <div
                  key={log.id}
                  className="flex gap-3 p-3 rounded-lg border bg-card hover:bg-accent/50 transition-colors"
                >
                  <Icon
                    icon={getEventIcon(log.event_type)}
                    className={`h-5 w-5 flex-shrink-0 mt-0.5 ${getEventColor(log.event_type)}`}
                  />
                  <div className="flex-1 min-w-0">
                    <div className="flex items-start justify-between gap-2 mb-1">
                      <Badge variant="outline" className="capitalize text-xs">
                        {log.event_type.replace('_', ' ')}
                      </Badge>
                      <span className="text-xs text-muted-foreground whitespace-nowrap">
                        {formatTimestamp(log.created_at)}
                      </span>
                    </div>
                    <p className="text-sm">{log.message}</p>
                    {log.metadata && Object.keys(log.metadata).length > 0 && (
                      <details className="mt-2">
                        <summary className="text-xs text-muted-foreground cursor-pointer hover:text-foreground">
                          View metadata
                        </summary>
                        <pre className="mt-2 p-2 bg-muted rounded text-xs overflow-x-auto">
                          {JSON.stringify(log.metadata, null, 2)}
                        </pre>
                      </details>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </ScrollArea>
      </DialogContent>
    </Dialog>
  )
}
