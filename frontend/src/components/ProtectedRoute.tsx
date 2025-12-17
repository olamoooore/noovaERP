import { Navigate, Outlet } from 'react-router-dom'
import { useEffect } from 'react'
import { useAuthStore } from '@/store/authStore'
import { useSettingsStore } from '@/store/settingsStore'

export function ProtectedRoute() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated())
  const token = useAuthStore((state) => state.token)
  const loadSettings = useSettingsStore((state) => state.loadSettings)
  const isLoaded = useSettingsStore((state) => state.isLoaded)

  useEffect(() => {
    if (!token) return
    if (isLoaded) return
    loadSettings()
  }, [token, isLoaded, loadSettings])

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}
