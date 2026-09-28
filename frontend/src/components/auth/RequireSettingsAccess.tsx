import { RequirePermissionAccess } from '@/components/auth/RequirePermissionAccess'

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
const NON_ADMIN_ROLES = [
  'Sales',
  'Manager',
  'Accountant',
  'Cashier',
  'Viewer',
]

/**
 * Settings-specific wrapper around the shared permission gate: keeps the
 * fail-closed role list and the settings wording, while all refresh /
 * denial-screen behaviour lives in RequirePermissionAccess.
 */
export function RequireSettingsAccess({
  permission = 'settings.view',
  title = 'Settings access required',
  description = 'Only Owner, Admin and SuperAdmin users can manage the settings for this workspace.',
  children,
}: RequireSettingsAccessProps) {
  return (
    <RequirePermissionAccess
      permission={permission}
      title={title}
      description={description}
      deniedRoles={NON_ADMIN_ROLES}
    >
      {children}
    </RequirePermissionAccess>
  )
}