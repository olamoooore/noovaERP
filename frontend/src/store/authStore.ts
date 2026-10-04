import { create } from 'zustand'
import { persist } from 'zustand/middleware'

interface User {
  id: number
  name: string
  email: string
  phone?: string | null
  avatar?: string | null
  avatar_url?: string | null
  roles?: any[]
  /** Platform role (e.g. platform_super_admin) — null for tenant users. */
  role?: string | null
}

interface AuthState {
  user: User | null
  token: string | null
  tenantId: string | null
  role: string | null
  permissions: string[]
  setAuth: (user: User, token: string, tenantId?: string, role?: string, permissions?: string[]) => void
  logout: () => void
  isAuthenticated: () => boolean
  hasPermission: (permission: string) => boolean
  hasAnyPermission: (permissions: string[]) => boolean
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      tenantId: null,
      role: null,
      permissions: [],
      setAuth: (user, token, tenantId, role, permissions) => {
        localStorage.setItem('token', token)
        localStorage.setItem('user', JSON.stringify(user))
        if (tenantId) {
          localStorage.setItem('tenant_id', tenantId)
        } else {
          localStorage.removeItem('tenant_id')
        }
        if (role) {
          localStorage.setItem('role', role)
        } else {
          localStorage.removeItem('role')
        }
        if (permissions) {
          localStorage.setItem('permissions', JSON.stringify(permissions))
        } else {
          localStorage.removeItem('permissions')
        }
        set({ user, token, tenantId: tenantId || null, role: role || null, permissions: permissions || [] })
      },
      logout: () => {
        localStorage.removeItem('token')
        localStorage.removeItem('user')
        localStorage.removeItem('tenant_id')
        localStorage.removeItem('role')
        localStorage.removeItem('permissions')
        set({ user: null, token: null, tenantId: null, role: null, permissions: [] })
      },
      isAuthenticated: () => !!get().token,
      hasPermission: (permission: string) => {
        const state = get()
        if (!state.role) return false
        if (state.role === 'Owner' || state.role === 'Admin') return true
        // Permission families may be expressed as alternatives separated by
        // '|', mirroring the backend RequirePermission middleware (e.g.
        // 'accounting.view|sales.view' passes when the role holds any of them).
        if (permission.includes('|')) {
          return permission
            .split('|')
            .map((candidate) => candidate.trim())
            .filter(Boolean)
            .some((candidate) => state.permissions.includes(candidate))
        }
        return state.permissions.includes(permission)
      },
      hasAnyPermission: (permissions: string[]) => {
        const state = get()
        if (!state.role) return false
        if (state.role === 'Owner' || state.role === 'Admin') return true
        return permissions.some((p) => state.permissions.includes(p))
      },
    }),
    {
      name: 'auth-storage',
    }
  )
)
