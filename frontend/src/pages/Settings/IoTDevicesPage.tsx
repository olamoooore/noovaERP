import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tantml:react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { IoTDeviceDialog } from '@/components/iot/IoTDeviceDialog'
import { LocalAgentCard } from '@/components/iot/LocalAgentCard'
import { AgentSetupDialog } from '@/components/iot/AgentSetupDialog'
import { DeviceLogsDialog } from '@/components/iot/DeviceLogsDialog'
import { IoTTemplatesPage } from '@/components/iot/IoTTemplatesPage'
import { SaveAsTemplateDialog } from '@/components/iot/SaveAsTemplateDialog'
import { MonitoringDashboard } from '@/components/iot/MonitoringDashboard'
import { LinkMiddleware } from '@/components/iot/LinkMiddleware'
import { PrinterSetup } from '@/components/iot/PrinterSetup'
import type { IoTDevice } from '@/types/iot'
import { getConnectionIcon, getConnectionLabel } from '@/lib/iot-helpers'

interface LocalAgent {
  id: number
  name: string
  agent_id: string
  status: 'online' | 'offline' | 'error'
  version: string
  last_seen_at: string | null
  config: {
    hostname?: string
    os?: string
    ip_address?: string
  }
}

interface Feature {
  id: string
  name: string
  key: string
  description: string
  is_core: boolean
  enabled: boolean
}

export function IoTDevicesPage() {
  const [dialogOpen, setDialogOpen] = useState(false)
  const [selectedDevice, setSelectedDevice] = useState<IoTDevice | null>(null)
  const [showSettings, setShowSettings] = useState(false)
  const [setupDialogOpen, setSetupDialogOpen] = useState(false)
  const [logsDialogOpen, setLogsDialogOpen] = useState(false)
  const [selectedDeviceForLogs, setSelectedDeviceForLogs] = useState<{ id: number; name: string } | null>(null)
  const [saveTemplateDialogOpen, setSaveTemplateDialogOpen] = useState(false)
  const [selectedDeviceForTemplate, setSelectedDeviceForTemplate] = useState<IoTDevice | null>(null)
  const { toast } = useToast()
  const queryClient = useQueryClient()

  // Fetch IoT feature status
  const { data: features } = useQuery<Feature[]>({
    queryKey: ['features'],
    queryFn: async () => {
      const response = await api.get('/features')
      return response.data
    },
  })

  const iotFeature = features?.find(f => f.key === 'iot')

  // Toggle module enabled/disabled
  const toggleModuleMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!iotFeature) throw new Error('IoT feature not found')
      await api.post(`/features/${iotFeature.id}/toggle`, { enabled })
    },
    onSuccess: () => {
      toast({
        title: 'Module updated',
        description: `NoovaERP IoT Hub has been ${iotFeature?.enabled ? 'disabled' : 'enabled'}`,
      })
      queryClient.invalidateQueries({ queryKey: ['features'] })
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to update module status',
        variant: 'destructive',
      })
    },
  })

  // Fetch devices
  const { data: devices, isLoading } = useQuery<IoTDevice[]>({
    queryKey: ['iot-devices'],
    queryFn: async () => {
      const response = await api.get('/iot/devices')
      return response.data
    },
  })

  // Fetch local agents
  const { data: agents, isLoading: agentsLoading } = useQuery<LocalAgent[]>({
    queryKey: ['local-agents'],
    queryFn: async () => {
      const response = await api.get('/iot/devices')
      // Filter devices where type is 'agent'
      return response.data.filter((d: IoTDevice) => d.type === 'agent')
    },
  })

  // Test device connection
  const testDeviceMutation = useMutation({
    mutationFn: async (deviceId: number) => {
      const response = await api.post(`/iot/devices/${deviceId}/test`)
      return response.data
    },
    onSuccess: (data) => {
      toast({
        title: data.success ? 'Connection successful' : 'Connection failed',
        description: data.message,
        variant: data.success ? 'default' : 'destructive',
      })
      queryClient.invalidateQueries({ queryKey: ['iot-devices'] })
    },
    onError: () => {
      toast({
        title: 'Test failed',
        description: 'Failed to test device connection',
        variant: 'destructive',
      })
    },
  })

  // Delete device
  const deleteDeviceMutation = useMutation({
    mutationFn: async (deviceId: number) => {
      await api.delete(`/iot/devices/${deviceId}`)
    },
    onSuccess: () => {
      toast({
        title: 'Device deleted',
        description: 'Device has been removed successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['iot-devices'] })
    },
  })

  const getDeviceIcon = (type: string, vendor?: string, connectionType?: string) => {
    switch (type) {
      case 'printer':
        // Use label icon for USB printers (typically label printers)
        if (connectionType === 'usb' || vendor?.toLowerCase().includes('gprinter')) {
          return 'solar:tag-bold-duotone'
        }
        return 'solar:printer-bold-duotone'
      case 'terminal':
        return 'solar:card-bold-duotone'
      case 'scanner':
        return 'solar:qr-code-bold-duotone'
      case 'kiosk':
        return 'solar:monitor-bold-duotone'
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

  const handleEdit = (device: IoTDevice) => {
    setSelectedDevice(device)
    setDialogOpen(true)
  }

  const handleAddNew = () => {
    setSelectedDevice(null)
    setDialogOpen(true)
  }

  const handleViewAgentDevices = (agent: LocalAgent) => {
    // Filter devices connected to this agent
    toast({
      title: 'Agent Devices',
      description: `Viewing devices for ${agent.name}`,
    })
  }

  const handleViewAgentLogs = (agent: LocalAgent) => {
    setSelectedDeviceForLogs({ id: agent.id, name: agent.name })
    setLogsDialogOpen(true)
  }

  const handleViewDeviceLogs = (device: IoTDevice) => {
    setSelectedDeviceForLogs({ id: device.id, name: device.name })
    setLogsDialogOpen(true)
  }

  const handlePrintLabel = (device: IoTDevice) => {
    // For now, show a toast indicating this will open the print label modal
    // This will be implemented in the next task
    toast({
      title: 'Print Label',
      description: `Print label functionality for ${device.name} will be available soon`,
    })
  }

  const handleSaveAsTemplate = (device: IoTDevice) => {
    setSelectedDeviceForTemplate(device)
    setSaveTemplateDialogOpen(true)
  }

  // Filter out agents from regular devices
  const regularDevices = devices?.filter(d => d.type !== 'agent') || []
  const onlineAgentsCount = agents?.filter(a => a.status === 'online').length || 0
  const onlineDevicesCount = regularDevices?.filter(d => d.status === 'online').length || 0

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-96">
        <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
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
          <Button variant="outline" onClick={() => setShowSettings(!showSettings)}>
            <Icon icon="solar:settings-bold" className="mr-2 h-4 w-4" />
            Settings
          </Button>
          <Button variant="outline" onClick={() => setSetupDialogOpen(true)}>
            <Icon icon="solar:download-bold" className="mr-2 h-4 w-4" />
            Install Agent
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
            <CardTitle className="text-sm font-medium">Local Agents</CardTitle>
            <Icon icon="solar:server-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{agents?.length || 0}</div>
            <p className="text-xs text-muted-foreground">
              {onlineAgentsCount} online
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Connected Devices</CardTitle>
            <Icon icon="solar:devices-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{regularDevices?.length || 0}</div>
            <p className="text-xs text-muted-foreground">
              {onlineDevicesCount} online
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Payment Terminals</CardTitle>
            <Icon icon="solar:card-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {regularDevices?.filter(d => d.type === 'terminal').length || 0}
            </div>
            <p className="text-xs text-muted-foreground">
              {regularDevices?.filter(d => d.type === 'terminal' && d.status === 'online').length || 0} active
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Printers</CardTitle>
            <Icon icon="solar:printer-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {regularDevices?.filter(d => d.type === 'printer').length || 0}
            </div>
            <p className="text-xs text-muted-foreground">
              {regularDevices?.filter(d => d.type === 'printer' && d.status === 'online').length || 0} active
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Settings Panel */}
      {showSettings && (
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Module Settings</CardTitle>
              <CardDescription>Configure NoovaERP IoT Hub module preferences</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="module-enabled" className="text-base">Enable Module</Label>
                  <p className="text-sm text-muted-foreground">
                    Turn on or off the NoovaERP IoT Hub module for your organization
                  </p>
                </div>
                <Switch
                  id="module-enabled"
                  checked={iotFeature?.enabled || false}
                  onCheckedChange={(checked) => toggleModuleMutation.mutate(checked)}
                  disabled={toggleModuleMutation.isPending}
                />
              </div>
              <Separator />
              <div className="flex items-start gap-3 p-3 bg-muted/50 rounded-lg">
                <Icon icon="solar:info-circle-bold" className="h-5 w-5 text-primary mt-0.5" />
                <div className="space-y-1">
                  <p className="text-sm font-medium">About NoovaERP IoT Hub</p>
                  <p className="text-xs text-muted-foreground">
                    Connect and manage IoT devices including printers, payment terminals, barcode scanners, and self-service kiosks. 
                    Supports multiple connection types: LAN, Cloud API, Local Agent, and Bluetooth.
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* UMP Middleware Connection */}
          <LinkMiddleware onLinked={(agentId) => {
            toast({
              title: 'Agent Linked',
              description: `Successfully linked to agent: ${agentId}`,
            })
            queryClient.invalidateQueries({ queryKey: ['iot-devices'] })
          }} />

          {/* Printer Setup */}
          <PrinterSetup onPrinterSelected={(type, deviceId) => {
            console.log(`${type} printer selected:`, deviceId)
          }} />
        </div>
      )}

      {/* Tabs for Agents, Devices, Templates, and Monitoring */}
      <Tabs defaultValue="devices" className="space-y-4">
        <TabsList>
          <TabsTrigger value="devices" className="gap-2">
            <Icon icon="solar:devices-bold" className="h-4 w-4" />
            Devices
            {regularDevices && regularDevices.length > 0 && (
              <Badge variant="secondary" className="ml-1">{regularDevices.length}</Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="agents" className="gap-2">
            <Icon icon="solar:server-bold" className="h-4 w-4" />
            Local Agents
            {agents && agents.length > 0 && (
              <Badge variant="secondary" className="ml-1">{agents.length}</Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="templates" className="gap-2">
            <Icon icon="solar:widget-4-bold" className="h-4 w-4" />
            Templates
          </TabsTrigger>
          <TabsTrigger value="monitoring" className="gap-2">
            <Icon icon="solar:monitor-bold" className="h-4 w-4" />
            Monitoring
          </TabsTrigger>
        </TabsList>

        {/* Devices Tab */}
        <TabsContent value="devices" className="space-y-4">
          {isLoading ? (
            <div className="flex items-center justify-center h-96">
              <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : regularDevices.length === 0 ? (
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
              {regularDevices.map((device) => (
                <Card key={device.id}>
                  <CardHeader>
                    <div className="flex items-start justify-between">
                      <div className="flex items-center gap-3">
                        <div className="p-2 bg-primary/10 rounded-lg">
                          <Icon icon={getDeviceIcon(device.type, device.vendor, device.connection_type)} className="h-6 w-6 text-primary" />
                        </div>
                        <div>
                          <CardTitle className="text-lg">{device.name}</CardTitle>
                          <CardDescription className="capitalize">
                            {device.vendor} {device.type}
                            {device.type === 'printer' && device.connection_type === 'usb' && (
                              <span className="text-xs text-blue-600 ml-2">(Label Printer)</span>
                            )}
                          </CardDescription>
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        <div className={`h-2 w-2 rounded-full ${getStatusColor(device.status)}`} />
                        <Badge variant={device.status === 'online' ? 'default' : 'secondary'} className="capitalize">
                          {device.status}
                        </Badge>
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent>
                    <div className="space-y-3">
                      <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">Connection</span>
                        <Badge variant="outline" className="gap-1.5">
                          <Icon icon={getConnectionIcon(device.connection_type)} className="h-3.5 w-3.5" />
                          {getConnectionLabel(device.connection_type)}
                        </Badge>
                      </div>
                      
                      {device.last_seen_at && (
                        <div className="flex items-center justify-between text-sm">
                          <span className="text-muted-foreground">Last seen</span>
                          <span>{new Date(device.last_seen_at).toLocaleString()}</span>
                        </div>
                      )}

                      <div className="flex gap-2 pt-2">
                        <Button
                          variant="outline"
                          size="sm"
                          className="flex-1"
                          onClick={() => testDeviceMutation.mutate(device.id)}
                          disabled={testDeviceMutation.isPending}
                        >
                          <Icon icon="solar:play-circle-bold" className="mr-2 h-4 w-4" />
                          Test
                        </Button>
                        {device.type === 'printer' && device.connection_type === 'usb' && (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handlePrintLabel(device)}
                          >
                            <Icon icon="solar:tag-bold" className="h-4 w-4" />
                          </Button>
                        )}
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
                          onClick={() => handleViewDeviceLogs(device)}
                        >
                          <Icon icon="solar:document-text-bold" className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => handleSaveAsTemplate(device)}
                          title="Save as Template"
                        >
                          <Icon icon="solar:bookmark-bold" className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => {
                            if (confirm('Are you sure you want to delete this device?')) {
                              deleteDeviceMutation.mutate(device.id)
                            }
                          }}
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

        {/* Local Agents Tab */}
        <TabsContent value="agents" className="space-y-4">
          {agentsLoading ? (
            <div className="flex items-center justify-center h-96">
              <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : !agents || agents.length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Icon icon="solar:server-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No local agents installed</h3>
                <p className="text-muted-foreground text-center mb-4 max-w-md">
                  Install the NoovaERP IoT Local Agent on your Windows POS machines to connect USB devices like payment terminals and printers
                </p>
                <Button onClick={() => setSetupDialogOpen(true)}>
                  <Icon icon="solar:download-bold" className="mr-2 h-4 w-4" />
                  Download & Install Agent
                </Button>
              </CardContent>
            </Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {agents.map((agent) => (
                <LocalAgentCard
                  key={agent.id}
                  agent={agent}
                  onViewDevices={handleViewAgentDevices}
                  onViewLogs={handleViewAgentLogs}
                />
              ))}
            </div>
          )}
        </TabsContent>

        {/* Templates Tab */}
        <TabsContent value="templates">
          <IoTTemplatesPage />
        </TabsContent>

        {/* Monitoring Tab */}
        <TabsContent value="monitoring">
          <MonitoringDashboard />
        </TabsContent>
      </Tabs>

      <IoTDeviceDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        device={selectedDevice}
        onSuccess={() => {
          queryClient.invalidateQueries({ queryKey: ['iot-devices'] })
          queryClient.invalidateQueries({ queryKey: ['local-agents'] })
          setDialogOpen(false)
        }}
      />

      <AgentSetupDialog
        open={setupDialogOpen}
        onOpenChange={setSetupDialogOpen}
      />

      <DeviceLogsDialog
        open={logsDialogOpen}
        onOpenChange={setLogsDialogOpen}
        deviceId={selectedDeviceForLogs?.id || null}
        deviceName={selectedDeviceForLogs?.name || ''}
      />

      <SaveAsTemplateDialog
        open={saveTemplateDialogOpen}
        onOpenChange={setSaveTemplateDialogOpen}
        device={selectedDeviceForTemplate}
        onSuccess={() => {
          setSaveTemplateDialogOpen(false)
          toast({
            title: 'Template saved',
            description: 'Device configuration has been saved as a template',
          })
        }}
      />
    </div>
  )
}
