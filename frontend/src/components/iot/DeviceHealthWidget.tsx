import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface IoTDevice {
  id: number
  name: string
  type: 'printer' | 'terminal' | 'scanner' | 'kiosk' | 'agent'
  vendor: string
  connection_type: 'cloud' | 'lan' | 'agent' | 'bluetooth' | 'usb'
  status: 'online' | 'offline' | 'error' | 'maintenance'
  last_seen_at: string | null
  latest_health_log?: {
    status: string
    response_time_ms: number | null
    checked_at: string
    error_message?: string
  }
}

export function DeviceHealthWidget() {
  const [searchQuery, setSearchQuery] = useState('')
  const { toast } = useToast()
  const queryClient = useQueryClient()

  // Fetch devices with health data
  const { data: devices, isLoading } = useQuery<IoTDevice[]>({
    queryKey: ['iot-devices-health'],
    queryFn: async () => {
      const response = await api.get('/iot/devices?include_health=true')
      return response.data
    },
    refetchInterval: 30000, // Refresh every 30 seconds
  })

  // Manual health check mutation
  const checkHealthMutation = useMutation({
    mutationFn: async (deviceId: number) => {
      const response = await api.post(`/iot/devices/${deviceId}/check-health`)
      return response.data
    },
    onSuccess: (data, deviceId) => {
      toast({
        title: 'Health check completed',
        description: `Device health check completed with status: ${data.result.status}`,
      })
      queryClient.invalidateQueries({ queryKey: ['iot-devices-health'] })
    },
    onError: (error: any) => {
      toast({
        title: 'Health check failed',
        description: error.response?.data?.message || 'Failed to perform health check',
        variant: 'destructive',
      })
    },
  })

  const getDeviceIcon = (type: string, connectionType?: string) => {
    switch (type) {
      case 'printer':
        return connectionType === 'usb' ? 'solar:tag-bold-duotone' : 'solar:printer-bold-duotone'
      case 'terminal':
        return 'solar:card-bold-duotone'
      case 'scanner':
        return 'solar:qr-code-bold-duotone'
      case 'kiosk':
        return 'solar:monitor-bold-duotone'
      case 'agent':
        return 'solar:server-bold-duotone'
      default:
        return 'solar:devices-bold-duotone'
    }
  }

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'online':
        return 'bg-green-500'
      case 'offline':
        return 'bg-gray-500'
      case 'error':
        return 'bg-red-500'
      case 'maintenance':
        return 'bg-yellow-500'
      default:
        return 'bg-gray-500'
    }
  }

  const getStatusBadgeVariant = (status: string) => {
    switch (status) {
      case 'online':
        return 'default'
      case 'offline':
        return 'secondary'
      case 'error':
        return 'destructive'
      case 'maintenance':
        return 'outline'
      default:
        return 'secondary'
    }
  }

  const filteredDevices = devices?.filter(device => {
    if (!searchQuery) return true
    const query = searchQuery.toLowerCase()
    return (
      device.name.toLowerCase().includes(query) ||
      device.vendor.toLowerCase().includes(query) ||
      device.type.toLowerCase().includes(query)
    )
  }) || []

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96">
        <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Search */}
      <div className="flex gap-4">
        <Input
          placeholder="Search devices..."
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          className="max-w-md"
        />
      </div>

      {/* Device Health Cards */}
      {filteredDevices.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Icon icon="solar:devices-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No devices found</h3>
            <p className="text-muted-foreground text-center">
              {searchQuery ? 'Try adjusting your search terms' : 'No devices are currently configured'}
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {filteredDevices.map((device) => (
            <Card key={device.id} className="relative">
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-3">
                    <div className="p-2 bg-primary/10 rounded-lg">
                      <Icon 
                        icon={getDeviceIcon(device.type, device.connection_type)} 
                        className="h-6 w-6 text-primary" 
                      />
                    </div>
                    <div>
                      <CardTitle className="text-lg">{device.name}</CardTitle>
                      <CardDescription className="capitalize">
                        {device.vendor} {device.type}
                      </CardDescription>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className={`h-2 w-2 rounded-full ${getStatusColor(device.status)}`} />
                    <Badge variant={getStatusBadgeVariant(device.status)} className="capitalize">
                      {device.status}
                    </Badge>
                  </div>
                </div>
              </CardHeader>
              
              <CardContent className="space-y-3">
                {/* Connection Type */}
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">Connection</span>
                  <Badge variant="outline" className="capitalize">
                    {device.connection_type}
                  </Badge>
                </div>

                {/* Last Seen */}
                {device.last_seen_at && (
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Last seen</span>
                    <span>{new Date(device.last_seen_at).toLocaleString()}</span>
                  </div>
                )}

                {/* Health Check Info */}
                {device.latest_health_log && (
                  <div className="space-y-2 p-3 bg-muted/30 rounded-lg">
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">Last Check</span>
                      <span>{new Date(device.latest_health_log.checked_at).toLocaleString()}</span>
                    </div>
                    
                    {device.latest_health_log.response_time_ms && (
                      <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">Response Time</span>
                        <span>{device.latest_health_log.response_time_ms}ms</span>
                      </div>
                    )}

                    {device.latest_health_log.error_message && (
                      <div className="text-sm">
                        <span className="text-muted-foreground">Error:</span>
                        <p className="text-red-600 text-xs mt-1">{device.latest_health_log.error_message}</p>
                      </div>
                    )}
                  </div>
                )}

                {/* Actions */}
                <div className="flex gap-2 pt-2">
                  <Button
                    variant="outline"
                    size="sm"
                    className="flex-1"
                    onClick={() => checkHealthMutation.mutate(device.id)}
                    disabled={checkHealthMutation.isPending}
                  >
                    {checkHealthMutation.isPending ? (
                      <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                      <Icon icon="solar:heart-pulse-bold" className="mr-2 h-4 w-4" />
                    )}
                    Check Health
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      // Navigate to device details (implement as needed)
                      toast({
                        title: 'Device Details',
                        description: `Viewing details for ${device.name}`,
                      })
                    }}
                  >
                    <Icon icon="solar:eye-bold" className="h-4 w-4" />
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}