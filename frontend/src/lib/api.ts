import axios from 'axios'

// Use VITE_API_BASE_URL from .env.production, fallback to /api/v1 for development
const baseURL = import.meta.env.VITE_API_BASE_URL || '/api/v1'

// Main API (tenant routes)
export const api = axios.create({
  baseURL,
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true,
})

// Auth API (non-tenant routes)
export const authApi = axios.create({
  baseURL: '/api/auth',
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true,
})

// Accounting API (tenant routes with /v1 prefix)
export const accountingApi = axios.create({
  baseURL,
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true,
})

// Request interceptor to add auth token
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  const tenantId = localStorage.getItem('tenant_id')
  if (tenantId) {
    config.headers['X-Tenant-ID'] = tenantId
  }
  const umpApiKey = localStorage.getItem('ump_api_key')
  if (umpApiKey) {
    config.headers['X-UMP-Token'] = umpApiKey
  }
  return config
})

authApi.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

accountingApi.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  const tenantId = localStorage.getItem('tenant_id')
  if (tenantId) {
    config.headers['X-Tenant-ID'] = tenantId
  }
  return config
})

// Response interceptor to handle errors
api.interceptors.response.use(
  (response) => {
    const resolvedTenant = response.headers?.['x-tenant-id'] as string | undefined
    if (resolvedTenant && localStorage.getItem('tenant_id') !== resolvedTenant) {
      localStorage.setItem('tenant_id', resolvedTenant)
    }
    return response
  },
  (error) => {
    // Normalize common auth/validation errors to improve UX
    const status = error.response?.status
    // 401: Unauthorized
    if (status === 401) {
      // Don't redirect web-shop API calls to admin login
      const requestUrl = error.config?.url || ''
      if (requestUrl.includes('web-shop')) {
        // Let the web shop auth hook handle this
        return Promise.reject(error)
      }
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      // Only redirect when not already on the login page: parallel 401s
      // (expired/restored-DB tokens) otherwise reload /login repeatedly and
      // the dashboard appears to reload forever.
      if (typeof window !== 'undefined' && !window.location.pathname.startsWith('/login')) {
        window.location.href = '/login'
      }
      return Promise.reject(error)
    }

    // 403: Forbidden and 422: Unprocessable Entity should bubble to UI for handling
    if (status === 403) {
      return Promise.reject(error)
    }
    if (status === 422) {
      // Attach a friendlier message if backend provided field errors
      const data = error.response?.data ?? {}
      let message = data?.message ?? 'Validation failed'
      // Laravel style: fields -> messages map
      const fieldErrors = data?.errors ?? null
      if (fieldErrors && typeof fieldErrors === 'object') {
        const parts: string[] = []
        for (const key in fieldErrors) {
          const arr = Array.isArray(fieldErrors[key]) ? fieldErrors[key] : [fieldErrors[key]]
          arr.forEach((m: any) => {
            if (typeof m === 'string') parts.push(`${key}: ${m}`)
          })
        }
        if (parts.length) message = parts.join('; ')
      }
      error.message = message
      return Promise.reject(error)
    }

    // Other errors: pass through to UI/error handling
    return Promise.reject(error)
  }
)

authApi.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

accountingApi.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export default api
