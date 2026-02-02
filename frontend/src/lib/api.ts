import axios from 'axios'

// Use VITE_API_BASE_URL from .env.production, fallback to /api/v1 for development
const baseURL = import.meta.env.VITE_API_BASE_URL || '/api/v1'

const api = axios.create({
  baseURL,
  headers: {
    'Content-Type': 'application/json',
  },
})

// Request interceptor to add auth token
api.interceptors.request.use((config) => {
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
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      // Use basename-aware redirect
      const basePath = import.meta.env.VITE_BASE_PATH || ''
      window.location.href = `${basePath}/login`
    }
    return Promise.reject(error)
  }
)

export default api
