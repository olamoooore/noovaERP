import axios, { AxiosInstance, AxiosError } from 'axios'

/**
 * Print Service for UMP Middleware Integration
 * 
 * This service handles communication with the UMP (Universal Middleware Platform)
 * for printer management and print job submission.
 */

// UMP Middleware Base URL
const UMP_BASE_URL = 'https://ump.example.com'

// Type Definitions
export interface Device {
  device_id: string
  type: 'label' | 'receipt' | 'terminal' | 'scanner'
  vendor: string
  model: string
  status: 'ready' | 'busy' | 'offline' | 'error'
  serial?: string
}

export interface FetchPrintersResponse {
  success: boolean
  agent_id: string
  devices: Device[]
  agent_status?: string
  agent_version?: string | null
  uptime?: number | null
  error?: string
  status_code?: number | null
}

export interface LabelPrintData {
  productName: string
  sku: string
  price: string
  quantity: number
}

export interface ReceiptItem {
  name: string
  quantity: number
  price: number
}

export interface ReceiptPrintData {
  order_id: number
  items: ReceiptItem[]
  total: number
  tax: number
  payment_method: string
  store_name?: string
  customer_name?: string
}

export type PrintData = LabelPrintData | ReceiptPrintData

export interface PrintJobResponse {
  success: boolean
  job_id: string | null
  message: string
  status?: string
  printer_type: string
  agent_id: string
  error?: string
  status_code?: number | null
}

export interface PrintJobStatusResponse {
  success: boolean
  job_id: string
  status: string
  message: string
  completed_at?: string | null
  error?: string | null
}

export interface CancelJobResponse {
  success: boolean
  job_id: string
  message: string
  error?: string
}

export interface ConnectionTestResponse {
  success: boolean
  message: string
  version?: string | null
  status?: string
  error?: string
}

// Create axios instance for UMP API
const umpApi: AxiosInstance = axios.create({
  baseURL: UMP_BASE_URL,
  timeout: 30000, // 30 seconds timeout
  headers: {
    'Content-Type': 'application/json',
  },
})

// Request interceptor for adding auth token if needed
umpApi.interceptors.request.use(
  (config) => {
    // Add any authentication headers if required by UMP
    // const token = localStorage.getItem('ump_token')
    // if (token) {
    //   config.headers.Authorization = `Bearer ${token}`
    // }
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor for error handling
umpApi.interceptors.response.use(
  (response) => {
    return response
  },
  (error: AxiosError) => {
    // Handle common errors
    if (error.response) {
      // Server responded with error status
      console.error('UMP API Error:', error.response.data)
    } else if (error.request) {
      // Request made but no response received
      console.error('UMP API No Response:', error.request)
    } else {
      // Error in request setup
      console.error('UMP API Request Error:', error.message)
    }
    return Promise.reject(error)
  }
)

/**
 * Fetch list of printers connected to a specific agent
 * 
 * @param {string} agentId - The UMP Agent ID
 * @returns {Promise<FetchPrintersResponse>} Response containing list of connected devices
 * 
 * @example
 * const response = await fetchPrinters('agent-auto-generated')
 * // Response format:
 * // {
 * //   success: true,
 * //   agent_id: 'agent-auto-generated',
 * //   devices: [
 * //     {
 * //       device_id: 'printer-label-001',
 * //       type: 'label',
 * //       vendor: 'gprinter',
 * //       model: 'GP-D320FX',
 * //       status: 'ready'
 * //     },
 * //     {
 * //       device_id: 'printer-receipt-001',
 * //       type: 'receipt',
 * //       vendor: 'kpos',
 * //       model: 'KPOS-80',
 * //       status: 'ready'
 * //     }
 * //   ]
 * // }
 */
export async function fetchPrinters(agentId: string): Promise<FetchPrintersResponse> {
  try {
    if (!agentId) {
      throw new Error('Agent ID is required')
    }

    const response = await umpApi.get(`/api/agent/${agentId}`)

    return {
      success: true,
      agent_id: agentId,
      devices: response.data.devices || [],
      agent_status: response.data.status || 'unknown',
      agent_version: response.data.version || null,
      uptime: response.data.uptime || null,
    }
  } catch (error) {
    console.error('Failed to fetch printers:', error)
    
    const axiosError = error as AxiosError<{ message?: string; error?: string }>
    
    return {
      success: false,
      agent_id: agentId,
      devices: [],
      error: axiosError.response?.data?.message || axiosError.message || 'Failed to fetch printers',
      status_code: axiosError.response?.status || null,
    }
  }
}

/**
 * Send a print job to UMP middleware
 * 
 * @param {string} agentId - The UMP Agent ID
 * @param {string} printerType - Type of printer ('label' or 'receipt')
 * @param {PrintData} data - Print job data
 * @returns {Promise<PrintJobResponse>} Response containing print job status
 * 
 * @example
 * // Label Print Job
 * const labelResponse = await sendPrintJob(
 *   'agent-auto-generated',
 *   'label',
 *   {
 *     productName: 'Premium Coffee Beans',
 *     sku: '12345',
 *     price: 'RM 25.50',
 *     quantity: 1
 *   }
 * )
 * 
 * @example
 * // Receipt Print Job
 * const receiptResponse = await sendPrintJob(
 *   'agent-auto-generated',
 *   'receipt',
 *   {
 *     order_id: 123,
 *     items: [
 *       { name: 'Product A', quantity: 2, price: 15.99 },
 *       { name: 'Product B', quantity: 1, price: 25.50 }
 *     ],
 *     total: 57.48,
 *     tax: 5.17,
 *     payment_method: 'Cash'
 *   }
 * )
 */
export async function sendPrintJob(
  agentId: string,
  printerType: 'label' | 'receipt',
  data: PrintData
): Promise<PrintJobResponse> {
  try {
    if (!agentId) {
      throw new Error('Agent ID is required')
    }

    if (!printerType) {
      throw new Error('Printer type is required')
    }

    if (!data) {
      throw new Error('Print data is required')
    }

    // Validate printer type
    const validTypes: Array<'label' | 'receipt'> = ['label', 'receipt']
    if (!validTypes.includes(printerType)) {
      throw new Error(`Invalid printer type. Must be one of: ${validTypes.join(', ')}`)
    }

    // Prepare print job payload
    const payload = {
      agent_id: agentId,
      type: printerType,
      data: data,
      timestamp: new Date().toISOString(),
    }

    const response = await umpApi.post('/api/print-job', payload)

    return {
      success: true,
      job_id: response.data.job_id || null,
      message: response.data.message || 'Print job sent successfully',
      status: response.data.status || 'queued',
      printer_type: printerType,
      agent_id: agentId,
    }
  } catch (error) {
    console.error('Failed to send print job:', error)
    
    const axiosError = error as AxiosError<{ message?: string; error?: string }>
    
    return {
      success: false,
      job_id: null,
      message: axiosError.response?.data?.message || axiosError.message || 'Failed to send print job',
      error: axiosError.response?.data?.error || axiosError.message,
      status_code: axiosError.response?.status || null,
      printer_type: printerType,
      agent_id: agentId,
    }
  }
}

/**
 * Get print job status
 * 
 * @param {string} jobId - The print job ID
 * @returns {Promise<PrintJobStatusResponse>} Response containing job status
 * 
 * @example
 * const status = await getPrintJobStatus('job-12345')
 * // Response format:
 * // {
 * //   success: true,
 * //   job_id: 'job-12345',
 * //   status: 'completed',
 * //   message: 'Print job completed successfully'
 * // }
 */
export async function getPrintJobStatus(jobId: string): Promise<PrintJobStatusResponse> {
  try {
    if (!jobId) {
      throw new Error('Job ID is required')
    }

    const response = await umpApi.get(`/api/print-job/${jobId}/status`)

    return {
      success: true,
      job_id: jobId,
      status: response.data.status || 'unknown',
      message: response.data.message || '',
      completed_at: response.data.completed_at || null,
      error: response.data.error || null,
    }
  } catch (error) {
    console.error('Failed to get print job status:', error)
    
    const axiosError = error as AxiosError<{ message?: string; error?: string }>
    
    return {
      success: false,
      job_id: jobId,
      status: 'error',
      message: axiosError.response?.data?.message || axiosError.message || 'Failed to get job status',
      error: axiosError.response?.data?.error || axiosError.message,
    }
  }
}

/**
 * Cancel a print job
 * 
 * @param {string} jobId - The print job ID
 * @returns {Promise<CancelJobResponse>} Response containing cancellation status
 * 
 * @example
 * const result = await cancelPrintJob('job-12345')
 */
export async function cancelPrintJob(jobId: string): Promise<CancelJobResponse> {
  try {
    if (!jobId) {
      throw new Error('Job ID is required')
    }

    const response = await umpApi.post(`/api/print-job/${jobId}/cancel`)

    return {
      success: true,
      job_id: jobId,
      message: response.data.message || 'Print job cancelled successfully',
    }
  } catch (error) {
    console.error('Failed to cancel print job:', error)
    
    const axiosError = error as AxiosError<{ message?: string; error?: string }>
    
    return {
      success: false,
      job_id: jobId,
      message: axiosError.response?.data?.message || axiosError.message || 'Failed to cancel print job',
      error: axiosError.response?.data?.error || axiosError.message,
    }
  }
}

/**
 * Test connection to UMP middleware
 * 
 * @returns {Promise<ConnectionTestResponse>} Response containing connection status
 * 
 * @example
 * const result = await testConnection()
 * // Response format:
 * // {
 * //   success: true,
 * //   message: 'UMP middleware is reachable',
 * //   version: '1.0.0'
 * // }
 */
export async function testConnection(): Promise<ConnectionTestResponse> {
  try {
    const response = await umpApi.get('/api/health')

    return {
      success: true,
      message: 'UMP middleware is reachable',
      version: response.data.version || null,
      status: response.data.status || 'ok',
    }
  } catch (error) {
    console.error('Failed to connect to UMP:', error)
    
    const axiosError = error as AxiosError
    
    return {
      success: false,
      message: 'Failed to connect to UMP middleware',
      error: axiosError.message,
    }
  }
}

/**
 * Get linked agent ID from local storage
 * 
 * @returns {string|null} Agent ID or null if not linked
 */
export function getLinkedAgentId(): string | null {
  return localStorage.getItem('ump_agent_id')
}

/**
 * Check if user has linked an agent
 * 
 * @returns {boolean} True if agent is linked
 */
export function isAgentLinked(): boolean {
  return !!getLinkedAgentId()
}

// Export the axios instance for advanced usage
export { umpApi }

// Export default object with all functions
export default {
  fetchPrinters,
  sendPrintJob,
  getPrintJobStatus,
  cancelPrintJob,
  testConnection,
  getLinkedAgentId,
  isAgentLinked,
  umpApi,
}
