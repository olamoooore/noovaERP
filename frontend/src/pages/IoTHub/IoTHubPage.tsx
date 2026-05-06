/**
 * NoovaERP IoT Hub Page
 * 
 * Main entry point for IoT device management.
 * Displays agent status, device configurations, and discovery panel.
 * Supports multi-protocol devices (Agent, HTTP, MQTT, Webhook).
 */

import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import iotApi, { DeviceConfiguration } from '@/lib/iotApi'
import { DeviceConfigDialog } from '@/components/iot/DeviceConfigDialog'
import { DiscoveredDevicesPanel } from '@/components/iot/DiscoveredDevicesPanel'
import { AgentConfigDialog } from '@/components/iot/AgentConfigDialog'
import { ScannerControl } from '@/components/iot/ScannerControl'
import { PrinterControl } from '@/components/iot/PrinterControl'
import { getConnectionIcon, getConnectionLabel } from '@/lib/iot-helpers'
import { getLinkedAgentId } from '@/services/printService'

// Hardcoded agent ID for now - will be dynamic later
const DEFAULT_AGENT_ID = 'agent-676da24f-97d3-4d74-bcd2-c235a30dbac7'

export function IoTHubPage() {
  const [selectedTab, setSelectedTab] = useState('configured')
  const [configDialogOpen, setConfigDialogOpen] = useState(false)
  const [agentConfigDialogOpen, setAgentConfigDialogOpen] = useState(false)
  const [selectedDevice, setSelectedDevice] = useState<DeviceConfiguration | null>(null)
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const agentId = getLinkedAgentId() ?? DEFAULT_AGENT_ID

  // Fetch agent health
  const { data: agentHealth, isLoading: healthLoading, error: healthError } = useQuery({
    queryKey: ['agent-health', agentId],
    queryFn: () => iotApi.checkAgentHealth(agentId),
    refetchInterval: 30000, // Poll every 30 seconds
    retry: 1,
  })

  // Fetch device configurations
  const { data: deviceConfigsData, isLoading: configsLoading } = useQuery({
    queryKey: ['device-configs', agentId],
    queryFn: () => iotApi.getDeviceConfigs(agentId),
  })

  // Fetch discovered devices - only when agent is online
  const { data: discoveredData, isLoading: discoveredLoading } = useQuery({
    queryKey: ['discovered-devices', agentId],
    queryFn: () => iotApi.getDiscoveredDevices({ agentId }),
    enabled: agentHealth?.status === 'online', // Only fetch when agent is online
  })

  // Trigger discovery scan
  const scanMutation = useMutation({
    mutationFn: () => {
      // Check if agent is online before scanning
      if (agentHealth?.status !== 'online') {
        throw new Error('Agent is not connected. Please ensure the Local Agent is running.')
      }
      return iotApi.triggerDiscoveryScan(agentId)
    },
    onSuccess: (data) => {
      toast({
        title: 'Discovery scan triggered',
        description: `Found ${data.discovered?.length || 0} devices`,
      })
      queryClient.invalidateQueries({ queryKey: ['discovered-devices'] })
    },
    onError: (error: any) => {
      toast({
        title: 'Scan failed',
        description: error.message || error.response?.data?.message || 'Failed to trigger device discovery',
        variant: 'destructive',
      })
    },
  })

  // Reload device
  const reloadDeviceMutation = useMutation({
    mutationFn: (deviceId: string) => iotApi.reloadDevice(agentId, deviceId),
    onSuccess: () => {
      toast({
        title: 'Device reloaded',
        description: 'Device configuration has been reloaded',
      })
    },
    onError: () => {
      toast({
        title: 'Reload failed',
        description: 'Failed to reload device',
        variant: 'destructive',
      })
    },
  })

  // Reload all devices
  const reloadAllMutation = useMutation({
    mutationFn: () => iotApi.reloadAllDevices(agentId),
    onSuccess: (data) => {
      toast({
        title: 'All devices reloaded',
        description: `Reloaded ${data.reloaded || 0} devices successfully`,
      })
      queryClient.invalidateQueries({ queryKey: ['device-configs'] })
    },
    onError: () => {
      toast({
        title: 'Reload failed',
        description: 'Failed to reload devices',
        variant: 'destructive',
      })
    },
  })

  const deviceConfigs = deviceConfigsData?.data || []
  const discoveredDevices = discoveredData?.data || []

  // Group devices by type
  const devicesByType = deviceConfigs.reduce((acc, device) => {
    const type = device.device_type || 'other'
    if (!acc[type]) acc[type] = []
    acc[type].push(device)
    return acc
  }, {} as Record<string, DeviceConfiguration[]>)

  const handleEditDevice = (device: DeviceConfiguration) => {
    setSelectedDevice(device)
    setConfigDialogOpen(true)
  }

  const handleAddDevice = () => {
    setSelectedDevice(null)
    setConfigDialogOpen(true)
  }

  const handleConfigDialogClose = () => {
    setConfigDialogOpen(false)
    setSelectedDevice(null)
    queryClient.invalidateQueries({ queryKey: ['device-configs'] })
  }

  const handleAgentConfigClose = () => {
    setAgentConfigDialogOpen(false)
    queryClient.invalidateQueries({ queryKey: ['agent-health'] })
  }

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-2">
            <Icon icon="solar:cpu-bolt-bold-duotone" className="h-7 w-7 text-primary" />
            NoovaERP IoT Hub
          </h1>
          <p className="text-muted-foreground">
            Manage your IoT devices with multi-protocol support
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setAgentConfigDialogOpen(true)}>
            <Icon icon="solar:settings-bold" className="h-4 w-4 mr-2" />
            Agent Config
          </Button>
          <Button onClick={handleAddDevice}>
            <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
            Add Device
          </Button>
        </div>
      </div>

      {/* Agent Status Card */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-primary/10 rounded-lg">
                <Icon icon="solar:server-bold-duotone" className="h-6 w-6 text-primary" />
              </div>
              <div>
                <CardTitle>Local Agent Status</CardTitle>
                <CardDescription>
                  Connection to POS machine agent
                </CardDescription>
              </div>
            </div>
            {healthLoading ? (
              <Badge variant="outline">
                <Icon icon="solar:refresh-bold" className="h-3 w-3 mr-1 animate-spin" />
                Checking...
              </Badge>
            ) : healthError ? (
              <Badge variant="destructive" className="gap-2">
                <div className="h-2 w-2 rounded-full bg-red-500" />
                Offline
              </Badge>
            ) : agentHealth?.status === 'online' ? (
              <Badge variant="default" className="gap-2">
                <div className="h-2 w-2 rounded-full bg-green-500 animate-pulse" />
                Online
              </Badge>
            ) : (
              <Badge variant="destructive" className="gap-2">
                <div className="h-2 w-2 rounded-full bg-red-500" />
                Offline
              </Badge>
            )}
          </div>
        </CardHeader>
        <CardContent>
          {healthError ? (
            <div className="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-lg">
              <div className="flex items-start gap-3">
                <Icon icon="solar:danger-bold" className="h-5 w-5 text-red-600 mt-0.5" />
                <div className="flex-1">
                  <p className="font-medium text-red-900 dark:text-red-100 mb-1">
                    Agent Connection Failed
                  </p>
                  <p className="text-sm text-red-700 dark:text-red-300">
                    Cannot connect to the local agent. Please ensure the agent is running on your POS machine.
                  </p>
                </div>
              </div>
            </div>
          ) : agentHealth?.status === 'online' ? (
            <div className="grid grid-cols-4 gap-4">
              <div className="p-3 bg-muted/50 rounded-lg text-center">
                <p className="text-2xl font-bold">{deviceConfigs.length}</p>
                <p className="text-xs text-muted-foreground">Devices</p>
              </div>
              <div className="p-3 bg-muted/50 rounded-lg text-center">
                <p className="text-2xl font-bold text-green-600">
                  {deviceConfigs.filter(d => d.status === 'active').length}
                </p>
                <p className="text-xs text-muted-foreground">Active</p>
              </div>
              <div className="p-3 bg-muted/50 rounded-lg text-center">
                <p className="text-2xl font-bold">{agentHealth.last_heartbeat ? 'Now' : '-'}</p>
                <p className="text-xs text-muted-foreground">Last Seen</p>
              </div>
              <div className="p-3 bg-muted/50 rounded-lg text-center">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => reloadAllMutation.mutate()}
                  disabled={reloadAllMutation.isPending}
                  className="w-full"
                >
                  <Icon 
                    icon="solar:refresh-bold" 
                    className={`h-4 w-4 mr-1 ${reloadAllMutation.isPending ? 'animate-spin' : ''}`} 
                  />
                  Reload All
                </Button>
              </div>
            </div>
          ) : (
            <div className="text-center py-4 text-muted-foreground">
              <Icon icon="solar:server-bold-duotone" className="h-12 w-12 mx-auto mb-2 opacity-50" />
              <p>Agent status unknown</p>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Tabs: Configured Devices vs Discovery */}
      <Tabs value={selectedTab} onValueChange={setSelectedTab}>
        <TabsList className="grid w-full grid-cols-2">
          <TabsTrigger value="configured">
            Configured Devices ({deviceConfigs.length})
          </TabsTrigger>
          <TabsTrigger value="discovery">
            Device Discovery ({discoveredDevices.length})
          </TabsTrigger>
        </TabsList>

        {/* Configured Devices Tab */}
        <TabsContent value="configured" className="space-y-6">
          {configsLoading ? (
            <Card>
              <CardContent className="py-12 text-center">
                <Icon icon="solar:refresh-bold" className="h-12 w-12 text-muted-foreground mx-auto mb-4 animate-spin" />
                <p className="text-muted-foreground">Loading devices...</p>
              </CardContent>
            </Card>
          ) : deviceConfigs.length === 0 ? (
            <Card>
              <CardContent className="py-12 text-center">
                <Icon icon="solar:box-bold-duotone" className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                <h3 className="text-lg font-medium mb-2">No Devices Configured</h3>
                <p className="text-muted-foreground mb-4">
                  Add your first device to get started
                </p>
                <Button onClick={handleAddDevice}>
                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                  Add Device
                </Button>
              </CardContent>
            </Card>
          ) : (
            // Device grid grouped by type
            Object.entries(devicesByType).map(([type, typeDevices]) => (
              <div key={type} className="space-y-4">
                <h2 className="text-lg font-semibold capitalize flex items-center gap-2">
                  <Icon 
                    icon={
                      type === 'printer' ? 'solar:printer-bold-duotone' :
                      type === 'scanner' ? 'solar:scanner-bold-duotone' :
                      type === 'payment_terminal' ? 'solar:card-bold-duotone' :
                      type === 'biometric' ? 'solar:user-id-bold-duotone' :
                      type === 'scale' ? 'solar:scale-bold-duotone' :
                      type === 'display' ? 'solar:monitor-bold-duotone' :
                      'solar:cpu-bolt-bold-duotone'
                    } 
                    className="h-5 w-5" 
                  />
                  {type.replace('_', ' ')}s ({typeDevices.length})
                </h2>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {typeDevices.map((device) => (
                    <Card key={device.id} className="hover:shadow-md transition-shadow">
                      <CardHeader>
                        <div className="flex items-start justify-between">
                          <div className="flex-1">
                            <CardTitle className="text-base flex items-center gap-2">
                              {device.name}
                              <Badge variant={device.status === 'active' ? 'default' : 'secondary'}>
                                {device.status}
                              </Badge>
                            </CardTitle>
                            <CardDescription className="flex items-center gap-1 mt-1">
                              <Icon icon={getConnectionIcon(device.connection_type || 'agent')} className="h-3 w-3" />
                              {getConnectionLabel(device.connection_type || 'agent')}
                            </CardDescription>
                          </div>
                        </div>
                      </CardHeader>
                      <CardContent className="space-y-3">
                        {/* Device-specific controls */}
                        {device.device_type === 'scanner' && (
                          <ScannerControl device={device} agentId={agentId} agentOnline={agentHealth?.status === 'online'} />
                        )}
                        {device.device_type === 'printer' && (
                          <PrinterControl device={device} agentId={agentId} agentOnline={agentHealth?.status === 'online'} />
                        )}

                        {/* Action buttons */}
                        <div className="flex gap-2">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleEditDevice(device)}
                            className="flex-1"
                          >
                            <Icon icon="solar:settings-bold" className="h-4 w-4 mr-1" />
                            Configure
                          </Button>
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => reloadDeviceMutation.mutate(device.id)}
                            disabled={reloadDeviceMutation.isPending}
                          >
                            <Icon 
                              icon="solar:refresh-bold" 
                              className={`h-4 w-4 ${reloadDeviceMutation.isPending ? 'animate-spin' : ''}`} 
                            />
                          </Button>
                        </div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              </div>
            ))
          )}
        </TabsContent>

        {/* Discovery Tab */}
        <TabsContent value="discovery">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Device Discovery</CardTitle>
                  <CardDescription>
                    Scan for new devices on your network
                  </CardDescription>
                </div>
                <Button
                  onClick={() => scanMutation.mutate()}
                  disabled={scanMutation.isPending || agentHealth?.status !== 'online'}
                >
                  <Icon 
                    icon="solar:magnifer-bold" 
                    className={`h-4 w-4 mr-2 ${scanMutation.isPending ? 'animate-spin' : ''}`} 
                  />
                  Scan for Devices
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              <DiscoveredDevicesPanel
                agentId={agentId}
                devices={discoveredDevices}
                isLoading={discoveredLoading}
                agentOnline={agentHealth?.status === 'online'}
                onRefresh={() => {
                  queryClient.invalidateQueries({ queryKey: ['discovered-devices'] })
                }}
                onConfigureDevice={(device) => {
                  // Handle device configuration from discovery
                  queryClient.invalidateQueries({ queryKey: ['device-configs'] })
                  queryClient.invalidateQueries({ queryKey: ['discovered-devices'] })
                }}
              />
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Device Config Dialog */}
      <DeviceConfigDialog
        open={configDialogOpen}
        onOpenChange={(open) => !open && handleConfigDialogClose()}
        device={selectedDevice}
        agentId={agentId}
        onSuccess={handleConfigDialogClose}
      />

      {/* Agent Config Dialog */}
      <AgentConfigDialog
        open={agentConfigDialogOpen}
        onOpenChange={(open) => !open && handleAgentConfigClose()}
      />
    </div>
  )
}

export default IoTHubPage
