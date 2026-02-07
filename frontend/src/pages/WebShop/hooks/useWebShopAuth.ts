import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'

interface Customer {
  id: number
  email: string
  first_name: string
  last_name: string
  phone?: string
  loyalty_points: number
}

interface AuthState {
  customer: Customer | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
}

interface RegisterData {
  email: string
  password: string
  password_confirmation: string
  first_name: string
  last_name: string
  phone?: string
  guest_token?: string
}

const STORAGE_KEY = 'web_shop_auth'

export function useWebShopAuth() {
  const [state, setState] = useState<AuthState>({
    customer: null,
    token: null,
    isAuthenticated: false,
    isLoading: true,
  })

  // Initialize from localStorage on mount
  useEffect(() => {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored) {
      try {
        const parsed = JSON.parse(stored)
        setState({
          customer: parsed.customer,
          token: parsed.token,
          isAuthenticated: true,
          isLoading: false,
        })
      } catch {
        localStorage.removeItem(STORAGE_KEY)
        setState(prev => ({ ...prev, isLoading: false }))
      }
    } else {
      setState(prev => ({ ...prev, isLoading: false }))
    }
  }, [])

  // Persist auth state to localStorage
  useEffect(() => {
    if (state.token && state.customer) {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        token: state.token,
        customer: state.customer,
      }))
    }
  }, [state.token, state.customer])

  const login = useCallback(async (email: string, password: string) => {
    try {
      const response = await api.post('/web-shop/auth/login', {
        email,
        password,
      })

      if (response.data.success) {
        setState({
          customer: response.data.customer,
          token: response.data.token,
          isAuthenticated: true,
          isLoading: false,
        })
        return { success: true }
      }
      return { success: false, error: 'Login failed' }
    } catch (error: any) {
      return {
        success: false,
        error: error.response?.data?.error || 'Login failed. Please try again.',
      }
    }
  }, [])

  const register = useCallback(async (data: RegisterData) => {
    try {
      const response = await api.post('/web-shop/auth/register', data)

      if (response.data.success) {
        setState({
          customer: response.data.customer,
          token: response.data.token,
          isAuthenticated: true,
          isLoading: false,
        })
        return { success: true }
      }
      return { success: false, error: 'Registration failed' }
    } catch (error: any) {
      return {
        success: false,
        error: error.response?.data?.error || error.response?.data?.message || 'Registration failed. Please try again.',
      }
    }
  }, [])

  const logout = useCallback(async () => {
    try {
      if (state.token) {
        await api.post('/web-shop/auth/logout', {}, {
          headers: {
            Authorization: `Bearer ${state.token}`,
          },
        })
      }
    } catch (error) {
      // Ignore errors, clear local state anyway
    } finally {
      localStorage.removeItem(STORAGE_KEY)
      setState({
        customer: null,
        token: null,
        isAuthenticated: false,
        isLoading: false,
      })
    }
  }, [state.token])

  // Placeholder for Phase 4 cart merging
  const mergeCart = useCallback(async () => {
    // TODO: Implement in Phase 4
    console.log('Cart merging not yet implemented')
  }, [])

  // Helper to get auth headers for API calls
  const getAuthHeaders = useCallback(() => {
    if (!state.token) return {}
    return {
      Authorization: `Bearer ${state.token}`,
    }
  }, [state.token])

  return {
    ...state,
    login,
    register,
    logout,
    mergeCart,
    getAuthHeaders,
  }
}