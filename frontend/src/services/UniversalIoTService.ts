/**
 * Universal IoT Service
 * 
 * Handles all communication with UMP Backend for IoT device management.
 * This service is the ONLY interface between NoovaERP UI and UMP.
 * 
 * Architecture:
 * NoovaERP Frontend → UMP Backend API → Local Agent → Hardware
 */

import axios from 'axios'

// UMP Backend Base URL - Port 3100 for local development
const UMP_BASE_URL = (import.meta as any).env?.VITE_UMP_BASE_URL || 'http://localhost:3100/api/v1'

// Types
export interface UMPDevice {
  id: string
  name: string
  type: 'printer' | 'scanner' | 'terminal' | 'scale' | 'display' | 'other'
  status: 'online' | 'offline' | 'busy' | 'error'
  capabilities: string[]  // Raw capabilities from UMP (e.g., ["THERMAL", "RECEIPT", "USB"])
  supportedActions?: string[]  // Actions the device supports (e.g., ["PRINT_RAW", "OPEN_DRAWER"])
  vendor?: string
  model?: string
  serialNumber?: string
  lastSeen?: string
  metadata?: Record<string, unknown>
  agentId?: string  // MongoDB ObjectId of the agent this device belongs to
}

export interface UMPAgent {
  id: string        // MongoDB ObjectId
  name: string
  status: 'online' | 'offline' | 'active' | 'inactive'
  deviceCount?: number
  lastSeen?: string
}

export type DeviceCapability = 
  | 'PRINT_RAW'      // Can print raw text/ESC-POS
  | 'PRINT_FILE'     // Can print PDF/image files
  | 'PRINT_LABEL'    // Can print labels (TSPL/ZPL)
  | 'PRINT_RECEIPT'  // Can print receipts
  | 'SCAN_IMAGE'     // Can scan documents to image
  | 'SCAN_BARCODE'   // Can scan barcodes
  | 'PAYMENT'        // Can process payments
  | 'WEIGH'          // Can weigh items
  | 'DISPLAY'        // Can show customer display
  | 'CASH_DRAWER'    // Can open cash drawer

export interface CommandResult {
  success: boolean
  message: string
  data?: unknown
  jobId?: string
  error?: string
}

export interface ScanResult {
  success: boolean
  imageUrl?: string
  imageBase64?: string
  format?: string
  error?: string
}

export interface AgentStatus {
  connected: boolean
  agentId: string
  version?: string
  uptime?: number
  deviceCount?: number
}

/**
 * Universal IoT Service Class
 */
class UniversalIoTService {
  private apiKey: string | null = null

  constructor() {
    // Load API key from localStorage on initialization
    if (typeof window !== 'undefined') {
      const savedKey = localStorage.getItem('ump_api_key')
      if (savedKey) {
        this.apiKey = savedKey
      }
    }
  }

  /**
   * Set the API key for UMP authentication
   */
  setApiKey(key: string) {
    this.apiKey = key
    if (typeof window !== 'undefined') {
      localStorage.setItem('ump_api_key', key)
    }
  }

  /**
   * Get the current API key
   */
  getApiKey(): string | null {
    return this.apiKey
  }

  /**
   * Get authorization headers
   */
  private getHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
      'Accept': 'application/json',
    }
    // Try to get API key from instance or localStorage
    const key = this.apiKey || (typeof window !== 'undefined' ? localStorage.getItem('ump_api_key') : null)
    if (key) {
      headers['Authorization'] = `Bearer ${key}`
      console.log('[UMP] Using API key:', key.substring(0, 10) + '...')
    } else {
      console.warn('[UMP] No API key found!')
    }
    return headers
  }

  /**
   * Clear the API key (for logout/disconnect)
   */
  clearApiKey() {
    this.apiKey = null
    if (typeof window !== 'undefined') {
      localStorage.removeItem('ump_api_key')
    }
  }

  /**
   * Fetch all agents for the authenticated user
   * Uses: GET /api/v1/agent
   * @returns Promise<UMPAgent[]> - List of agents with their MongoDB ObjectIds
   */
  async fetchAgents(): Promise<UMPAgent[]> {
    try {
      console.log('[UMP] Fetching agents')
      const response = await axios.get(`${UMP_BASE_URL}/agent`, {
        headers: this.getHeaders(),
      })

      if (response.data.success) {
        const agents = response.data.data || []
        // Handle both array and object responses
        const agentList = Array.isArray(agents) ? agents : Object.values(agents)
        return agentList.map((a: Record<string, unknown>) => ({
          id: (a._id || a.id) as string,
          name: a.name as string || a.agentId as string || 'Unknown Agent',
          status: a.status as UMPAgent['status'] || 'offline',
          deviceCount: a.deviceCount as number,
          lastSeen: a.lastSeen as string,
        }))
      }

      console.error('Failed to fetch agents:', response.data.message)
      return []
    } catch (error) {
      console.error('Error fetching agents:', error)
      return []
    }
  }

  /**
   * Fetch all devices
   * Uses: GET /api/v1/devices (returns all devices for the authenticated user)
   * @param _agentId - Optional agent identifier (not used currently, kept for API compatibility)
   * @returns Promise<UMPDevice[]> - List of devices
   */
  async fetchDevices(_agentId?: string): Promise<UMPDevice[]> {
    try {
      console.log('[UMP] Fetching all devices')
      const headers = this.getHeaders()
      console.log('[UMP] Request headers:', headers)
      
      const response = await axios.get(`${UMP_BASE_URL}/devices`, {
        headers,
      })

      if (response.data.success) {
        // Map UMP device format to our UMPDevice interface
        const devices = response.data.data?.items || response.data.data || []
        return devices.map((d: Record<string, unknown>) => ({
          id: d.id || d._id,
          name: d.name,
          type: this.mapDeviceType(d.type as string),
          status: d.status || 'offline',
          capabilities: (d.capabilities as string[] || []),  // Keep raw capabilities from UMP
          supportedActions: (d.supportedActions as string[] || []),  // Actions UMP says device supports
          vendor: d.vendor || d.manufacturer,
          model: d.model,
          serialNumber: d.serialNumber,
          lastSeen: d.lastSeen,
          metadata: d.metadata || {},
          agentId: d.agentId || d.agent,  // Include the agent's MongoDB ObjectId
        })) as UMPDevice[]
      }

      console.error('Failed to fetch devices:', response.data.message)
      return []
    } catch (error: unknown) {
      const axiosError = error as { response?: { status?: number; data?: { error?: string } } }
      console.error('Error fetching devices:', error)
      
      // Provide more specific error info
      if (axiosError.response?.status === 401) {
        console.error('[UMP] 401 Unauthorized - Token may not have device access permissions')
        console.error('[UMP] Response:', axiosError.response?.data)
      }
      
      throw error
    }
  }

  /**
   * Map UMP device type to our standard types
   */
  private mapDeviceType(type: string): UMPDevice['type'] {
    const typeMap: Record<string, UMPDevice['type']> = {
      'printer': 'printer',
      'receipt-printer': 'printer',
      'label-printer': 'printer',
      'scanner': 'scanner',
      'palm-vein-scanner': 'scanner',
      'barcode-scanner': 'scanner',
      'terminal': 'terminal',
      'payment-terminal': 'terminal',
      'scale': 'scale',
      'display': 'display',
      'customer-display': 'display',
    }
    return typeMap[type?.toLowerCase()] || 'other'
  }

  /**
   * Helper: Convert File to base64
   */
  private fileToBase64(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader()
      reader.onload = () => {
        // Remove data URL prefix (e.g., "data:application/pdf;base64,")
        const base64 = (reader.result as string).split(',')[1]
        resolve(base64)
      }
      reader.onerror = reject
      reader.readAsDataURL(file)
    })
  }

  /**
   * Execute a command on a device
   * Uses JSON for all commands (including file uploads via base64)
   * Endpoint: /universal/execute
   * 
   * Note: agentId is now optional - UMP auto-discovers the agent from deviceId
   * 
   * @param _agentId - Optional agent ID (kept for backwards compatibility, not sent to UMP)
   * @param deviceId - The device's MongoDB ObjectId
   * @param action - The action to perform (PRINT_RAW, SCAN_IMAGE, etc.)
   * @param data - Optional JSON data for the command
   * @param file - Optional file to send (for document printing - will be converted to base64)
   * @returns Promise<CommandResult>
   */
  async executeCommand(
    _agentId: string,
    deviceId: string,
    action: string,
    data?: Record<string, unknown>,
    file?: File
  ): Promise<CommandResult> {
    try {
      let payload: any

      if (file) {
        // Convert file to base64 and use PRINT_RAW action
        console.log('[UMP] Converting file to base64...')
        const base64Content = await this.fileToBase64(file)
        
        console.log('[UMP] File converted:', {
          name: file.name,
          type: file.type,
          size: file.size,
          base64Length: base64Content.length
        })

        // Always use PRINT_RAW for file printing (UMP doesn't support PRINT_FILE)
        payload = {
          deviceId,
          action: 'PRINT_RAW',  // UMP requires PRINT_RAW for all printing
          data: {
            content: base64Content,  // Base64 content in data.content field
            ...(data || {})  // Merge any additional data
          }
        }
      } else {
        // Regular command (no file)
        payload = {
          deviceId,   // Must be MongoDB ObjectId (24 hex chars)
          action,     // PRINT_RAW, SCAN_IMAGE, etc.
          ...(data && { data }),
        }
      }
      
      console.log('[UMP] Executing command:', {
        deviceId: payload.deviceId,
        action: payload.action,
        hasContent: !!payload.data?.content,
        contentLength: payload.data?.content?.length
      })

      const response = await axios.post(
        `${UMP_BASE_URL}/universal/execute`,
        payload,
        {
          headers: {
            ...this.getHeaders(),
            'Content-Type': 'application/json',
          },
        }
      )

      return {
        success: response.data.success,
        message: response.data.message || 'Command executed',
        data: response.data.data,
        jobId: response.data.jobId,
      }
    } catch (error: unknown) {
      const axiosError = error as { response?: { status?: number; data?: { message?: string; error?: string } }; message?: string }
      console.error('Error executing command:', error)
      console.error('[UMP] Response:', axiosError.response?.data)
      return {
        success: false,
        message: 'Failed to execute command',
        error: axiosError.response?.data?.message || axiosError.response?.data?.error || axiosError.message || 'Unknown error',
      }
    }
  }

  /**
   * Send a print job with raw text (ESC/POS, TSPL, etc.)
   */
  async printRaw(agentId: string, deviceId: string, content: string, format?: string): Promise<CommandResult> {
    return this.executeCommand(agentId, deviceId, 'PRINT_RAW', {
      content,
      format: format || 'text',
    })
  }

  /**
   * Send a print job with a file (PDF, image)
   * Note: UMP requires PRINT_RAW action with base64 content, not PRINT_FILE
   */
  async printFile(agentId: string, deviceId: string, file: File): Promise<CommandResult> {
    // executeCommand handles file conversion to base64 and uses PRINT_RAW action
    return this.executeCommand(agentId, deviceId, 'PRINT_RAW', undefined, file)
  }

  /**
   * Print a label with structured data
   */
  async printLabel(
    agentId: string,
    deviceId: string,
    labelData: {
      productName: string
      barcode?: string
      price?: string
      sku?: string
      width?: number
      height?: number
    }
  ): Promise<CommandResult> {
    return this.executeCommand(agentId, deviceId, 'PRINT_LABEL', labelData)
  }

  /**
   * Print a receipt
   */
  async printReceipt(
    agentId: string,
    deviceId: string,
    receiptData: {
      header?: string
      items: Array<{ name: string; qty: number; price: number }>
      subtotal: number
      tax?: number
      total: number
      footer?: string
    }
  ): Promise<CommandResult> {
    return this.executeCommand(agentId, deviceId, 'PRINT_RECEIPT', receiptData)
  }

  /**
   * Start a scan operation
   */
  async startScan(
    agentId: string,
    deviceId: string,
    options?: {
      format?: 'jpeg' | 'png' | 'pdf'
      resolution?: number
      colorMode?: 'color' | 'grayscale' | 'bw'
    }
  ): Promise<CommandResult> {
    return this.executeCommand(agentId, deviceId, 'SCAN_IMAGE', options || {})
  }

  /**
   * Open cash drawer
   */
  async openCashDrawer(agentId: string, deviceId: string): Promise<CommandResult> {
    return this.executeCommand(agentId, deviceId, 'OPEN_DRAWER')
  }

  /**
   * Show message on customer display
   */
  async showDisplay(
    agentId: string,
    deviceId: string,
    message: string,
    line2?: string
  ): Promise<CommandResult> {
    return this.executeCommand(agentId, deviceId, 'DISPLAY', {
      line1: message,
      line2,
    })
  }

  /**
   * Get device status
   * Uses: GET /api/v1/devices/:id
   */
  async getDeviceStatus(_agentId: string, deviceId: string): Promise<UMPDevice | null> {
    try {
      const response = await axios.get(
        `${UMP_BASE_URL}/devices/${deviceId}`,
        { headers: this.getHeaders() }
      )

      if (response.data.success) {
        return response.data.data as UMPDevice
      }
      return null
    } catch (error) {
      console.error('Error getting device status:', error)
      return null
    }
  }

  /**
   * Check agent connection status
   * Uses: GET /health for basic connectivity check
   * Then GET /api/v1/devices to get device count
   */
  async checkAgentStatus(agentId: string): Promise<AgentStatus> {
    try {
      // First check if UMP is reachable
      const healthResponse = await axios.get(
        `${UMP_BASE_URL.replace('/api/v1', '')}/health`,
        { headers: this.getHeaders(), timeout: 5000 }
      )

      if (healthResponse.status === 200) {
        // UMP is up, now get devices
        try {
          const devicesResponse = await axios.get(
            `${UMP_BASE_URL}/devices`,
            { headers: this.getHeaders(), timeout: 5000 }
          )
          
          const devices = devicesResponse.data?.data?.items || devicesResponse.data?.data || []
          
          return {
            connected: true,
            agentId,
            version: healthResponse.data?.version || '1.0.0',
            deviceCount: devices.length,
          }
        } catch {
          // Devices endpoint failed, but UMP is connected
          return {
            connected: true,
            agentId,
            version: healthResponse.data?.version || '1.0.0',
            deviceCount: 0,
          }
        }
      }

      return { connected: false, agentId }
    } catch {
      return { connected: false, agentId }
    }
  }

  /**
   * Poll for job result (used for scans)
   * Uses: GET /api/v1/universal/job/:jobId/status
   */
  async pollScanResult(_agentId: string, jobId: string, maxAttempts = 30): Promise<ScanResult> {
    for (let i = 0; i < maxAttempts; i++) {
      try {
        const response = await axios.get(
          `${UMP_BASE_URL}/universal/job/${jobId}/status`,
          { headers: this.getHeaders() }
        )

        if (response.data.success && response.data.data?.status === 'completed') {
          return {
            success: true,
            imageUrl: response.data.data.result?.imageUrl,
            imageBase64: response.data.data.result?.imageBase64,
            format: response.data.data.result?.format,
          }
        }

        if (response.data.data?.status === 'failed') {
          return {
            success: false,
            error: response.data.data.error || 'Scan failed',
          }
        }

        // Wait 1 second before next poll
        await new Promise(resolve => setTimeout(resolve, 1000))
      } catch (error) {
        console.error('Error polling scan result:', error)
      }
    }

    return {
      success: false,
      error: 'Scan timeout - no result received',
    }
  }
}

// Export singleton instance
export const universalIoTService = new UniversalIoTService()
export default universalIoTService
