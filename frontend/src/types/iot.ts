/**
 * IoT Device Types
 * 
 * Type definitions for multi-protocol IoT device management
 */

export type ConnectionType = 'agent' | 'mqtt' | 'http' | 'webhook'

export type DeviceType = 
  | 'printer'           // Receipt/Label/Thermal printers
  | 'scanner'           // Barcode/QR/Palm-Vein scanners
  | 'terminal'          // Payment terminals
  | 'kiosk'             // Self-service kiosks
  | 'biometric'         // Fingerprint, face recognition, palm-vein
  | 'scale'             // Weighing scales
  | 'display'           // Customer/Kitchen displays
  | 'sensor'            // IoT sensors

export type DeviceStatus = 'online' | 'offline' | 'busy' | 'error' | 'ready'

export type AuthType = 'bearer' | 'basic' | 'api_key' | 'hmac'

export type HttpMethod = 'POST' | 'PUT' | 'PATCH' | 'GET'

/**
 * Device Connection Information
 * Protocol-specific configuration for device connectivity
 */
export interface DeviceConnectionInfo {
  type: ConnectionType
  
  // Agent connection (USB/Network devices via local agent)
  agent_id?: string
  device_path?: string
  ump_device_id?: string  // UMP cloud device MongoDB ObjectId
  
  // MQTT connection (IoT sensors, smart devices)
  broker_url?: string
  topic_command?: string
  topic_response?: string
  client_id?: string
  username?: string
  password?: string
  qos?: 0 | 1 | 2
  keep_alive?: number
  clean_session?: boolean
  tls?: {
    enabled: boolean
    ca_cert?: string
    client_cert?: string
    client_key?: string
  }
  
  // HTTP connection (Smart devices with REST API)
  base_url?: string
  auth_type?: AuthType
  auth_config?: {
    token?: string
    username?: string
    password?: string
    header?: string
    value?: string
    secret?: string
    algorithm?: string
  }
  timeout?: number
  retry_attempts?: number
  retry_delay?: number
  verify_ssl?: boolean
  headers?: Record<string, string>
  
  // Webhook connection (External services)
  webhook_url?: string
  method?: HttpMethod
}

/**
 * IoT Device
 * Represents a physical or virtual IoT device
 */
export interface IoTDevice {
  id: string
  name: string
  device_type: DeviceType
  driver_type: string
  connection_type: ConnectionType
  agent_id?: string | null
  connection_info: DeviceConnectionInfo
  capabilities: string[]
  driver_config?: Record<string, any>
  enabled: boolean
  current_status?: DeviceStatus
  status_message?: string
  last_seen_at?: string
  created_at?: string
  updated_at?: string
}

/**
 * Connection Type Metadata
 * UI information for each connection type
 */
export interface ConnectionTypeInfo {
  value: ConnectionType
  label: string
  description: string
  icon: string
  color: string
  requires: string
  examples: string[]
}

/**
 * Device Form Data
 * Form structure for creating/editing devices
 */
export interface DeviceFormData {
  name: string
  device_type: DeviceType
  driver_type: string
  connection_type: ConnectionType
  agent_id?: string
  connection_info: DeviceConnectionInfo
  capabilities?: string[]
  driver_config?: Record<string, any>
  enabled?: boolean
}

/**
 * Connection Test Result
 */
export interface ConnectionTestResult {
  success: boolean
  message: string
  latency?: number
  error?: string
}

/**
 * Available Connection Types
 */
export const CONNECTION_TYPES: ConnectionTypeInfo[] = [
  {
    value: 'agent',
    label: 'Local Agent',
    description: 'Connect via local IoT agent (USB/Network devices)',
    icon: 'solar:server-bold-duotone',
    color: 'blue',
    requires: 'Local agent installation',
    examples: ['USB printers', 'Network scanners', 'Serial devices'],
  },
  {
    value: 'mqtt',
    label: 'MQTT',
    description: 'Direct MQTT connection (IoT sensors, smart devices)',
    icon: 'solar:cloud-bold-duotone',
    color: 'purple',
    requires: 'MQTT broker and device support',
    examples: ['IoT sensors', 'Smart devices', 'Industrial equipment'],
  },
  {
    value: 'http',
    label: 'HTTP/REST',
    description: 'Direct HTTP API connection (Smart devices with REST API)',
    icon: 'solar:link-bold-duotone',
    color: 'green',
    requires: 'Device with HTTP API',
    examples: ['Smart scales', 'Cloud printers', 'API-enabled devices'],
  },
  {
    value: 'webhook',
    label: 'Webhook',
    description: 'Webhook integration (External services)',
    icon: 'solar:webhook-bold-duotone',
    color: 'orange',
    requires: 'Webhook endpoint',
    examples: ['Third-party APIs', 'Cloud services', 'External integrations'],
  },
]

/**
 * Device Type Icons
 */
export const DEVICE_TYPE_ICONS: Record<DeviceType, string> = {
  printer: 'solar:printer-bold-duotone',
  scanner: 'solar:qr-code-bold-duotone',
  terminal: 'solar:card-bold-duotone',
  kiosk: 'solar:tablet-bold-duotone',
  biometric: 'solar:hand-shake-bold-duotone',
  scale: 'solar:scale-bold-duotone',
  display: 'solar:monitor-bold-duotone',
  sensor: 'solar:cpu-bolt-bold-duotone',
}

/**
 * Device Status Colors
 */
export const DEVICE_STATUS_COLORS: Record<DeviceStatus, string> = {
  online: 'green',
  offline: 'gray',
  busy: 'yellow',
  error: 'red',
  ready: 'blue',
}
