import axios from 'axios'

// Use VITE_API_BASE_URL from .env.production, fallback to /api/v1 for development
const baseURL = import.meta.env.VITE_API_BASE_URL || '/api/v1'

// Main API (tenant routes)
const api = axios.create({
  baseURL,
  headers: {
    'Content-Type': 'application/json',
  },
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
  baseURL: '/api/v1',
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
  return config
})

// Response interceptor to handle errors
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Don't redirect web-shop API calls to admin login
      const requestUrl = error.config?.url || ''
      if (requestUrl.includes('web-shop')) {
        // Let the web shop auth hook handle this
        return Promise.reject(error)
      }
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      // Use basename-aware redirect
      const basePath = import.meta.env.VITE_BASE_PATH || ''
      window.location.href = `${basePath}/login`
    }
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
