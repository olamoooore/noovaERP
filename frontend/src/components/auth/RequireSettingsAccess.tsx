import { useEffect, useState } from 'react'
import { Icon } from '@iconify/react'
import { Link } from 'react-router-dom'
import { useAuthStore } from '@/store/authStore'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

interface RequireSettingsAccessProps {
  /** Permission string required to access the gated children. */
  permission?: string
  /** Optional human-readable title for the denial screen. */
  title?: string
  /** Optional description for the denial screen. */
  description?: string
  children: React.ReactNode
}

// Roles that NEVER carry admin-style settings permissions, even if a
// stale localStorage cache claims otherwise. Mirrors the platform
// convention used in backend/app/Enums/UserRole.php + RolePermissionSeeder.
const NON_ADMIN_ROLES = new Set([
  'Sales',
  'Manager',
  'Accountant',
  'Cashier',
  'Viewer',
])

/**
 * Gate that blocks access to a route for users without the required
 * permission. The backend is the authoritative gate — this is a UX
 * guard so non-authorized users see a clear denial instead of a
 * half-rendered page that 403s on every API call.
 *
 * Three layers of defence:
 *   1. Refresh the live permission list from /auth/me on mount so a
 *      stale localStorage cache can't grant access the user no
 *      longer has.
 *   2. Role-based fail-closed: NON_ADMIN_ROLES are always denied,
 *      regardless of cached permissions.
 *   3. The standard `hasPermission()` check (Owner/Admin bypass,
 *      otherwise check the permission list).
 */
export function RequireSettingsAccess({
  permission = 'settings.view',
  title = 'Settings access required',
  description = 'Only Owner, Admin and SuperAdmin users can manage the settings for this workspace.',
  children,
}: RequireSettingsAccessProps) {
  const { hasPermission, role, setAuth, permissions } = useAuthStore()
  const [refreshed, setRefreshed] = useState(false)

  useEffect(() => {
    let cancelled = false
    const refresh = async () => {
      try {
        const me = await api.get('/auth/me')
        const tenantId = localStorage.getItem('tenant_id')
        const token = localStorage.getItem('token') || ''
        const user = me.data?.user
        const newRole: string | null = me.data?.role ?? null
        const newPermissions: string[] = me.data?.permissions ?? []
        if (cancelled || !user) return
        setAuth(
          {
            id: user.id,
            name: user.name,
            email: user.email,
            phone: user.phone ?? null,
            avatar: user.avatar ?? null,
            avatar_url: user.avatar_url ?? null,
            roles: user.roles,
          },
          token,
          tenantId ?? undefined,
          newRole ?? undefined,
          newPermissions
        )
      } catch {
        // 401 etc. — fall through, the gate below will still hold.
      } finally {
        if (!cancelled) setRefreshed(true)
      }
    }
    refresh()
    return () => {
      cancelled = true
    }
  }, [setAuth])

  // Layer 1+3: live permission check (Owner/Admin bypass via store;
  // otherwise must be present in the freshly-refreshed list).
  const allowedByPermission = hasPermission(permission)

  // Layer 2: role-based fail-closed. Catches stale localStorage that
  // still lists the permission from before the seeder was tightened.
  const roleDeniesAccess = !!role && NON_ADMIN_ROLES.has(role)

  // Owner/Admin pass immediately, before the refresh lands, so they
  // never see a flash of the denial screen.
  const allowedByRole = role === 'Owner' || role === 'Admin'

  if (allowedByRole || (!roleDeniesAccess && allowedByPermission)) {
    return <>{children}</>
  }

  // While the refresh is in flight for a role that *might* still
  // turn out to be allowed, render children optimistically to avoid
  // a flash. Once `refreshed` is true we trust the layers above.
  if (!refreshed && permissions.length === 0) {
    return <>{children}</>
  }

  return (
    <div className="mx-auto flex min-h-[60vh] max-w-lg flex-col items-center justify-center p-6">
      <Card className="w-full border-dashed">
        <CardHeader className="items-center text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <Icon icon="solar:lock-keyhole-bold-duotone" className="h-6 w-6" />
          </div>
          <CardTitle className="mt-3 text-lg">{title}</CardTitle>
          <CardDescription>{description}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col items-center gap-3 pb-6">
          <p className="text-xs text-muted-foreground">
            Your role: <span className="font-medium text-foreground">{role || 'unknown'}</span>
          </p>
          <Button asChild variant="outline" size="sm">
            <Link to="/dashboard">
              <Icon icon="solar:alt-arrow-left-bold" className="mr-1.5 h-4 w-4" />
              Back to dashboard
            </Link>
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}
