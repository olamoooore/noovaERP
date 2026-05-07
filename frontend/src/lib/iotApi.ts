import api from './api'
import type { ConnectionType, DeviceConnectionInfo } from '@/types/iot'

// Types
export interface DeviceConfiguration {
  id: string
  agentId: string
  // Support both camelCase and snake_case from API responses
  deviceType?: 'printer' | 'scanner' | 'terminal' | 'biometric' | 'scale' | 'display' | 'kiosk'
  device_type?: 'printer' | 'scanner' | 'terminal' | 'biometric' | 'scale' | 'display' | 'kiosk'
  driverType?: string
  driver_type?: string
  name: string
  enabled?: boolean
  connectionType?: ConnectionType  // New: connection type (camelCase)
  connection_type?: ConnectionType  // New: connection type (snake_case)
  connectionInfo?: DeviceConnectionInfo | {  // Updated: support both new and old formats
    vendorId?: string
    productId?: string
    port?: string
    printerName?: string
  }
  connection_info?: DeviceConnectionInfo | {
    vendorId?: string
    productId?: string
    port?: string
    printerName?: string
  }
  driverConfig?: Record<string, any>
  driver_config?: Record<string, any>
  capabilities?: string[]
  metadata?: Record<string, any>
  currentStatus?: string
  status?: string  // snake_case status from backend
  lastSeenAt?: string
  last_seen_at?: string
  createdAt?: string
  created_at?: string
  updatedAt?: string
  updated_at?: string
}

export interface DiscoveredDevice {
  id: string
  agentId: string
  vendorId: string
  productId: string
  manufacturer?: string
  model?: string
  serialNumber?: string
  port?: string
  connectionType: 'usb' | 'serial' | 'hid'
  status: string
  discoveredAt: string
  configuredDeviceId?: string
  ump_device_id?: string
}

export interface DeviceTemplate {
  id: string
  name: string
  deviceType: 'printer' | 'scanner' | 'terminal'
  driverType: string
  vendorId?: string
  productId?: string
  manufacturer?: string
  model?: string
  defaultConfig: Record<string, any>
  capabilities: string[]
  description?: string
  icon?: string
}

export interface DeviceStatusHistory {
  id: string
  deviceConfigId: string
  status: string
  message?: string
  errorCode?: string
  timestamp: string
}

export interface ScanResult {
  id: string
  deviceId: string
  agentId: string
  barcode: string
  format?: string
  scannedAt: string
  metadata?: {
    parsed?: QRParsedData | PalmVeinParsedData
    isQrCode?: boolean
  }
}

export interface QRParsedData {
  type: 'json' | 'url' | 'noovaerp_product' | 'text'
  data: Record<string, any>
  raw?: string
}

export interface PalmVeinParsedData {
  type: 'palm_vein'
  data: {
    template?: string
    quality?: number
    matchScore?: number | null
    format?: string
  }
  raw?: string
}

export interface BarcodeScannedEvent {
  deviceId: string
  deviceName?: string
  barcode: string
  format?: string
  scanResultId?: string
  scannedAt: string
  parsed?: QRParsedData | PalmVeinParsedData
  isQrCode?: boolean
}

export interface AgentHealth {
  success: boolean
  status: 'online' | 'offline'
  last_heartbeat?: string
  data?: {
    agentId: string
    version: string
    uptime: number
    devices: Array<{
      deviceId: string
      type: string
      vendor: string
      model: string
      status: string
    }>
  }
  error?: string
}

// Agent Health & Status
export const iotApi = {
  // Agent Health
  checkAgentHealth: async (agentId: string): Promise<AgentHealth> => {
    const response = await api.get(`/iot/agent/verify/${agentId}`)
    const payload = response.data

    return {
      success: !!payload.success,
      status: payload.success && payload.data?.status === 'online' ? 'online' : 'offline',
      last_heartbeat: payload.success ? new Date().toISOString() : undefined,
      data: payload.success
        ? {
            agentId: payload.data?.agent_id ?? agentId,
            version: payload.data?.agent_version ?? 'Unknown',
            uptime: payload.data?.uptime ?? 0,
            devices: payload.data?.devices ?? [],
          }
        : undefined,
      error: payload.success ? undefined : payload.message,
    }
  },

  getAgentDevices: async (agentId: string) => {
    const response = await api.get(`/iot/agents/${agentId}/devices`)
    return response.data
  },

  getAgentDiscovered: async (agentId: string) => {
    const response = await api.get(`/iot/agents/${agentId}/discovered`)
    return response.data
  },

  // Device Configurations
  getDeviceConfigs: async (agentId: string): Promise<{ data: DeviceConfiguration[] }> => {
    const response = await api.get(`/iot/agents/${agentId}/device-configs`)
    return response.data
  },

  createDeviceConfig: async (agentId: string, data: Partial<DeviceConfiguration>) => {
    const response = await api.post(`/iot/agents/${agentId}/device-configs`, data)
    return response.data
  },

  getDeviceConfig: async (agentId: string, deviceId: string): Promise<{ data: DeviceConfiguration }> => {
    const response = await api.get(`/iot/agents/${agentId}/device-configs/${deviceId}`)
    return response.data
  },

  updateDeviceConfig: async (agentId: string, deviceId: string, data: Partial<DeviceConfiguration>) => {
    const response = await api.put(`/iot/agents/${agentId}/device-configs/${deviceId}`, data)
    return response.data
  },

  deleteDeviceConfig: async (agentId: string, deviceId: string) => {
    const response = await api.delete(`/iot/agents/${agentId}/device-configs/${deviceId}`)
    return response.data
  },

  reloadDevice: async (agentId: string, deviceId: string) => {
    const response = await api.post(`/iot/agents/${agentId}/device-configs/${deviceId}/reload`)
    return response.data
  },

  reloadAllDevices: async (agentId: string) => {
    const response = await api.post(`/iot/agents/${agentId}/device-configs/reload-all`)
    return response.data
  },

  // Discovered Devices
  getDiscoveredDevices: async (params?: { agentId?: string; status?: string; connectionType?: string }) => {
    // Convert camelCase to snake_case for backend compatibility
    const backendParams: Record<string, string> = {}
    if (params?.agentId) backendParams.agent_id = params.agentId
    if (params?.status) backendParams.status = params.status
    if (params?.connectionType) backendParams.connection_type = params.connectionType
    
    const response = await api.get('/iot/discovered-devices', { params: backendParams })
    return response.data
  },

  getDiscoveredDevice: async (deviceId: string): Promise<{ data: DiscoveredDevice }> => {
    const response = await api.get(`/iot/discovered-devices/${deviceId}`)
    return response.data
  },

  ignoreDiscoveredDevice: async (deviceId: string) => {
    const response = await api.patch(`/iot/discovered-devices/${deviceId}/ignore`)
    return response.data
  },

  triggerDiscoveryScan: async (agentId: string) => {
    const response = await api.post('/iot/discovered-devices/scan', { agent_id: agentId })
    return response.data
  },

  // Device Templates
  getDeviceTemplates: async (params?: { deviceType?: string; vendorId?: string; productId?: string }) => {
    const response = await api.get('/iot/device-templates', { params })
    return response.data
  },

  getDeviceTemplate: async (templateId: string): Promise<{ data: DeviceTemplate }> => {
    const response = await api.get(`/iot/device-templates/${templateId}`)
    return response.data
  },

  // Device Status
  updateDeviceStatus: async (agentId: string, data: {
    deviceId: string
    status: string
    message?: string
    errorCode?: string
  }) => {
    const response = await api.post(`/iot/agents/${agentId}/device-status`, data)
    return response.data
  },
  
  // Agent Configuration
  getAgentConfig: async () => {
    const response = await api.get('/iot/agent-config')
    return response.data
  },

  getLinkedAgent: async (): Promise<{
    success: boolean
    data: {
      agent_id: string
      linked_at?: string | null
    } | null
    message?: string
  }> => {
    const response = await api.get('/iot/user/linked-agent')
    return response.data
  },

  linkAgent: async (agentId: string): Promise<{
    success: boolean
    data?: {
      agent_id: string
      linked_at?: string | null
    }
    message?: string
  }> => {
    const response = await api.post('/iot/user/link-agent', {
      agent_id: agentId,
    })
    return response.data
  },
  
  updateAgentConfig: async (data: {
    agent_url: string
    agent_token?: string
    agent_id?: string
  }) => {
    const response = await api.put('/iot/agent-config', data)
    return response.data
  },
  
  testAgentConnection: async (data: {
    agent_url: string
    agent_token?: string
  }) => {
    const response = await api.post('/iot/agent-config/test', data)
    return response.data
  },

  // Scanner Job Operations
  startScannerListening: async (agentId: string, deviceId: string, options?: {
    mode?: 'single' | 'continuous'
    timeout?: number
  }) => {
    const response = await api.post(`/iot/agents/${agentId}/scanner/start-listening`, {
      device_id: deviceId,
      mode: options?.mode || 'single',
      timeout: options?.timeout || 30,
    })
    return response.data
  },

  stopScannerListening: async (agentId: string, deviceId: string) => {
    const response = await api.post(`/iot/agents/${agentId}/scanner/stop-listening`, {
      device_id: deviceId,
    })
    return response.data
  },

  getScanHistory: async (agentId: string, deviceId?: string, limit?: number) => {
    const params: Record<string, string | number> = {}
    if (deviceId) params.device_id = deviceId
    if (limit) params.limit = limit
    
    const response = await api.get(`/iot/agents/${agentId}/scanner/history`, { params })
    return response.data
  },

  // Printer Job Operations
  printLabel: async (agentId: string, deviceId: string, options: {
    jobType: 'product_label' | 'barcode_label' | 'shipping_label' | 'test_label' | 'raw_tspl'
    data: {
      productName?: string
      barcode?: string
      price?: string
      sku?: string
      barcodeType?: 'CODE128' | 'CODE39' | 'EAN13' | 'EAN8' | 'UPC-A' | 'QR'
      text?: string
      commands?: string
      // Shipping label fields
      fromName?: string
      fromAddress?: string
      toName?: string
      toAddress?: string
      trackingNumber?: string
    }
    copies?: number
    config?: Record<string, any>
  }) => {
    const response = await api.post(`/iot/agents/${agentId}/printer/print-label`, {
      device_id: deviceId,
      job_type: options.jobType,
      data: options.data,
      copies: options.copies || 1,
      config: options.config,
    })
    return response.data
  },

  getPrinterStatus: async (agentId: string, deviceId: string) => {
    const response = await api.get(`/iot/agents/${agentId}/printer/status`, {
      params: { device_id: deviceId },
    })
    return response.data
  },

  getPrintHistory: async (agentId: string, deviceId?: string, limit?: number, status?: string) => {
    const params: Record<string, string | number> = {}
    if (deviceId) params.device_id = deviceId
    if (limit) params.limit = limit
    if (status) params.status = status
    
    const response = await api.get(`/iot/agents/${agentId}/printer/history`, { params })
    return response.data
  },

  // Receipt Printer Operations
  testReceiptPrint: async (agentId: string, deviceId: string) => {
    const response = await api.post(`/iot/agents/${agentId}/printer/test-receipt`, {
      device_id: deviceId,
    })
    return response.data
  },

  printReceipt: async (data: {
    agent_id: string
    device_id: string
    receipt_data: {
      store_name?: string
      items: Array<{
        name: string
        quantity: number
        price: number
      }>
      subtotal: number
      tax?: number
      total: number
      payment_method: string
      payment_amount?: number
      change?: number
      receipt_number?: string
      cashier?: string
      date?: string
    }
  }) => {
    const response = await api.post('/iot/print-jobs/receipt', data)
    return response.data
  },
}

// Print Job Types
export interface PrintJob {
  id: string
  deviceId: string
  jobType: string
  status: 'pending' | 'sent' | 'printing' | 'completed' | 'failed'
  content: Record<string, any>
  metadata?: Record<string, any>
  errorMessage?: string
  sentAt?: string
  completedAt?: string
  createdAt: string
}

export default iotApi
