/**
 * MQTT Connection Configuration Component
 * 
 * Configuration form for MQTT protocol device connections
 */

import { Icon } from '@iconify/react'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import type { DeviceConnectionInfo } from '@/types/iot'
import { getConnectionPlaceholder } from '@/lib/iot-helpers'

interface MqttConnectionConfigProps {
  value: DeviceConnectionInfo
  onChange: (value: DeviceConnectionInfo) => void
  deviceName?: string
}

/**
 * Generate a URL-safe slug from device name
 */
function generateSlug(name: string): string {
  return name
    .toLowerCase()
    .trim()
    .replace(/[^\w\s-]/g, '') // Remove special characters
    .replace(/\s+/g, '-') // Replace spaces with hyphens
    .replace(/-+/g, '-') // Replace multiple hyphens with single hyphen
    .substring(0, 50) // Limit length
}

/**
 * Generate a short random ID
 */
function generateShortId(): string {
  return Math.random().toString(36).substring(2, 8)
}

/**
 * Generate recommended MQTT topics based on device name
 */
function generateRecommendedTopics(deviceName: string) {
  const slug = generateSlug(deviceName || 'device')
  const shortId = generateShortId()
  const deviceId = slug ? `${slug}-${shortId}` : `device-${shortId}`
  
  return {
    commandTopic: `noovaerp-iot/devices/${deviceId}/commands`,
    responseTopic: `noovaerp-iot/devices/${deviceId}/responses`,
    clientId: `noovaerp-iot-${deviceId}`,
  }
}

export function MqttConnectionConfig({
  value,
  onChange,
  deviceName,
}: MqttConnectionConfigProps) {
  const updateField = (field: keyof DeviceConnectionInfo, fieldValue: any) => {
    onChange({ ...value, [field]: fieldValue })
  }

  const updateTlsConfig = (field: string, fieldValue: any) => {
    onChange({
      ...value,
      tls: {
        enabled: value.tls?.enabled || false,
        ...value.tls,
        [field]: fieldValue,
      },
    })
  }

  const handleUseRecommendedTopics = () => {
    const recommended = generateRecommendedTopics(deviceName || 'device')
    onChange({
      ...value,
      topic_command: recommended.commandTopic,
      topic_response: recommended.responseTopic,
      client_id: recommended.clientId,
    })
  }

  const tlsEnabled = value.tls?.enabled || false

  return (
    <div className="space-y-4 p-4 bg-muted/50 rounded-lg border">
      <div className="flex items-center gap-2">
        <Icon icon="solar:cloud-bold-duotone" className="h-5 w-5 text-purple-600" />
        <h4 className="font-medium text-sm">MQTT Connection</h4>
      </div>

      <div className="space-y-4">
        {/* Broker URL */}
        <div className="space-y-2">
          <Label htmlFor="broker_url">
            Broker URL <span className="text-destructive">*</span>
          </Label>
          <Input
            id="broker_url"
            value={value.broker_url || ''}
            onChange={(e) => updateField('broker_url', e.target.value)}
            placeholder={getConnectionPlaceholder('mqtt', 'broker_url')}
          />
          <p className="text-xs text-muted-foreground">
            MQTT broker address (e.g., mqtt://broker.hivemq.com:1883)
          </p>
        </div>

        {/* Quick Setup - Auto-generate Topics */}
        <div className="p-4 bg-blue-50 dark:bg-blue-950/20 rounded-lg border border-blue-200 dark:border-blue-800 space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Icon icon="solar:magic-stick-bold-duotone" className="h-5 w-5 text-blue-600 dark:text-blue-400" />
              <h4 className="font-medium text-sm text-blue-900 dark:text-blue-100">Quick Setup</h4>
            </div>
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={handleUseRecommendedTopics}
              className="border-blue-300 hover:bg-blue-100 dark:border-blue-700 dark:hover:bg-blue-900/50"
            >
              <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
              Use Recommended Topics
            </Button>
          </div>
          <p className="text-xs text-blue-700 dark:text-blue-300">
            Auto-generate MQTT topics and client ID based on your device name. This ensures unique, organized topic structure.
          </p>
        </div>

        {/* Topics */}
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor="topic_command">
              Command Topic <span className="text-destructive">*</span>
            </Label>
            <Input
              id="topic_command"
              value={value.topic_command || ''}
              onChange={(e) => updateField('topic_command', e.target.value)}
              placeholder={getConnectionPlaceholder('mqtt', 'topic_command')}
            />
            <p className="text-xs text-muted-foreground">
              Topic where device receives commands
            </p>
          </div>
          <div className="space-y-2">
            <Label htmlFor="topic_response">Response Topic</Label>
            <Input
              id="topic_response"
              value={value.topic_response || ''}
              onChange={(e) => updateField('topic_response', e.target.value)}
              placeholder={getConnectionPlaceholder('mqtt', 'topic_response')}
            />
            <p className="text-xs text-muted-foreground">
              Topic where device sends responses
            </p>
          </div>
        </div>

        {/* Client ID */}
        <div className="space-y-2">
          <Label htmlFor="client_id">
            Client ID <span className="text-destructive">*</span>
          </Label>
          <Input
            id="client_id"
            value={value.client_id || ''}
            onChange={(e) => updateField('client_id', e.target.value)}
            placeholder={getConnectionPlaceholder('mqtt', 'client_id')}
          />
          <p className="text-xs text-muted-foreground">
            Unique identifier for this MQTT client
          </p>
        </div>

        {/* Authentication */}
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor="username">Username (Optional)</Label>
            <Input
              id="username"
              value={value.username || ''}
              onChange={(e) => updateField('username', e.target.value)}
              placeholder={getConnectionPlaceholder('mqtt', 'username')}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="password">Password (Optional)</Label>
            <Input
              id="password"
              type="password"
              value={value.password || ''}
              onChange={(e) => updateField('password', e.target.value)}
              placeholder={getConnectionPlaceholder('mqtt', 'password')}
            />
          </div>
        </div>

        {/* QoS Level */}
        <div className="space-y-2">
          <Label htmlFor="qos">Quality of Service (QoS)</Label>
          <Select
            value={String(value.qos || 1)}
            onValueChange={(v) => updateField('qos', parseInt(v))}
          >
            <SelectTrigger id="qos">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="0">QoS 0 - At most once</SelectItem>
              <SelectItem value="1">QoS 1 - At least once</SelectItem>
              <SelectItem value="2">QoS 2 - Exactly once</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {/* TLS/SSL */}
        <div className="space-y-4 pt-4 border-t">
          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <Label htmlFor="tls_enabled">Enable TLS/SSL</Label>
              <p className="text-xs text-muted-foreground">
                Secure connection with encryption
              </p>
            </div>
            <Switch
              id="tls_enabled"
              checked={tlsEnabled}
              onCheckedChange={(checked) => updateTlsConfig('enabled', checked)}
            />
          </div>

          {tlsEnabled && (
            <div className="space-y-4 pl-4 border-l-2">
              <div className="space-y-2">
                <Label htmlFor="ca_cert">CA Certificate (Optional)</Label>
                <Input
                  id="ca_cert"
                  value={value.tls?.ca_cert || ''}
                  onChange={(e) => updateTlsConfig('ca_cert', e.target.value)}
                  placeholder="Path to CA certificate"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="client_cert">Client Certificate (Optional)</Label>
                <Input
                  id="client_cert"
                  value={value.tls?.client_cert || ''}
                  onChange={(e) => updateTlsConfig('client_cert', e.target.value)}
                  placeholder="Path to client certificate"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="client_key">Client Key (Optional)</Label>
                <Input
                  id="client_key"
                  type="password"
                  value={value.tls?.client_key || ''}
                  onChange={(e) => updateTlsConfig('client_key', e.target.value)}
                  placeholder="Path to client key"
                />
              </div>
            </div>
          )}
        </div>

        {/* Common Topic Patterns */}
        <div className="p-3 bg-muted/30 rounded-lg border space-y-2">
          <div className="flex items-center gap-2">
            <Icon icon="solar:book-bold-duotone" className="h-4 w-4 text-muted-foreground" />
            <h5 className="text-xs font-medium text-muted-foreground">Common Topic Patterns</h5>
          </div>
          <div className="space-y-1 text-xs text-muted-foreground">
            <div className="flex items-start gap-2">
              <span className="text-primary">•</span>
              <span><code className="bg-muted px-1 rounded">noovaerp-iot/devices/&#123;device-id&#125;/commands</code> - Device commands</span>
            </div>
            <div className="flex items-start gap-2">
              <span className="text-primary">•</span>
              <span><code className="bg-muted px-1 rounded">noovaerp-iot/devices/&#123;device-id&#125;/responses</code> - Device responses</span>
            </div>
            <div className="flex items-start gap-2">
              <span className="text-primary">•</span>
              <span><code className="bg-muted px-1 rounded">noovaerp-iot/devices/&#123;device-id&#125;/status</code> - Device status updates</span>
            </div>
          </div>
        </div>

        {/* Info Box */}
        <div className="flex items-start gap-3 p-3 bg-purple-50 dark:bg-purple-950/20 rounded-lg border border-purple-200 dark:border-purple-800">
          <Icon
            icon="solar:info-circle-bold"
            className="h-5 w-5 text-purple-600 dark:text-purple-400 mt-0.5 shrink-0"
          />
          <div className="space-y-1">
            <p className="text-sm font-medium text-purple-900 dark:text-purple-100">
              MQTT Protocol Connection
            </p>
            <p className="text-xs text-purple-700 dark:text-purple-300">
              Connect to MQTT-enabled IoT devices. Requires an MQTT broker and device support for pub/sub messaging.
              No local agent required.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
