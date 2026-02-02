import axios from 'axios'

// In production, API is at /noovaerp/api/v1, in development it's proxied to /api/v1
const baseURL = import.meta.env.PROD ? '/noovaerp/api/v1' : '/api/v1'

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
      window.location.href = import.meta.env.PROD ? '/noovaerp/login' : '/login'
    }
    return Promise.reject(error)
  }
)

export default api
