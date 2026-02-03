/**
 * Webhook Connection Configuration Component
 * 
 * Configuration form for webhook-based device connections
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
import type { DeviceConnectionInfo, HttpMethod, AuthType } from '@/types/iot'
import { getConnectionPlaceholder } from '@/lib/iot-helpers'

interface WebhookConnectionConfigProps {
  value: DeviceConnectionInfo
  onChange: (value: DeviceConnectionInfo) => void
}

export function WebhookConnectionConfig({
  value,
  onChange,
}: WebhookConnectionConfigProps) {
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
        <Icon icon="solar:webhook-bold-duotone" className="h-5 w-5 text-orange-600" />
        <h4 className="font-medium text-sm">Webhook Connection</h4>
      </div>

      <div className="space-y-4">
        {/* Webhook URL */}
        <div className="space-y-2">
          <Label htmlFor="webhook_url">
            Webhook URL <span className="text-destructive">*</span>
          </Label>
          <Input
            id="webhook_url"
            value={value.webhook_url || ''}
            onChange={(e) => updateField('webhook_url', e.target.value)}
            placeholder={getConnectionPlaceholder('webhook', 'webhook_url')}
          />
          <p className="text-xs text-muted-foreground">
            External service webhook endpoint
          </p>
        </div>

        {/* HTTP Method */}
        <div className="space-y-2">
          <Label htmlFor="method">
            HTTP Method <span className="text-destructive">*</span>
          </Label>
          <Select
            value={value.method || 'POST'}
            onValueChange={(v) => updateField('method', v as HttpMethod)}
          >
            <SelectTrigger id="method">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="POST">POST</SelectItem>
              <SelectItem value="PUT">PUT</SelectItem>
              <SelectItem value="PATCH">PATCH</SelectItem>
              <SelectItem value="GET">GET</SelectItem>
            </SelectContent>
          </Select>
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
              <SelectItem value="hmac">HMAC Signature</SelectItem>
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

        {authType === 'hmac' && (
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="auth_secret">HMAC Secret</Label>
              <Input
                id="auth_secret"
                type="password"
                value={value.auth_config?.secret || ''}
                onChange={(e) => updateAuthConfig('secret', e.target.value)}
                placeholder="Enter HMAC secret"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="auth_algorithm">Algorithm</Label>
              <Select
                value={value.auth_config?.algorithm || 'sha256'}
                onValueChange={(v) => updateAuthConfig('algorithm', v)}
              >
                <SelectTrigger id="auth_algorithm">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="sha256">SHA-256</SelectItem>
                  <SelectItem value="sha512">SHA-512</SelectItem>
                  <SelectItem value="sha1">SHA-1</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        )}

        {/* Timeout */}
        <div className="space-y-2">
          <Label htmlFor="timeout">Timeout (seconds)</Label>
          <Input
            id="timeout"
            type="number"
            value={value.timeout || 10}
            onChange={(e) => updateField('timeout', parseInt(e.target.value))}
            placeholder="10"
            min="1"
            max="300"
          />
        </div>

        {/* Info Box */}
        <div className="flex items-start gap-3 p-3 bg-orange-50 dark:bg-orange-950/20 rounded-lg border border-orange-200 dark:border-orange-800">
          <Icon
            icon="solar:info-circle-bold"
            className="h-5 w-5 text-orange-600 dark:text-orange-400 mt-0.5 shrink-0"
          />
          <div className="space-y-1">
            <p className="text-sm font-medium text-orange-900 dark:text-orange-100">
              Webhook Integration
            </p>
            <p className="text-xs text-orange-700 dark:text-orange-300">
              Send device commands to external services via webhooks. Useful for third-party API integrations
              and cloud services. No local agent required.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
