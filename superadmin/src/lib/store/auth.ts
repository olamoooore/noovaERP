import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export type AdminRole =
  | 'platform_super_admin'
  | 'platform_ops'
  | 'sales_ops'
  | 'billing_ops'
  | 'support_readonly'

export interface AdminUser {
  id: number
  name: string
  email: string
  role: AdminRole
}

interface AuthState {
  token: string | null
  user: AdminUser | null
  isAuthenticated: boolean
  setAuth: (token: string, user: AdminUser) => void
  clearAuth: () => void
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      isAuthenticated: false,

      setAuth: (token, user) =>
        set({ token, user, isAuthenticated: true }),

      clearAuth: () =>
        set({ token: null, user: null, isAuthenticated: false }),
    }),
    {
      name: 'admin_auth',
      partialize: (state) => ({
        token: state.token,
        user: state.user,
        isAuthenticated: state.isAuthenticated,
      }),
    },
  ),
)
