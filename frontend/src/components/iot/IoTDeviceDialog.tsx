import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
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
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import type { ConnectionType, DeviceType, DeviceConnectionInfo, IoTDevice } from '@/types/iot'
import { ConnectionTypeSelector } from './ConnectionTypeSelector'
import { AgentConnectionConfig } from './AgentConnectionConfig'
import { HttpConnectionConfig } from './HttpConnectionConfig'
import { MqttConnectionConfig } from './MqttConnectionConfig'
import { WebhookConnectionConfig } from './WebhookConnectionConfig'
import { validateConnectionInfo } from '@/lib/iot-helpers'

interface IoTDeviceDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  device: IoTDevice | null
  onSuccess: () => void
  agents?: Array<{ id: string; name: string; status: string }>
}


export function IoTDeviceDialog({ open, onOpenChange, device, onSuccess, agents = [] }: IoTDeviceDialogProps) {
  const { toast } = useToast()
  
  // Form state
  const [name, setName] = useState('')
  const [deviceType, setDeviceType] = useState<DeviceType>('printer')
  const [driverType, setDriverType] = useState('')
  const [connectionType, setConnectionType] = useState<ConnectionType>('agent')
  const [connectionInfo, setConnectionInfo] = useState<DeviceConnectionInfo>({ type: 'agent' })
  const [capabilities, setCapabilities] = useState<string[]>([])
  const [enabled, setEnabled] = useState(true)

  // Initialize form when device changes
  useEffect(() => {
    if (device) {
      setName(device.name)
      setDeviceType(device.device_type)
      setDriverType(device.driver_type || '')
      setConnectionType(device.connection_type)
      setConnectionInfo(device.connection_info || { type: device.connection_type })
      setCapabilities(device.capabilities || [])
      setEnabled(device.enabled !== false)
    } else {
      // Reset form for new device
      setName('')
      setDeviceType('printer')
      setDriverType('')
      setConnectionType('agent')
      setConnectionInfo({ type: 'agent' })
      setCapabilities([])
      setEnabled(true)
    }
  }, [device, open])

  // Update connection info when connection type changes
  const handleConnectionTypeChange = (type: ConnectionType) => {
    setConnectionType(type)
    setConnectionInfo({ type })
  }

  // Validate form
  const validation = validateConnectionInfo(connectionType, connectionInfo)
  const isFormValid = name.trim() !== '' && driverType.trim() !== '' && validation.valid

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        name: name.trim(),
        device_type: deviceType,
        driver_type: driverType.trim(),
        connection_type: connectionType,
        connection_info: connectionInfo,
        capabilities,
        enabled,
      }

      if (device) {
        return await api.put(`/iot/device-configurations/${device.id}`, payload)
      } else {
        return await api.post('/iot/device-configurations', payload)
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
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to save device',
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
      <DialogContent className="sm:max-w-[600px] max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{device ? 'Edit Device' : 'Add New Device'}</DialogTitle>
          <DialogDescription>
            {device ? 'Update device configuration' : 'Configure a new IoT device with multi-protocol support'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-6">
          {/* Basic Information */}
          <div className="space-y-4">
            <h3 className="text-sm font-semibold">Basic Information</h3>
            
            <div className="space-y-2">
              <Label htmlFor="name">Device Name *</Label>
              <Input
                id="name"
                placeholder="e.g., Counter 1 Receipt Printer"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="device_type">Device Type *</Label>
                <Select value={deviceType} onValueChange={(value) => setDeviceType(value as DeviceType)}>
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
                <Label htmlFor="driver_type">Driver/Protocol *</Label>
                <Select value={driverType} onValueChange={setDriverType}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select driver type" />
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
                agents={agents}
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
              {saveMutation.isPending && (
                <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
              )}
              {device ? 'Update' : 'Create'} Device
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
