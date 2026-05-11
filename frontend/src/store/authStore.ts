import { create } from 'zustand'
import { persist } from 'zustand/middleware'

interface User {
  id: number
  name: string
  email: string
  roles?: any[]
}

interface AuthState {
  user: User | null
  token: string | null
  tenantId: string | null
  setAuth: (user: User, token: string, tenantId?: string) => void
  logout: () => void
  isAuthenticated: () => boolean
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      tenantId: null,
      setAuth: (user, token, tenantId) => {
        localStorage.setItem('token', token)
        localStorage.setItem('user', JSON.stringify(user))
        if (tenantId) {
          localStorage.setItem('tenant_id', tenantId)
        } else {
          localStorage.removeItem('tenant_id')
        }
        set({ user, token, tenantId: tenantId || null })
      },
      logout: () => {
        localStorage.removeItem('token')
        localStorage.removeItem('user')
        localStorage.removeItem('tenant_id')
        set({ user: null, token: null, tenantId: null })
      },
      isAuthenticated: () => !!get().token,
    }),
    {
      name: 'auth-storage',
    }
  )
)
