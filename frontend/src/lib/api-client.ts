import axios, { AxiosInstance, AxiosRequestConfig, AxiosError } from 'axios'
import { toast } from '@/hooks/use-toast'

// Extend AxiosRequestConfig to include metadata
declare module 'axios' {
  interface AxiosRequestConfig {
    metadata?: {
      startTime?: number
      [key: string]: any
    }
    skipErrorHandling?: boolean
  }
}

// Types for the API client
export interface APIClientConfig extends AxiosRequestConfig {
  module?: string
  retries?: number
  skipErrorHandling?: boolean
}

export interface APIResponse<T = any> {
  data: T
  status: number
  message?: string
  errors?: APIError[]
}

export interface APIError {
  field?: string
  message: string
  code?: string
}

export interface AuthConfig {
  tokenKey?: string
  refreshTokenKey?: string
  authHeader?: string
  tenantHeader?: string
}

// Error categories for consistent handling
export enum ErrorCategory {
  VALIDATION = 'validation',
  NETWORK = 'network',
  SERVER = 'server',
  CLIENT = 'client',
  AUTHENTICATION = 'authentication',
  AUTHORIZATION = 'authorization',
  RATE_LIMIT = 'rate_limit'
}

export interface CategorizedError {
  category: ErrorCategory
  message: string
  originalError: any
  shouldRetry: boolean
  userMessage: string
}

/**
 * Enhanced API Client that wraps axios with consistent error handling,
 * authentication, and request/response interceptors
 */
class APIClient {
  private axiosInstance: AxiosInstance
  private authConfig: AuthConfig

  constructor(authConfig: AuthConfig = {}) {
    this.authConfig = {
      tokenKey: 'token',
      refreshTokenKey: 'refresh_token',
      authHeader: 'Authorization',
      tenantHeader: 'X-Tenant-ID',
      ...authConfig
    }

    // Create axios instance with existing configuration
    this.axiosInstance = axios.create({
      baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
      headers: {
        'Content-Type': 'application/json',
      },
      withCredentials: true, // Support cookie-based auth
      timeout: 30000, // 30 second timeout
    })

    this.setupInterceptors()
  }

  private setupInterceptors(): void {
    // Request interceptor
    this.axiosInstance.interceptors.request.use(
      (config) => {
        // Add authentication headers if token exists (for EventManagement module)
        const token = localStorage.getItem(this.authConfig.tokenKey!)
        if (token && config.headers) {
          config.headers[this.authConfig.authHeader!] = `Bearer ${token}`
        }

        // Add tenant header if available
        const tenantId = localStorage.getItem('tenant_id')
        if (tenantId && config.headers && this.authConfig.tenantHeader) {
          config.headers[this.authConfig.tenantHeader] = tenantId
        }

        // Add request timestamp for debugging
        if (!config.metadata) {
          config.metadata = {}
        }
        config.metadata.startTime = Date.now()

        return config
      },
      (error) => {
        return Promise.reject(this.categorizeError(error))
      }
    )

    // Response interceptor
    this.axiosInstance.interceptors.response.use(
      (response) => {
        const resolvedTenant = response.headers?.['x-tenant-id'] as string | undefined
        if (resolvedTenant && localStorage.getItem('tenant_id') !== resolvedTenant) {
          localStorage.setItem('tenant_id', resolvedTenant)
        }

        // Log response time for performance monitoring
        const duration = Date.now() - (response.config.metadata?.startTime || 0)
        if (duration > 5000) {
          console.warn(`Slow API response: ${response.config.url} took ${duration}ms`)
        }

        return response
      },
      async (error) => {
        const categorizedError = this.categorizeError(error)
        
        // Handle specific error cases
        if (error.response?.status === 401) {
          await this.handleAuthenticationError(error)
          // Don't show "please log in" toast – the ERP session is still valid.
          // React Query will surface 401 as a failed query state.
          return Promise.reject(categorizedError)
        } else if (error.response?.status === 429) {
          return this.handleRateLimitError(error)
        }

        // Show user-friendly error message unless explicitly skipped
        if (!error.config?.skipErrorHandling) {
          this.showErrorToast(categorizedError)
        }

        return Promise.reject(categorizedError)
      }
    )
  }

  private categorizeError(error: any): CategorizedError {
    if (!error.response) {
      // Network error
      return {
        category: ErrorCategory.NETWORK,
        message: 'Network connection failed',
        originalError: error,
        shouldRetry: true,
        userMessage: 'Unable to connect to the server. Please check your internet connection.'
      }
    }

    const status = error.response.status
    const data = error.response.data

    switch (status) {
      case 400:
        return {
          category: ErrorCategory.VALIDATION,
          message: data?.message || 'Invalid request data',
          originalError: error,
          shouldRetry: false,
          userMessage: data?.message || 'Please check your input and try again.'
        }

      case 401:
        return {
          category: ErrorCategory.AUTHENTICATION,
          message: 'Authentication required',
          originalError: error,
          shouldRetry: false,
          userMessage: 'Please log in to continue.'
        }

      case 403:
        return {
          category: ErrorCategory.AUTHORIZATION,
          message: 'Access denied',
          originalError: error,
          shouldRetry: false,
          userMessage: 'You do not have permission to perform this action.'
        }

      case 404:
        return {
          category: ErrorCategory.CLIENT,
          message: 'Resource not found',
          originalError: error,
          shouldRetry: false,
          userMessage: 'The requested resource was not found.'
        }

      case 429:
        return {
          category: ErrorCategory.RATE_LIMIT,
          message: 'Rate limit exceeded',
          originalError: error,
          shouldRetry: true,
          userMessage: 'Too many requests. Please wait a moment and try again.'
        }

      case 500:
      case 502:
      case 503:
      case 504:
        return {
          category: ErrorCategory.SERVER,
          message: 'Server error',
          originalError: error,
          shouldRetry: true,
          userMessage: 'A server error occurred. Please try again later.'
        }

      case 422:
        // Laravel validation errors
        return {
          category: ErrorCategory.VALIDATION,
          message: data?.message ?? 'Validation failed',
          originalError: error,
          shouldRetry: false,
          userMessage: (data?.errors ? Object.values(data.errors).flat().join(', ') : (data?.message ?? 'Validation failed'))
        }
      default:
        
        // Fall-through for unexpected codes
        return {
          category: ErrorCategory.CLIENT,
          message: data?.message || 'An unexpected error occurred',
          originalError: error,
          shouldRetry: false,
          userMessage: data?.message || 'An unexpected error occurred. Please try again.'
        }
    }
  }

  private async handleAuthenticationError(error: AxiosError): Promise<void> {
    // Check if refresh token exists and try to refresh
    const refreshToken = localStorage.getItem(this.authConfig.refreshTokenKey!)

    if (!refreshToken) {
      // No event-management refresh token. The ERP user is authenticated via
      // Sanctum session cookies; just clear module tokens and let the error
      // propagate – do NOT wipe the ERP session or redirect.
      this.clearAuthData()
      return
    }

    if (!error.config?.url?.includes('/auth/refresh')) {
      try {
        const response = await this.axiosInstance.post('/auth/refresh', {
          refresh_token: refreshToken
        }, { skipErrorHandling: true } as APIClientConfig)

        // Update tokens
        localStorage.setItem(this.authConfig.tokenKey!, response.data.access_token)
        if (response.data.refresh_token) {
          localStorage.setItem(this.authConfig.refreshTokenKey!, response.data.refresh_token)
        }

        // Retry the original request
        if (error.config) {
          return this.axiosInstance.request(error.config)
        }
      } catch (refreshError) {
        // Refresh failed, clear module tokens only
        this.clearAuthData()
      }
    } else {
      this.clearAuthData()
    }
  }

  private async handleRateLimitError(error: AxiosError): Promise<any> {
    const retryAfter = error.response?.headers['retry-after'] || 60
    const delay = Math.min(parseInt(retryAfter) * 1000, 60000) // Max 60 seconds
    
    console.warn(`Rate limit exceeded. Retrying after ${retryAfter} seconds...`)
    
    // Wait for the specified time then retry the request
    await new Promise(resolve => setTimeout(resolve, delay))
    
    if (error.config) {
      return this.axiosInstance.request(error.config)
    }
    
    throw error
  }

  private clearAuthData(): void {
    // Only clear event-management module tokens.
    // Do NOT touch 'user' or 'tenant_id' – those belong to the ERP's own
    // Sanctum session and must not be wiped by this module-level client.
    // Do NOT force-redirect to /login – the ERP manages its own auth lifecycle.
    localStorage.removeItem(this.authConfig.tokenKey!)
    localStorage.removeItem(this.authConfig.refreshTokenKey!)
  }

  private showErrorToast(error: CategorizedError): void {
    try {
      toast({
        title: "Error",
        description: error.userMessage,
        variant: "destructive",
      })
    } catch (toastError) {
      // Fallback to console if toast is not available
      console.error('API Error:', error.userMessage)
    }
  }

  // Public API methods
  async request<T = any>(config: APIClientConfig): Promise<APIResponse<T>> {
    const response = await this.axiosInstance.request(config)
    return {
      data: response.data,
      status: response.status,
      message: response.data?.message
    }
  }

  async get<T = any>(url: string, config?: APIClientConfig): Promise<APIResponse<T>> {
    return this.request<T>({ ...config, method: 'GET', url })
  }

  async post<T = any>(url: string, data?: any, config?: APIClientConfig): Promise<APIResponse<T>> {
    return this.request<T>({ ...config, method: 'POST', url, data })
  }

  async put<T = any>(url: string, data?: any, config?: APIClientConfig): Promise<APIResponse<T>> {
    return this.request<T>({ ...config, method: 'PUT', url, data })
  }

  async patch<T = any>(url: string, data?: any, config?: APIClientConfig): Promise<APIResponse<T>> {
    return this.request<T>({ ...config, method: 'PATCH', url, data })
  }

  async delete<T = any>(url: string, config?: APIClientConfig): Promise<APIResponse<T>> {
    return this.request<T>({ ...config, method: 'DELETE', url })
  }

  // Utility methods
  setAuthToken(token: string): void {
    localStorage.setItem(this.authConfig.tokenKey!, token)
  }

  getAuthToken(): string | null {
    return localStorage.getItem(this.authConfig.tokenKey!)
  }

  clearAuth(): void {
    this.clearAuthData()
  }

  // Get the underlying axios instance for advanced usage
  getAxiosInstance(): AxiosInstance {
    return this.axiosInstance
  }
}

// Create and export the default API client instance
export const apiClient = new APIClient()

// Export the class for creating custom instances
export { APIClient }
