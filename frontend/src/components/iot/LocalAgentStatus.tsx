import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'

interface LocalAgentStatusProps {
  isConnected: boolean
  isConnecting: boolean
  onConnect: () => void
  onDisconnect: () => void
  devices?: Array<{
    id: string
    name: string
    type: string
    status: string
  }>
}

export function LocalAgentStatus({
  isConnected,
  isConnecting,
  onConnect,
  onDisconnect,
  devices = [],
}: LocalAgentStatusProps) {
  const getStatusColor = () => {
    if (isConnecting) return 'bg-yellow-500'
    if (isConnected) return 'bg-green-500'
    return 'bg-gray-500'
  }

  const getStatusText = () => {
    if (isConnecting) return 'Connecting...'
    if (isConnected) return 'Connected'
    return 'Disconnected'
  }

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" size="sm" className="gap-2">
          <div className={`h-2 w-2 rounded-full ${getStatusColor()} animate-pulse`} />
          <Icon icon="solar:server-bold" className="h-4 w-4" />
          <span className="hidden sm:inline">Local Agent</span>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-80" align="end">
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Icon icon="solar:server-bold-duotone" className="h-5 w-5 text-primary" />
              <div>
                <p className="font-semibold text-sm">Local Agent</p>
                <p className="text-xs text-muted-foreground">ws://localhost:3001</p>
              </div>
            </div>
            <Badge variant={isConnected ? 'default' : 'secondary'}>
              {getStatusText()}
            </Badge>
          </div>

          {isConnected && devices.length > 0 && (
            <div className="space-y-2">
              <p className="text-xs font-medium text-muted-foreground">Connected Devices</p>
              <div className="space-y-1">
                {devices.map((device) => (
                  <div
                    key={device.id}
                    className="flex items-center justify-between p-2 rounded-md bg-muted/50"
                  >
                    <div className="flex items-center gap-2">
                      <Icon
                        icon={
                          device.type === 'terminal'
                            ? 'solar:card-bold'
                            : device.type === 'printer'
                            ? 'solar:printer-bold'
                            : 'solar:qr-code-bold'
                        }
                        className="h-4 w-4"
                      />
                      <span className="text-sm">{device.name}</span>
                    </div>
                    <div className={`h-2 w-2 rounded-full ${
                      device.status === 'online' ? 'bg-green-500' : 'bg-gray-500'
                    }`} />
                  </div>
                ))}
              </div>
            </div>
          )}

          {!isConnected && (
            <div className="space-y-2">
              <p className="text-xs text-muted-foreground">
                The local agent is not running. USB devices will not be available.
              </p>
              <Button
                size="sm"
                className="w-full"
                onClick={onConnect}
                disabled={isConnecting}
              >
                {isConnecting ? (
                  <>
                    <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                    Connecting...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:link-circle-bold" className="mr-2 h-4 w-4" />
                    Connect
                  </>
                )}
              </Button>
            </div>
          )}

          {isConnected && (
            <Button
              size="sm"
              variant="outline"
              className="w-full"
              onClick={onDisconnect}
            >
              <Icon icon="solar:link-broken-bold" className="mr-2 h-4 w-4" />
              Disconnect
            </Button>
          )}
        </div>
      </PopoverContent>
    </Popover>
  )
}
