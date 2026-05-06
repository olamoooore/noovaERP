import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import iotApi, { DeviceConfiguration, DiscoveredDevice, BarcodeScannedEvent } from '@/lib/iotApi'
import { DeviceConfigDialog } from '@/components/iot/DeviceConfigDialog'
import { DiscoveredDevicesPanel } from '@/components/iot/DiscoveredDevicesPanel'
import { AgentConfigDialog } from '@/components/iot/AgentConfigDialog'
import { ScannerControl } from '@/components/iot/ScannerControl'
import { PrinterControl } from '@/components/iot/PrinterControl'
import echo from '@/lib/echo'
import { getConnectionIcon, getConnectionLabel } from '@/lib/iot-helpers'
import { useLinkedAgent } from '@/hooks/useLinkedAgent'

export function IoTDevicesPage() {
  const [selectedTab, setSelectedTab] = useState('configured')
  const [configDialogOpen, setConfigDialogOpen] = useState(false)
  const [agentConfigDialogOpen, setAgentConfigDialogOpen] = useState(false)
  const [selectedDevice, setSelectedDevice] = useState<DeviceConfiguration | null>(null)
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const { agentId, isLoading: agentLinkLoading } = useLinkedAgent()
  const hasLinkedAgent = !!agentId

  // Fetch agent health
  const { data: agentHealth, isLoading: healthLoading, error: healthError } = useQuery({
    queryKey: ['agent-health', agentId],
    queryFn: () => iotApi.checkAgentHealth(agentId!),
    enabled: hasLinkedAgent,
    refetchInterval: 30000, // Poll every 30 seconds
    retry: 1,
  })

  // Fetch device configurations
  const { data: deviceConfigsData, isLoading: configsLoading } = useQuery({
    queryKey: ['device-configs', agentId],
    queryFn: () => iotApi.getDeviceConfigs(agentId!),
    enabled: hasLinkedAgent,
  })

  // Fetch discovered devices - only when agent is online
  const { data: discoveredData, isLoading: discoveredLoading } = useQuery({
    queryKey: ['discovered-devices', agentId],
    queryFn: () => iotApi.getDiscoveredDevices({ agentId: agentId! }),
    enabled: hasLinkedAgent && agentHealth?.status === 'online', // Only fetch when agent is online
  })

  // Trigger discovery scan
  const scanMutation = useMutation({
    mutationFn: () => {
      if (!agentId) {
        throw new Error('No linked agent configured for this tenant.')
      }

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
    mutationFn: (deviceId: string) => {
      if (!agentId) {
        throw new Error('No linked agent configured for this tenant.')
      }

      return iotApi.reloadDevice(agentId, deviceId)
    },
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
    mutationFn: () => {
      if (!agentId) {
        throw new Error('No linked agent configured for this tenant.')
      }

      return iotApi.reloadAllDevices(agentId)
    },
    onSuccess: (data) => {
      toast({
        title: 'All devices reloaded',
        description: `Reloaded ${data.reloaded || 0} devices successfully`,
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Reload failed',
        description: error.response?.data?.message || error.message || 'Failed to reload devices',
        variant: 'destructive',
      })
    },
  })

  // Delete device
  const deleteDeviceMutation = useMutation({
    mutationFn: (deviceId: string) => {
      if (!agentId) {
        throw new Error('No linked agent configured for this tenant.')
      }

      return iotApi.deleteDeviceConfig(agentId, deviceId)
    },
    onSuccess: () => {
      toast({
        title: 'Device deleted',
        description: 'Device configuration has been removed',
      })
      queryClient.invalidateQueries({ queryKey: ['device-configs'] })
    },
    onError: () => {
      toast({
        title: 'Delete failed',
        description: 'Failed to delete device',
        variant: 'destructive',
      })
    },
  })

  const deviceConfigs = deviceConfigsData?.data || []
  // Only show discovered devices if agent is online
  const discoveredDevices = (agentHealth?.status === 'online' && discoveredData?.data) ? discoveredData.data : []

  // WebSocket listeners for real-time updates
  useEffect(() => {
    const echoClient = echo

    // Skip WebSocket setup if echo is not available
    if (!echoClient) {
      console.log('WebSocket disabled - skipping real-time listeners')
      return
    }

    console.log('Setting up WebSocket listeners for IoT devices...')

    // Listen for device configuration updates
    echoClient.channel('iot-devices')
      .listen('.device.config.updated', (event: any) => {
        console.log('Device config updated:', event)
        queryClient.invalidateQueries({ queryKey: ['device-configs'] })
        
        toast({
          title: 'Device Updated',
          description: `${event.config.name} has been ${event.action}`,
        })
      })
      .listen('.device.discovered', (event: any) => {
        console.log('New device discovered:', event)
        queryClient.invalidateQueries({ queryKey: ['discovered-devices'] })
        
        toast({
          title: 'New Device Found',
          description: `${event.device.manufacturer || 'Unknown'} ${event.device.model || 'Device'}`,
        })
      })
      .listen('.device.status_changed', (event: any) => {
        console.log('Device status changed:', event)
        queryClient.invalidateQueries({ queryKey: ['device-configs'] })
        
        const isError = event.status === 'error' || event.status === 'offline'
        toast({
          title: 'Device Status Changed',
          description: `${event.device_name} is now ${event.status}`,
          variant: isError ? 'destructive' : 'default',
        })
      })

    return () => {
      console.log('Cleaning up WebSocket listeners...')
      echoClient.leaveChannel('iot-devices')
    }
  }, [queryClient, toast])

  const getDeviceIcon = (type?: string) => {
    switch (type) {
      case 'printer':
        return 'solar:printer-bold-duotone'
      case 'scanner':
        return 'solar:qr-code-bold-duotone'
      case 'terminal':
        return 'solar:card-bold-duotone'
      case 'kiosk':
        return 'solar:tablet-bold-duotone'
      case 'biometric':
        return 'solar:hand-shake-bold-duotone'
      default:
        return 'solar:devices-bold-duotone'
    }
  }

  const getStatusColor = (status?: string) => {
    switch (status) {
      case 'ready':
      case 'online':
        return 'bg-green-500'
      case 'offline':
        return 'bg-gray-500'
      case 'error':
        return 'bg-red-500'
      case 'busy':
        return 'bg-yellow-500'
      default:
        return 'bg-gray-500'
    }
  }

  const handleEdit = (device: DeviceConfiguration) => {
    setSelectedDevice(device)
    setConfigDialogOpen(true)
  }

  const handleAddNew = () => {
    if (!agentId) {
      toast({
        title: 'No linked agent',
        description: 'Link this POS session to a tenant agent before adding devices.',
        variant: 'destructive',
      })
      return
    }

    setSelectedDevice(null)
    setConfigDialogOpen(true)
  }

  const handleConfigureDiscovered = (discoveredDevice: DiscoveredDevice) => {
    if (!agentId) {
      toast({
        title: 'No linked agent',
        description: 'Link this POS session to a tenant agent before configuring devices.',
        variant: 'destructive',
      })
      return
    }

    // Pre-fill the form with discovered device data
    setSelectedDevice({
      id: '',
      agentId,
      deviceType: 'printer', // Default to printer, user can change
      driverType: 'label-printer-raw',
      name: `${discoveredDevice.manufacturer || 'Unknown'} ${discoveredDevice.model || 'Device'}`,
      enabled: true,
      connectionInfo: {
        vendorId: discoveredDevice.vendorId,
        productId: discoveredDevice.productId,
        port: discoveredDevice.port,
      },
      driverConfig: {},
      capabilities: [],
      currentStatus: 'offline',
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    } as any)
    setConfigDialogOpen(true)
  }

  const handleDelete = (deviceId: string) => {
    if (confirm('Are you sure you want to delete this device?')) {
      deleteDeviceMutation.mutate(deviceId)
    }
  }

  const onlineDevicesCount = deviceConfigs.filter(d => d.currentStatus === 'ready' || d.currentStatus === 'online').length

  if (!agentLinkLoading && !hasLinkedAgent) {
    return (
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-primary/10 rounded-xl">
              <Icon icon="solar:smart-speaker-bold-duotone" className="h-8 w-8 text-primary" />
            </div>
            <div>
              <h1 className="text-3xl font-bold">NoovaERP IoT Hub</h1>
              <p className="text-muted-foreground">
                Manage your connected hardware devices
              </p>
            </div>
          </div>
          <Button variant="outline" onClick={() => setAgentConfigDialogOpen(true)}>
            <Icon icon="solar:settings-bold" className="mr-2 h-4 w-4" />
            Agent Config
          </Button>
        </div>

        <Card>
          <CardContent className="py-10">
            <div className="flex flex-col items-center text-center gap-3">
              <Icon icon="solar:link-broken-bold-duotone" className="h-14 w-14 text-muted-foreground" />
              <h2 className="text-xl font-semibold">No linked agent configured</h2>
              <p className="max-w-2xl text-sm text-muted-foreground">
                This tenant session is not linked to a POS agent. Link the current machine's agent ID first so device discovery and scanner controls target the correct tenant agent.
              </p>
            </div>
          </CardContent>
        </Card>

        <AgentConfigDialog
          open={agentConfigDialogOpen}
          onOpenChange={setAgentConfigDialogOpen}
        />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="p-3 bg-primary/10 rounded-xl">
            <Icon icon="solar:smart-speaker-bold-duotone" className="h-8 w-8 text-primary" />
          </div>
          <div>
            <h1 className="text-3xl font-bold">NoovaERP IoT Hub</h1>
            <p className="text-muted-foreground">
              Manage your connected hardware devices
            </p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            onClick={() => setAgentConfigDialogOpen(true)}
          >
            <Icon icon="solar:settings-bold" className="mr-2 h-4 w-4" />
            Agent Config
          </Button>
          <Button
            variant="outline"
            onClick={() => scanMutation.mutate()}
            disabled={scanMutation.isPending || agentHealth?.status !== 'online'}
            title={agentHealth?.status !== 'online' ? 'Agent must be online to scan' : 'Scan for devices'}
          >
            <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
            Scan Devices
          </Button>
          <Button
            variant="outline"
            onClick={() => reloadAllMutation.mutate()}
            disabled={reloadAllMutation.isPending}
          >
            <Icon icon="solar:restart-bold" className="mr-2 h-4 w-4" />
            Reload All
          </Button>
          <Button onClick={handleAddNew}>
            <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
            Add Device
          </Button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid gap-4 md:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Agent Status</CardTitle>
            <Icon icon="solar:server-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="flex items-center gap-2">
              <div className={`h-3 w-3 rounded-full ${
                healthLoading ? 'bg-yellow-500' : 
                healthError ? 'bg-red-500' :
                agentHealth?.status === 'online' ? 'bg-green-500' : 'bg-gray-500'
              }`} />
              <div className="text-2xl font-bold capitalize">
                {healthLoading ? 'Checking...' : 
                 healthError ? 'Error' :
                 agentHealth?.status || 'Unknown'}
              </div>
            </div>
            <p className="text-xs text-muted-foreground">
              {agentHealth?.data?.agentId || agentId}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Configured Devices</CardTitle>
            <Icon icon="solar:devices-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{deviceConfigs.length}</div>
            <p className="text-xs text-muted-foreground">
              Total devices configured
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Discovered Devices</CardTitle>
            <Icon icon="solar:magnifer-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{discoveredDevices.length}</div>
            <p className="text-xs text-muted-foreground">
              {discoveredDevices.filter((d: DiscoveredDevice) => !d.configuredDeviceId).length} unconfigured
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Agent Uptime</CardTitle>
            <Icon icon="solar:clock-circle-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {agentHealth?.data?.uptime ? Math.floor(agentHealth.data.uptime / 3600) : 0}h
            </div>
            <p className="text-xs text-muted-foreground">
              v{agentHealth?.data?.version || '1.0.0'}
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Tabs */}
      <Tabs value={selectedTab} onValueChange={setSelectedTab} className="space-y-4">
        <TabsList className="w-full justify-start">
          <TabsTrigger value="configured" className="gap-2">
            <Icon icon="solar:settings-bold" className="h-4 w-4" />
            All Devices
            {deviceConfigs.length > 0 && (
              <Badge variant="secondary" className="ml-1">{deviceConfigs.length}</Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="printers" className="gap-2">
            <Icon icon="solar:printer-bold" className="h-4 w-4" />
            Printers
            {deviceConfigs.filter(d => d.deviceType === 'printer').length > 0 && (
              <Badge variant="secondary" className="ml-1">
                {deviceConfigs.filter(d => d.deviceType === 'printer').length}
              </Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="scanners" className="gap-2">
            <Icon icon="solar:qr-code-bold" className="h-4 w-4" />
            Scanners
            {deviceConfigs.filter(d => d.deviceType === 'scanner').length > 0 && (
              <Badge variant="secondary" className="ml-1">
                {deviceConfigs.filter(d => d.deviceType === 'scanner').length}
              </Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="terminals" className="gap-2">
            <Icon icon="solar:card-bold" className="h-4 w-4" />
            Terminals
            {deviceConfigs.filter(d => d.deviceType === 'terminal').length > 0 && (
              <Badge variant="secondary" className="ml-1">
                {deviceConfigs.filter(d => d.deviceType === 'terminal').length}
              </Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="kiosks" className="gap-2">
            <Icon icon="solar:tablet-bold" className="h-4 w-4" />
            Kiosks
            {deviceConfigs.filter(d => d.deviceType === 'kiosk').length > 0 && (
              <Badge variant="secondary" className="ml-1">
                {deviceConfigs.filter(d => d.deviceType === 'kiosk').length}
              </Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="biometric" className="gap-2">
            <Icon icon="solar:hand-shake-bold" className="h-4 w-4" />
            Biometric
            {deviceConfigs.filter(d => d.deviceType === 'biometric').length > 0 && (
              <Badge variant="secondary" className="ml-1">
                {deviceConfigs.filter(d => d.deviceType === 'biometric').length}
              </Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="discovered" className="gap-2">
            <Icon icon="solar:magnifer-bold" className="h-4 w-4" />
            Discovered
            {discoveredDevices.length > 0 && (
              <Badge variant="secondary" className="ml-1">{discoveredDevices.length}</Badge>
            )}
          </TabsTrigger>
        </TabsList>

        {/* Configured Devices Tab */}
        <TabsContent value="configured" className="space-y-4">
          {configsLoading ? (
            <div className="flex items-center justify-center h-96">
              <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : deviceConfigs.length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Icon icon="solar:devices-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No devices configured</h3>
                <p className="text-muted-foreground text-center mb-4">
                  Get started by adding your first IoT device
                </p>
                <Button onClick={handleAddNew}>
                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                  Add Device
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {deviceConfigs.map((device) => (
                <Card key={device.id}>
                  <CardHeader>
                    <div className="flex items-start justify-between">
                      <div className="flex items-center gap-3">
                        <div className="p-2 bg-primary/10 rounded-lg">
                          <Icon icon={getDeviceIcon(device.deviceType)} className="h-6 w-6 text-primary" />
                        </div>
                        <div>
                          <CardTitle className="text-lg">{device.name}</CardTitle>
                          <CardDescription className="capitalize">
                            {device.driverType}
                          </CardDescription>
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        {/* Show offline if agent is not online, otherwise show device status */}
                        {agentHealth?.status !== 'online' ? (
                          <>
                            <div className={`h-2 w-2 rounded-full ${getStatusColor('offline')}`} />
                            <Badge variant="secondary" className="capitalize">
                              offline
                            </Badge>
                          </>
                        ) : (
                          <>
                            <div className={`h-2 w-2 rounded-full ${getStatusColor(device.currentStatus)}`} />
                            <Badge variant={device.currentStatus === 'ready' ? 'default' : 'secondary'} className="capitalize">
                              {device.currentStatus}
                            </Badge>
                          </>
                        )}
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent>
                    <div className="space-y-3">
                      <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">Type</span>
                        <Badge variant="outline" className="capitalize">
                          {device.deviceType}
                        </Badge>
                      </div>

                      <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">Connection</span>
                        <Badge variant="outline" className="gap-1.5">
                          <Icon icon={getConnectionIcon((device as any).connection_type || 'agent')} className="h-3.5 w-3.5" />
                          {getConnectionLabel((device as any).connection_type || 'agent')}
                        </Badge>
                      </div>

                      <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">Enabled</span>
                        <Badge variant={device.enabled ? 'default' : 'secondary'}>
                          {device.enabled ? 'Yes' : 'No'}
                        </Badge>
                      </div>
                      
                      {device.lastSeenAt && (
                        <div className="flex items-center justify-between text-sm">
                          <span className="text-muted-foreground">Last seen</span>
                          <span className="text-xs">{new Date(device.lastSeenAt).toLocaleString()}</span>
                        </div>
                      )}

                      <div className="flex gap-2 pt-2">
                        <Button
                          variant="outline"
                          size="sm"
                          className="flex-1"
                          onClick={() => reloadDeviceMutation.mutate(device.id)}
                          disabled={reloadDeviceMutation.isPending}
                        >
                          <Icon icon="solar:restart-bold" className="mr-2 h-4 w-4" />
                          Reload
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => handleEdit(device)}
                        >
                          <Icon icon="solar:pen-bold" className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => handleDelete(device.id)}
                          disabled={deleteDeviceMutation.isPending}
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </TabsContent>

        {/* Printers Tab */}
        <TabsContent value="printers" className="space-y-4">
          {configsLoading ? (
            <div className="flex items-center justify-center h-96">
              <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : deviceConfigs.filter(d => d.deviceType === 'printer').length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Icon icon="solar:printer-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No printers configured</h3>
                <p className="text-muted-foreground text-center mb-4">
                  Configure a printer from the Discovered Devices tab
                </p>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              {deviceConfigs
                .filter(d => d.deviceType === 'printer')
                .map((device) => (
                  <PrinterControl
                    key={device.id}
                    device={device}
                    agentId={agentId ?? ''}
                    agentOnline={agentHealth?.status === 'online'}
                  />
                ))}
            </div>
          )}
        </TabsContent>

        {/* Scanners Tab */}
        <TabsContent value="scanners" className="space-y-4">
          {configsLoading ? (
            <div className="flex items-center justify-center h-96">
              <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : deviceConfigs.filter(d => d.deviceType === 'scanner').length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Icon icon="solar:qr-code-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No scanners configured</h3>
                <p className="text-muted-foreground text-center mb-4">
                  Add a barcode or QR code scanner
                </p>
                <Button onClick={handleAddNew}>
                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                  Add Scanner
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              {deviceConfigs
                .filter(d => d.deviceType === 'scanner')
                .map((device) => (
                  <ScannerControl
                    key={device.id}
                    device={device}
                    agentId={agentId ?? ''}
                    agentOnline={agentHealth?.status === 'online'}
                  />
                ))}
            </div>
          )}
        </TabsContent>

        {/* Terminals Tab */}
        <TabsContent value="terminals" className="space-y-4">
          {configsLoading ? (
            <div className="flex items-center justify-center h-96">
              <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : deviceConfigs.filter(d => d.deviceType === 'terminal').length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Icon icon="solar:card-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No payment terminals configured</h3>
                <p className="text-muted-foreground text-center mb-4">
                  Add a payment terminal for card processing
                </p>
                <Button onClick={handleAddNew}>
                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                  Add Terminal
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {deviceConfigs
                .filter(d => d.deviceType === 'terminal')
                .map((device) => (
                  <Card key={device.id}>
                    <CardHeader>
                      <div className="flex items-start justify-between">
                        <div className="flex items-center gap-3">
                          <div className="p-2 bg-primary/10 rounded-lg">
                            <Icon icon={getDeviceIcon(device.deviceType)} className="h-6 w-6 text-primary" />
                          </div>
                          <div>
                            <CardTitle className="text-lg">{device.name}</CardTitle>
                            <CardDescription className="capitalize">
                              {device.driverType}
                            </CardDescription>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          {agentHealth?.status !== 'online' ? (
                            <>
                              <div className={`h-2 w-2 rounded-full ${getStatusColor('offline')}`} />
                              <Badge variant="secondary" className="capitalize">offline</Badge>
                            </>
                          ) : (
                            <>
                              <div className={`h-2 w-2 rounded-full ${getStatusColor(device.currentStatus)}`} />
                              <Badge variant={device.currentStatus === 'ready' ? 'default' : 'secondary'} className="capitalize">
                                {device.currentStatus}
                              </Badge>
                            </>
                          )}
                        </div>
                      </div>
                    </CardHeader>
                    <CardContent>
                      <div className="space-y-3">
                        <div className="flex items-center justify-between text-sm">
                          <span className="text-muted-foreground">Connection</span>
                          <Badge variant="outline" className="gap-1.5">
                            <Icon icon={getConnectionIcon((device as any).connection_type || 'agent')} className="h-3.5 w-3.5" />
                            {getConnectionLabel((device as any).connection_type || 'agent')}
                          </Badge>
                        </div>
                        <div className="flex gap-2 pt-2">
                          <Button variant="outline" size="sm" className="flex-1" onClick={() => handleEdit(device)}>
                            <Icon icon="solar:pen-bold" className="mr-2 h-4 w-4" />
                            Edit
                          </Button>
                          <Button variant="outline" size="sm" onClick={() => handleDelete(device.id)}>
                            <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                ))}
            </div>
          )}
        </TabsContent>

        {/* Kiosks Tab */}
        <TabsContent value="kiosks" className="space-y-4">
          {configsLoading ? (
            <div className="flex items-center justify-center h-96">
              <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : deviceConfigs.filter(d => d.deviceType === 'kiosk').length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Icon icon="solar:tablet-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No kiosks configured</h3>
                <p className="text-muted-foreground text-center mb-4">
                  Add a self-service kiosk device
                </p>
                <Button onClick={handleAddNew}>
                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                  Add Kiosk
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {deviceConfigs
                .filter(d => d.deviceType === 'kiosk')
                .map((device) => (
                  <Card key={device.id}>
                    <CardHeader>
                      <div className="flex items-start justify-between">
                        <div className="flex items-center gap-3">
                          <div className="p-2 bg-primary/10 rounded-lg">
                            <Icon icon={getDeviceIcon(device.deviceType)} className="h-6 w-6 text-primary" />
                          </div>
                          <div>
                            <CardTitle className="text-lg">{device.name}</CardTitle>
                            <CardDescription className="capitalize">
                              {device.driverType}
                            </CardDescription>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          {agentHealth?.status !== 'online' ? (
                            <>
                              <div className={`h-2 w-2 rounded-full ${getStatusColor('offline')}`} />
                              <Badge variant="secondary" className="capitalize">offline</Badge>
                            </>
                          ) : (
                            <>
                              <div className={`h-2 w-2 rounded-full ${getStatusColor(device.currentStatus)}`} />
                              <Badge variant={device.currentStatus === 'ready' ? 'default' : 'secondary'} className="capitalize">
                                {device.currentStatus}
                              </Badge>
                            </>
                          )}
                        </div>
                      </div>
                    </CardHeader>
                    <CardContent>
                      <div className="space-y-3">
                        <div className="flex items-center justify-between text-sm">
                          <span className="text-muted-foreground">Connection</span>
                          <Badge variant="outline" className="gap-1.5">
                            <Icon icon={getConnectionIcon((device as any).connection_type || 'agent')} className="h-3.5 w-3.5" />
                            {getConnectionLabel((device as any).connection_type || 'agent')}
                          </Badge>
                        </div>
                        <div className="flex gap-2 pt-2">
                          <Button variant="outline" size="sm" className="flex-1" onClick={() => handleEdit(device)}>
                            <Icon icon="solar:pen-bold" className="mr-2 h-4 w-4" />
                            Edit
                          </Button>
                          <Button variant="outline" size="sm" onClick={() => handleDelete(device.id)}>
                            <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                ))}
            </div>
          )}
        </TabsContent>

        {/* Biometric Tab */}
        <TabsContent value="biometric" className="space-y-4">
          {configsLoading ? (
            <div className="flex items-center justify-center h-96">
              <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : deviceConfigs.filter(d => d.deviceType === 'biometric').length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Icon icon="solar:hand-shake-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No biometric devices configured</h3>
                <p className="text-muted-foreground text-center mb-4">
                  Add a fingerprint, face recognition, or palm vein scanner
                </p>
                <Button onClick={handleAddNew}>
                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                  Add Biometric Device
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {deviceConfigs
                .filter(d => d.deviceType === 'biometric')
                .map((device) => (
                  <Card key={device.id}>
                    <CardHeader>
                      <div className="flex items-start justify-between">
                        <div className="flex items-center gap-3">
                          <div className="p-2 bg-primary/10 rounded-lg">
                            <Icon icon={getDeviceIcon(device.deviceType)} className="h-6 w-6 text-primary" />
                          </div>
                          <div>
                            <CardTitle className="text-lg">{device.name}</CardTitle>
                            <CardDescription className="capitalize">
                              {device.driverType}
                            </CardDescription>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          {agentHealth?.status !== 'online' ? (
                            <>
                              <div className={`h-2 w-2 rounded-full ${getStatusColor('offline')}`} />
                              <Badge variant="secondary" className="capitalize">offline</Badge>
                            </>
                          ) : (
                            <>
                              <div className={`h-2 w-2 rounded-full ${getStatusColor(device.currentStatus)}`} />
                              <Badge variant={device.currentStatus === 'ready' ? 'default' : 'secondary'} className="capitalize">
                                {device.currentStatus}
                              </Badge>
                            </>
                          )}
                        </div>
                      </div>
                    </CardHeader>
                    <CardContent>
                      <div className="space-y-3">
                        <div className="flex items-center justify-between text-sm">
                          <span className="text-muted-foreground">Connection</span>
                          <Badge variant="outline" className="gap-1.5">
                            <Icon icon={getConnectionIcon((device as any).connection_type || 'agent')} className="h-3.5 w-3.5" />
                            {getConnectionLabel((device as any).connection_type || 'agent')}
                          </Badge>
                        </div>
                        <div className="flex gap-2 pt-2">
                          <Button variant="outline" size="sm" className="flex-1" onClick={() => handleEdit(device)}>
                            <Icon icon="solar:pen-bold" className="mr-2 h-4 w-4" />
                            Edit
                          </Button>
                          <Button variant="outline" size="sm" onClick={() => handleDelete(device.id)}>
                            <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                ))}
            </div>
          )}
        </TabsContent>

        {/* Discovered Devices Tab */}
        <TabsContent value="discovered" className="space-y-4">
          <DiscoveredDevicesPanel
            agentId={agentId ?? ''}
            devices={discoveredDevices}
            isLoading={discoveredLoading}
            agentOnline={agentHealth?.status === 'online'}
            onRefresh={() => queryClient.invalidateQueries({ queryKey: ['discovered-devices'] })}
            onConfigureDevice={handleConfigureDiscovered}
          />
        </TabsContent>
      </Tabs>

      <DeviceConfigDialog
        open={configDialogOpen}
        onOpenChange={setConfigDialogOpen}
        device={selectedDevice}
        agentId={agentId ?? ''}
        onSuccess={() => {
          queryClient.invalidateQueries({ queryKey: ['device-configs'] })
          setConfigDialogOpen(false)
        }}
      />
      
      <AgentConfigDialog
        open={agentConfigDialogOpen}
        onOpenChange={setAgentConfigDialogOpen}
      />
    </div>
  )
}
