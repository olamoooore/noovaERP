/**
 * HTTP Connection Configuration Component
 * 
 * Configuration form for HTTP/REST API device connections
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
import { Switch } from '@/components/ui/switch'
import type { DeviceConnectionInfo, AuthType } from '@/types/iot'
import { getConnectionPlaceholder } from '@/lib/iot-helpers'

interface HttpConnectionConfigProps {
  value: DeviceConnectionInfo
  onChange: (value: DeviceConnectionInfo) => void
}

export function HttpConnectionConfig({
  value,
  onChange,
}: HttpConnectionConfigProps) {
  const updateField = (field: keyof DeviceConnectionInfo, fieldValue: any) => {
    onChange({ ...value, [field]: fieldValue })
  }

  const updateAuthConfig = (field: string, fieldValue: any) => {
    onChange({
      ...value,
      auth_config: {
        ...value.auth_config,
        [field]: fieldValue,
      },
    })
  }

  const authType = value.auth_type || 'bearer'

  return (
    <div className="space-y-4 p-4 bg-muted/50 rounded-lg border">
      <div className="flex items-center gap-2">
        <Icon icon="solar:link-bold-duotone" className="h-5 w-5 text-green-600" />
        <h4 className="font-medium text-sm">HTTP/REST API Connection</h4>
      </div>

      <div className="space-y-4">
        {/* Base URL */}
        <div className="space-y-2">
          <Label htmlFor="base_url">
            Base URL <span className="text-destructive">*</span>
          </Label>
          <Input
            id="base_url"
            value={value.base_url || ''}
            onChange={(e) => updateField('base_url', e.target.value)}
            placeholder={getConnectionPlaceholder('http', 'base_url')}
          />
          <p className="text-xs text-muted-foreground">
            Device API base URL (e.g., https://device.local/api/v1)
          </p>
        </div>

        {/* Authentication Type */}
        <div className="space-y-2">
          <Label htmlFor="auth_type">Authentication Type</Label>
          <Select
            value={authType}
            onValueChange={(v) => updateField('auth_type', v as AuthType)}
          >
            <SelectTrigger id="auth_type">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="bearer">Bearer Token</SelectItem>
              <SelectItem value="basic">Basic Auth</SelectItem>
              <SelectItem value="api_key">API Key</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {/* Authentication Configuration */}
        {authType === 'bearer' && (
          <div className="space-y-2">
            <Label htmlFor="auth_token">Bearer Token</Label>
            <Input
              id="auth_token"
              type="password"
              value={value.auth_config?.token || ''}
              onChange={(e) => updateAuthConfig('token', e.target.value)}
              placeholder="Enter bearer token"
            />
          </div>
        )}

        {authType === 'basic' && (
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="auth_username">Username</Label>
              <Input
                id="auth_username"
                value={value.auth_config?.username || ''}
                onChange={(e) => updateAuthConfig('username', e.target.value)}
                placeholder="Username"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="auth_password">Password</Label>
              <Input
                id="auth_password"
                type="password"
                value={value.auth_config?.password || ''}
                onChange={(e) => updateAuthConfig('password', e.target.value)}
                placeholder="Password"
              />
            </div>
          </div>
        )}

        {authType === 'api_key' && (
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="auth_header">Header Name</Label>
              <Input
                id="auth_header"
                value={value.auth_config?.header || 'X-API-Key'}
                onChange={(e) => updateAuthConfig('header', e.target.value)}
                placeholder="X-API-Key"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="auth_value">API Key Value</Label>
              <Input
                id="auth_value"
                type="password"
                value={value.auth_config?.value || ''}
                onChange={(e) => updateAuthConfig('value', e.target.value)}
                placeholder="Enter API key"
              />
            </div>
          </div>
        )}

        {/* Advanced Settings */}
        <div className="space-y-4 pt-4 border-t">
          <h5 className="text-sm font-medium">Advanced Settings</h5>
          
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="timeout">Timeout (seconds)</Label>
              <Input
                id="timeout"
                type="number"
                value={value.timeout || 30}
                onChange={(e) => updateField('timeout', parseInt(e.target.value))}
                placeholder={getConnectionPlaceholder('http', 'timeout')}
                min="1"
                max="300"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="retry_attempts">Retry Attempts</Label>
              <Input
                id="retry_attempts"
                type="number"
                value={value.retry_attempts || 3}
                onChange={(e) => updateField('retry_attempts', parseInt(e.target.value))}
                placeholder={getConnectionPlaceholder('http', 'retry_attempts')}
                min="0"
                max="10"
              />
            </div>
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-0.5">
              <Label htmlFor="verify_ssl">Verify SSL Certificate</Label>
              <p className="text-xs text-muted-foreground">
                Disable for self-signed certificates
              </p>
            </div>
            <Switch
              id="verify_ssl"
              checked={value.verify_ssl !== false}
              onCheckedChange={(checked) => updateField('verify_ssl', checked)}
            />
          </div>
        </div>

        {/* Info Box */}
        <div className="flex items-start gap-3 p-3 bg-green-50 dark:bg-green-950/20 rounded-lg border border-green-200 dark:border-green-800">
          <Icon
            icon="solar:info-circle-bold"
            className="h-5 w-5 text-green-600 dark:text-green-400 mt-0.5 shrink-0"
          />
          <div className="space-y-1">
            <p className="text-sm font-medium text-green-900 dark:text-green-100">
              HTTP/REST API Connection
            </p>
            <p className="text-xs text-green-700 dark:text-green-300">
              Connect directly to devices with REST APIs. Supports bearer tokens, basic auth, and API keys.
              No local agent required.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
