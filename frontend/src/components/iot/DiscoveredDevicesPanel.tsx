import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { DiscoveredDevice } from '@/lib/iotApi'

interface DiscoveredDevicesPanelProps {
  agentId: string
  devices: DiscoveredDevice[]
  isLoading: boolean
  onRefresh: () => void
  onConfigureDevice?: (device: DiscoveredDevice) => void
  agentOnline?: boolean
}

export function DiscoveredDevicesPanel({
  devices,
  isLoading,
  onRefresh,
  onConfigureDevice,
  agentOnline = true,
}: DiscoveredDevicesPanelProps) {
  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96">
        <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
      </div>
    )
  }

  // Show message when agent is offline
  if (!agentOnline) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center justify-center py-12">
          <Icon icon="solar:close-circle-bold-duotone" className="h-16 w-16 text-destructive mb-4" />
          <h3 className="text-lg font-semibold mb-2">Agent Not Connected</h3>
          <p className="text-muted-foreground text-center mb-4 max-w-md">
            The Local Agent must be online to discover devices. Please ensure the agent is running on your POS machine.
          </p>
        </CardContent>
      </Card>
    )
  }

  if (devices.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center justify-center py-12">
          <Icon icon="solar:magnifer-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
          <h3 className="text-lg font-semibold mb-2">No devices discovered</h3>
          <p className="text-muted-foreground text-center mb-4">
            Click "Scan Devices" to search for connected hardware
          </p>
          <Button onClick={onRefresh}>
            <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
            Refresh
          </Button>
        </CardContent>
      </Card>
    )
  }

  const getConnectionTypeIcon = (type: string) => {
    switch (type) {
      case 'usb':
        return 'solar:usb-bold-duotone'
      case 'serial':
        return 'solar:cable-bold-duotone'
      case 'hid':
        return 'solar:keyboard-bold-duotone'
      default:
        return 'solar:devices-bold-duotone'
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          Found {devices.length} device{devices.length !== 1 ? 's' : ''}
        </p>
        <Button variant="outline" size="sm" onClick={onRefresh}>
          <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
          Refresh
        </Button>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {devices.map((device) => (
          <Card key={device.id}>
            <CardHeader>
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="p-2 bg-primary/10 rounded-lg">
                    <Icon icon={getConnectionTypeIcon(device.connectionType)} className="h-6 w-6 text-primary" />
                  </div>
                  <div>
                    <CardTitle className="text-lg">
                      {device.manufacturer || 'Unknown'}
                    </CardTitle>
                    <CardDescription>
                      {device.model || 'Unknown Model'}
                    </CardDescription>
                  </div>
                </div>
                {device.configuredDeviceId ? (
                  <Badge variant="default">Configured</Badge>
                ) : (
                  <Badge variant="secondary">New</Badge>
                )}
              </div>
            </CardHeader>
            <CardContent>
              <div className="space-y-2 text-sm">
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Vendor ID</span>
                  <code className="text-xs bg-muted px-2 py-1 rounded">{device.vendorId}</code>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Product ID</span>
                  <code className="text-xs bg-muted px-2 py-1 rounded">{device.productId}</code>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Connection</span>
                  <Badge variant="outline" className="capitalize">
                    {device.connectionType}
                  </Badge>
                </div>
                {device.serialNumber && (
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">Serial</span>
                    <span className="text-xs truncate max-w-[150px]">{device.serialNumber}</span>
                  </div>
                )}
                {device.port && (
                  <div className="flex flex-col gap-1">
                    <span className="text-muted-foreground">Port</span>
                    <code className="text-xs bg-muted px-2 py-1 rounded break-all">
                      {device.port}
                    </code>
                  </div>
                )}
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Discovered</span>
                  <span className="text-xs">{new Date(device.discoveredAt).toLocaleString()}</span>
                </div>

                {!device.configuredDeviceId && (
                  <div className="pt-2">
                    <Button 
                      size="sm" 
                      className="w-full"
                      onClick={() => onConfigureDevice?.(device)}
                    >
                      <Icon icon="solar:settings-bold" className="mr-2 h-4 w-4" />
                      Configure Device
                    </Button>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}
