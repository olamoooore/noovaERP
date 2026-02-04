/**
 * IoT Helper Functions
 * 
 * Utility functions for IoT device management
 */

import type { 
  ConnectionType, 
  DeviceType, 
  DeviceStatus,
  ConnectionTypeInfo,
} from '@/types/iot'
import { CONNECTION_TYPES } from '@/types/iot'

/**
 * Get connection type icon
 */
export function getConnectionIcon(type: ConnectionType): string {
  const icons: Record<ConnectionType, string> = {
    agent: 'solar:server-bold-duotone',
    mqtt: 'solar:cloud-bold-duotone',
    http: 'solar:link-bold-duotone',
    webhook: 'solar:webhook-bold-duotone',
  }
  return icons[type] || 'solar:cpu-bolt-bold-duotone'
}

/**
 * Get connection type color
 */
export function getConnectionColor(type: ConnectionType): string {
  const colors: Record<ConnectionType, string> = {
    agent: 'blue',
    mqtt: 'purple',
    http: 'green',
    webhook: 'orange',
  }
  return colors[type] || 'gray'
}

/**
 * Get connection type label
 */
export function getConnectionLabel(type: ConnectionType): string {
  const labels: Record<ConnectionType, string> = {
    agent: 'Local Agent',
    mqtt: 'MQTT',
    http: 'HTTP/REST',
    webhook: 'Webhook',
  }
  return labels[type] || type.toUpperCase()
}

/**
 * Get connection type description
 */
export function getConnectionDescription(type: ConnectionType): string {
  const descriptions: Record<ConnectionType, string> = {
    agent: 'Connect via local IoT agent (USB/Network devices)',
    mqtt: 'Direct MQTT connection (IoT sensors, smart devices)',
    http: 'Direct HTTP API connection (Smart devices with REST API)',
    webhook: 'Webhook integration (External services)',
  }
  return descriptions[type] || ''
}

/**
 * Get connection type info
 */
export function getConnectionTypeInfo(type: ConnectionType): ConnectionTypeInfo | undefined {
  return CONNECTION_TYPES.find(ct => ct.value === type)
}

/**
 * Get device type icon
 */
export function getDeviceTypeIcon(type: DeviceType): string {
  const icons: Record<DeviceType, string> = {
    printer: 'solar:printer-bold-duotone',
    scanner: 'solar:scanner-bold-duotone',
    terminal: 'solar:card-bold-duotone',
    kiosk: 'solar:tablet-bold-duotone',
    biometric: 'solar:hand-shake-bold-duotone',
    scale: 'solar:scale-bold-duotone',
    display: 'solar:monitor-bold-duotone',
    sensor: 'solar:cpu-bolt-bold-duotone',
  }
  return icons[type] || 'solar:cpu-bolt-bold-duotone'
}

/**
 * Get device type label
 */
export function getDeviceTypeLabel(type: DeviceType): string {
  const labels: Record<DeviceType, string> = {
    printer: 'Printer',
    scanner: 'Scanner',
    terminal: 'Payment Terminal',
    kiosk: 'Kiosk',
    biometric: 'Biometric Device',
    scale: 'Scale',
    display: 'Display',
    sensor: 'Sensor',
  }
  return labels[type] || type
}

/**
 * Get device status color
 */
export function getDeviceStatusColor(status: DeviceStatus): string {
  const colors: Record<DeviceStatus, string> = {
    online: 'green',
    offline: 'gray',
    busy: 'yellow',
    error: 'red',
    ready: 'blue',
  }
  return colors[status] || 'gray'
}

/**
 * Get device status label
 */
export function getDeviceStatusLabel(status: DeviceStatus): string {
  const labels: Record<DeviceStatus, string> = {
    online: 'Online',
    offline: 'Offline',
    busy: 'Busy',
    error: 'Error',
    ready: 'Ready',
  }
  return labels[status] || status
}

/**
 * Format last seen timestamp
 */
export function formatLastSeen(timestamp?: string): string {
  if (!timestamp) return 'Never'
  
  const date = new Date(timestamp)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  
  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`
  
  const diffHours = Math.floor(diffMins / 60)
  if (diffHours < 24) return `${diffHours}h ago`
  
  const diffDays = Math.floor(diffHours / 24)
  if (diffDays < 7) return `${diffDays}d ago`
  
  return date.toLocaleDateString()
}

/**
 * Validate connection info based on type
 */
export function validateConnectionInfo(
  type: ConnectionType,
  info: Record<string, any>
): { valid: boolean; errors: string[] } {
  const errors: string[] = []
  
  switch (type) {
    case 'agent':
      if (!info.agent_id) errors.push('Agent ID is required')
      break
      
    case 'mqtt':
      if (!info.broker_url) errors.push('Broker URL is required')
      if (!info.topic_command) errors.push('Command topic is required')
      if (!info.client_id) errors.push('Client ID is required')
      break
      
    case 'http':
      if (!info.base_url) errors.push('Base URL is required')
      if (info.auth_type && !info.auth_config) {
        errors.push('Authentication configuration is required')
      }
      break
      
    case 'webhook':
      if (!info.webhook_url) errors.push('Webhook URL is required')
      if (!info.method) errors.push('HTTP method is required')
      break
  }
  
  return {
    valid: errors.length === 0,
    errors,
  }
}

/**
 * Get placeholder text for connection fields
 */
export function getConnectionPlaceholder(
  type: ConnectionType,
  field: string
): string {
  const placeholders: Record<ConnectionType, Record<string, string>> = {
    agent: {
      agent_id: 'Select an agent',
      device_path: '/dev/usb/lp0 or COM1',
    },
    mqtt: {
      broker_url: 'mqtt://broker.hivemq.com:1883',
      topic_command: 'devices/printer-001/commands',
      topic_response: 'devices/printer-001/responses',
      client_id: 'noovaerp-iot-device-001',
      username: 'device_user',
      password: '••••••••',
    },
    http: {
      base_url: 'https://device.example.com/api/v1',
      timeout: '30',
      retry_attempts: '3',
    },
    webhook: {
      webhook_url: 'https://api.service.com/webhook',
    },
  }
  
  return placeholders[type]?.[field] || ''
}

/**
 * Get connection type badge variant
 */
export function getConnectionBadgeVariant(type: ConnectionType): 'default' | 'secondary' | 'outline' {
  return type === 'agent' ? 'default' : 'outline'
}

/**
 * Check if device is online
 */
export function isDeviceOnline(device: { current_status?: DeviceStatus; last_seen_at?: string }): boolean {
  if (device.current_status === 'online' || device.current_status === 'ready') {
    return true
  }
  
  // Consider device online if seen in last 5 minutes
  if (device.last_seen_at) {
    const lastSeen = new Date(device.last_seen_at)
    const now = new Date()
    const diffMs = now.getTime() - lastSeen.getTime()
    return diffMs < 5 * 60 * 1000 // 5 minutes
  }
  
  return false
}

/**
 * Get connection type CSS classes
 */
export function getConnectionTypeClasses(type: ConnectionType): string {
  const classes: Record<ConnectionType, string> = {
    agent: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/20 dark:text-blue-400 dark:border-blue-800',
    mqtt: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/20 dark:text-purple-400 dark:border-purple-800',
    http: 'bg-green-50 text-green-700 border-green-200 dark:bg-green-950/20 dark:text-green-400 dark:border-green-800',
    webhook: 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950/20 dark:text-orange-400 dark:border-orange-800',
  }
  return classes[type] || 'bg-gray-50 text-gray-700 border-gray-200'
}
