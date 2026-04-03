import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { useAuthStore, type AdminRole } from '@/lib/store/auth'
import { adminApi } from '@/lib/admin-api/client'

const ROLE_LABELS: Record<AdminRole, string> = {
  platform_super_admin: 'Super Admin',
  platform_ops:         'Platform Ops',
  sales_ops:            'Sales Ops',
  billing_ops:          'Billing Ops',
  support_readonly:     'Support',
}

const ROLE_COLORS: Record<AdminRole, string> = {
  platform_super_admin: 'bg-purple-100 text-purple-700',
  platform_ops:         'bg-blue-100 text-blue-700',
  sales_ops:            'bg-green-100 text-green-700',
  billing_ops:          'bg-amber-100 text-amber-700',
  support_readonly:     'bg-gray-100 text-gray-600',
}

export function AdminTopbar() {
  const navigate = useNavigate()
  const { user, clearAuth } = useAuthStore()

  const handleLogout = async () => {
    try {
      await adminApi.post('/auth/logout')
    } catch {
      // Logout even if the request fails
    } finally {
      clearAuth()
      navigate('/login', { replace: true })
    }
  }

  return (
    <header className="flex h-[var(--topbar-height)] items-center justify-between border-b border-gray-200 bg-white px-6">
      <div className="flex items-center gap-2 text-sm text-gray-500">
        <Icon icon="solar:shield-star-bold-duotone" className="text-brand-600" width={16} />
        <span className="font-medium text-gray-700">Superadmin Control Plane</span>
      </div>

      <div className="flex items-center gap-3">
        {user && (
          <>
            <span
              className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${ROLE_COLORS[user.role]}`}
            >
              {ROLE_LABELS[user.role]}
            </span>
            <span className="text-sm text-gray-700">{user.name}</span>
          </>
        )}

        <button
          onClick={handleLogout}
          className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700"
        >
          <Icon icon="solar:logout-3-bold-duotone" width={16} />
          Logout
        </button>
      </div>
    </header>
  )
}
