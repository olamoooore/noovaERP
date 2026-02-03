/**
 * Agent Connection Configuration Component
 * 
 * Configuration form for agent-based device connections
 */

import { Icon } from '@iconify/react'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import type { DeviceConnectionInfo } from '@/types/iot'
import { getConnectionPlaceholder } from '@/lib/iot-helpers'

interface AgentConnectionConfigProps {
  value: DeviceConnectionInfo
  onChange: (value: DeviceConnectionInfo) => void
  agents?: Array<{ id: string; name: string; status: string }>
}

export function AgentConnectionConfig({
  value,
  onChange,
  agents = [],
}: AgentConnectionConfigProps) {
  const updateField = (field: keyof DeviceConnectionInfo, fieldValue: any) => {
    onChange({ ...value, [field]: fieldValue })
  }

  return (
    <div className="space-y-4 p-4 bg-muted/50 rounded-lg border">
      <div className="flex items-center gap-2">
        <Icon icon="solar:server-bold-duotone" className="h-5 w-5 text-blue-600" />
        <h4 className="font-medium text-sm">Agent Connection</h4>
      </div>

      <div className="space-y-4">
        {/* Agent Selection */}
        <div className="space-y-2">
          <Label htmlFor="agent_id">
            Local Agent <span className="text-destructive">*</span>
          </Label>
          <Select
            value={value.agent_id || ''}
            onValueChange={(v) => updateField('agent_id', v)}
          >
            <SelectTrigger id="agent_id">
              <SelectValue placeholder="Select an agent" />
            </SelectTrigger>
            <SelectContent>
              {agents.length === 0 ? (
                <div className="p-2 text-sm text-muted-foreground text-center">
                  No agents available
                </div>
              ) : (
                agents.map((agent) => (
                  <SelectItem key={agent.id} value={agent.id}>
                    <div className="flex items-center gap-2">
                      <div
                        className={`h-2 w-2 rounded-full ${
                          agent.status === 'online'
                            ? 'bg-green-500'
                            : 'bg-gray-400'
                        }`}
                      />
                      <span>{agent.name}</span>
                      <Badge variant="outline" className="ml-auto text-xs">
                        {agent.status}
                      </Badge>
                    </div>
                  </SelectItem>
                ))
              )}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            Select the local agent that will manage this device
          </p>
        </div>

        {/* Device Path (Optional) */}
        <div className="space-y-2">
          <Label htmlFor="device_path">Device Path (Optional)</Label>
          <Input
            id="device_path"
            value={value.device_path || ''}
            onChange={(e) => updateField('device_path', e.target.value)}
            placeholder={getConnectionPlaceholder('agent', 'device_path')}
          />
          <p className="text-xs text-muted-foreground">
            Physical device path (e.g., /dev/usb/lp0, COM1) - Leave empty for auto-detection
          </p>
        </div>

        {/* Info Box */}
        <div className="flex items-start gap-3 p-3 bg-blue-50 dark:bg-blue-950/20 rounded-lg border border-blue-200 dark:border-blue-800">
          <Icon
            icon="solar:info-circle-bold"
            className="h-5 w-5 text-blue-600 dark:text-blue-400 mt-0.5 shrink-0"
          />
          <div className="space-y-1">
            <p className="text-sm font-medium text-blue-900 dark:text-blue-100">
              Agent-Based Connection
            </p>
            <p className="text-xs text-blue-700 dark:text-blue-300">
              The local agent must be running on the machine where the device is physically connected.
              Supports USB, serial, and network devices.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
