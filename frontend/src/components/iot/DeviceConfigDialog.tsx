import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/hooks/use-toast'
import iotApi, { DeviceConfiguration } from '@/lib/iotApi'
import type { ConnectionType, DeviceConnectionInfo } from '@/types/iot'
import { ConnectionTypeSelector } from './ConnectionTypeSelector'
import { AgentConnectionConfig } from './AgentConnectionConfig'
import { HttpConnectionConfig } from './HttpConnectionConfig'
import { MqttConnectionConfig } from './MqttConnectionConfig'
import { WebhookConnectionConfig } from './WebhookConnectionConfig'
import { validateConnectionInfo } from '@/lib/iot-helpers'

interface DeviceConfigDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  device: DeviceConfiguration | null
  agentId: string
  onSuccess: () => void
}

export function DeviceConfigDialog({
  open,
  onOpenChange,
  device,
  agentId,
  onSuccess,
}: DeviceConfigDialogProps) {
  const { toast } = useToast()
  
  // Form state
  const [name, setName] = useState('')
  const [deviceType, setDeviceType] = useState<'printer' | 'scanner' | 'terminal'>('printer')
  const [driverType, setDriverType] = useState('label-printer-raw')
  const [connectionType, setConnectionType] = useState<ConnectionType>('agent')
  const [connectionInfo, setConnectionInfo] = useState<DeviceConnectionInfo>({ type: 'agent' })
  const [enabled, setEnabled] = useState(true)

  // Initialize form when device changes
  useEffect(() => {
    if (device && open) {
      setName(device.name)
      setDeviceType(device.deviceType)
      setDriverType(device.driverType)
      setEnabled(device.enabled)
      
      // Map connectionInfo to form state
      const oldConnectionInfo = device.connectionInfo || {}
      
      // Determine connection type from existing data (new format first, then legacy)
      if (oldConnectionInfo.type) {
        // New multi-protocol format
        setConnectionType(oldConnectionInfo.type)
        setConnectionInfo(oldConnectionInfo as DeviceConnectionInfo)
      } else if (oldConnectionInfo.vendorId || oldConnectionInfo.productId || oldConnectionInfo.port) {
        // Legacy USB device format - treat as agent connection
        setConnectionType('agent')
        setConnectionInfo({
          type: 'agent',
          agent_id: agentId,
          device_path: oldConnectionInfo.port || '',
          ump_device_id: (oldConnectionInfo as any).ump_device_id,
        })
      } else {
        // Default to agent
        setConnectionType('agent')
        setConnectionInfo({ type: 'agent', agent_id: agentId })
      }
    } else if (open) {
      // Reset form for new device
      setName('')
      setDeviceType('printer')
      setDriverType('label-printer-raw')
      setConnectionType('agent')
      setConnectionInfo({ type: 'agent', agent_id: agentId })
      setEnabled(true)
    }
  }, [device, open, agentId])

  // Update connection info when connection type changes
  const handleConnectionTypeChange = (type: ConnectionType) => {
    setConnectionType(type)
    if (type === 'agent') {
      setConnectionInfo({ type: 'agent', agent_id: agentId })
    } else {
      setConnectionInfo({ type })
    }
  }

  const isEditingExistingDevice = !!(device && device.id && device.id.trim() !== '')

  // Validate form
  const validation = validateConnectionInfo(connectionType, connectionInfo)
  const isFormValid = name.trim() !== '' && driverType.trim() !== '' && validation.valid

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        device_type: deviceType,
        driver_type: driverType,
        name: name.trim(),
        enabled,
        connection_type: connectionType,
        connection_info: connectionInfo,
        driver_config: {},
        capabilities: [],
      }

      if (device && device.id && device.id.trim() !== '') {
        return await iotApi.updateDeviceConfig(agentId, device.id, payload)
      } else {
        return await iotApi.createDeviceConfig(agentId, payload)
      }
    },
    onSuccess: () => {
      toast({
        title: device ? 'Device updated' : 'Device created',
        description: device ? 'Device has been updated successfully' : 'Device has been created successfully',
      })
      onSuccess()
      onOpenChange(false)
    },
    onError: (error: any) => {
      console.error('Save device error:', error)
      const errorMessage = error.response?.data?.message || error.message || 'Failed to save device'
      toast({
        title: 'Error',
        description: errorMessage,
        variant: 'destructive',
      })
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (isFormValid) {
      saveMutation.mutate()
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {isEditingExistingDevice ? 'Edit Device Configuration' : 'Add New Device'}
          </DialogTitle>
          <DialogDescription>
            Configure your IoT device with multi-protocol support
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-6">
          {/* Basic Information */}
          <div className="space-y-4">
            <h3 className="text-sm font-semibold flex items-center gap-2">
              <Icon icon="solar:settings-bold-duotone" className="h-4 w-4 text-muted-foreground" />
              Basic Information
            </h3>

            <div className="space-y-2">
              <Label htmlFor="name">Device Name *</Label>
              <Input
                id="name"
                placeholder="e.g., Label Printer - Station 1"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="deviceType">Device Type *</Label>
                <Select value={deviceType} onValueChange={(value) => setDeviceType(value as any)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="printer">
                      <div className="flex items-center gap-2">
                        <Icon icon="solar:printer-bold-duotone" className="h-4 w-4" />
                        Printer
                      </div>
                    </SelectItem>
                    <SelectItem value="scanner">
                      <div className="flex items-center gap-2">
                        <Icon icon="solar:qr-code-bold-duotone" className="h-4 w-4" />
                        Scanner
                      </div>
                    </SelectItem>
                    <SelectItem value="terminal">
                      <div className="flex items-center gap-2">
                        <Icon icon="solar:card-bold-duotone" className="h-4 w-4" />
                        Payment Terminal
                      </div>
                    </SelectItem>
                    <SelectItem value="kiosk">
                      <div className="flex items-center gap-2">
                        <Icon icon="solar:tablet-bold-duotone" className="h-4 w-4" />
                        Kiosk
                      </div>
                    </SelectItem>
                    <SelectItem value="biometric">
                      <div className="flex items-center gap-2">
                        <Icon icon="solar:hand-shake-bold-duotone" className="h-4 w-4" />
                        Biometric Device
                      </div>
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="driverType">Driver Type *</Label>
                <Select value={driverType} onValueChange={setDriverType}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {deviceType === 'printer' && (
                      <>
                        <SelectItem value="label-printer-raw">Label Printer (RAW)</SelectItem>
                        <SelectItem value="receipt-printer-raw">Receipt Printer (RAW)</SelectItem>
                        <SelectItem value="escpos">ESC/POS</SelectItem>
                        <SelectItem value="tspl">TSPL</SelectItem>
                        <SelectItem value="zpl">ZPL</SelectItem>
                      </>
                    )}
                    {deviceType === 'scanner' && (
                      <>
                        <SelectItem value="barcode-scanner">Barcode Scanner</SelectItem>
                        <SelectItem value="qr-scanner">QR Code Scanner</SelectItem>
                        <SelectItem value="scanner-hid">HID Scanner</SelectItem>
                      </>
                    )}
                    {deviceType === 'terminal' && (
                      <>
                        <SelectItem value="stripe-terminal">Stripe Terminal</SelectItem>
                        <SelectItem value="hitpay-terminal">HitPay Terminal</SelectItem>
                        <SelectItem value="terminal-mock">Mock Terminal (Testing)</SelectItem>
                      </>
                    )}
                    {deviceType === 'kiosk' && (
                      <>
                        <SelectItem value="kiosk-standard">Standard Kiosk</SelectItem>
                        <SelectItem value="kiosk-web">Web-Based Kiosk</SelectItem>
                        <SelectItem value="kiosk-custom">Custom Kiosk</SelectItem>
                      </>
                    )}
                    {deviceType === 'biometric' && (
                      <>
                        <SelectItem value="fingerprint-scanner">Fingerprint Scanner</SelectItem>
                        <SelectItem value="face-recognition">Face Recognition</SelectItem>
                        <SelectItem value="palm-vein-scanner">Palm Vein Scanner</SelectItem>
                        <SelectItem value="iris-scanner">Iris Scanner</SelectItem>
                      </>
                    )}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>

          {/* Connection Type Selector */}
          <div className="space-y-4">
            <h3 className="text-sm font-semibold">Connection Type</h3>
            <ConnectionTypeSelector
              value={connectionType}
              onChange={handleConnectionTypeChange}
            />
          </div>

          {/* Protocol-Specific Configuration */}
          <div className="space-y-4">
            <h3 className="text-sm font-semibold">Connection Configuration</h3>
            
            {connectionType === 'agent' && (
              <AgentConnectionConfig
                value={connectionInfo}
                onChange={setConnectionInfo}
                agents={[{ id: agentId, name: 'Local Agent', status: 'online' }]}
              />
            )}

            {connectionType === 'http' && (
              <HttpConnectionConfig
                value={connectionInfo}
                onChange={setConnectionInfo}
              />
            )}

            {connectionType === 'mqtt' && (
              <MqttConnectionConfig
                value={connectionInfo}
                onChange={setConnectionInfo}
                deviceName={name}
              />
            )}

            {connectionType === 'webhook' && (
              <WebhookConnectionConfig
                value={connectionInfo}
                onChange={setConnectionInfo}
              />
            )}

            {/* Validation Errors */}
            {!validation.valid && (
              <div className="p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
                <p className="text-sm font-medium text-destructive mb-2">
                  Configuration Errors:
                </p>
                <ul className="list-disc list-inside space-y-1">
                  {validation.errors.map((error, index) => (
                    <li key={index} className="text-sm text-destructive">
                      {error}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>

          {/* Enable Device Toggle */}
          <div className="flex items-center justify-between p-4 bg-muted/50 rounded-lg">
            <div className="space-y-0.5">
              <Label htmlFor="enabled">Enable Device</Label>
              <p className="text-sm text-muted-foreground">
                Device will be active and ready to use
              </p>
            </div>
            <Switch
              id="enabled"
              checked={enabled}
              onCheckedChange={setEnabled}
            />
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={saveMutation.isPending}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={!isFormValid || saveMutation.isPending}>
              {saveMutation.isPending ? (
                <>
                  <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                  Saving...
                </>
              ) : (
                <>
                  <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                  {isEditingExistingDevice ? 'Update' : 'Create'}
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
