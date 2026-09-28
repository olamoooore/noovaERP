import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { useToast } from '@/hooks/use-toast'
import { cn } from '@/lib/utils'

interface RolePermission {
  name: string
  label: string
}

interface CatalogGroup {
  module: string
  label: string
  permissions: RolePermission[]
}

interface TenantRole {
  value: string
  label: string
  is_super: boolean
  permissions: string[]
  member_count: number
}

interface TenantRolesResponse {
  roles: TenantRole[]
  catalog: CatalogGroup[]
}

const ROLE_ICONS: Record<string, string> = {
  owner: 'solar:crown-bold-duotone',
  admin: 'solar:shield-user-bold-duotone',
  manager: 'solar:user-check-bold-duotone',
  sales: 'solar:medal-star-bold-duotone',
  accountant: 'solar:calculator-minimalistic-bold-duotone',
  cashier: 'solar:card-bold-duotone',
  viewer: 'solar:eye-bold-duotone',
}

/**
 * Owner/Admin edit the permission set of each non-super role for this
 * workspace. Owner and Admin roles bypass every permission check on the
 * server (UserRole::superRoles()), so they are shown locked as "Full access".
 */
export function RolesPermissionsDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const { toast } = useToast()
  const qc = useQueryClient()
  const [selectedRole, setSelectedRole] = useState<string | null>(null)
  const [draft, setDraft] = useState<Set<string>>(new Set())

  const { data, isLoading } = useQuery({
    queryKey: ['tenant-roles'],
    queryFn: async () => (await api.get('/tenant-roles')).data as TenantRolesResponse,
    enabled: open,
  })

  const roles = useMemo(() => data?.roles ?? [], [data])
  const catalog = useMemo(() => data?.catalog ?? [], [data])
  const role: TenantRole | undefined = useMemo(
    () => roles.find((r) => r.value === selectedRole) ?? roles.find((r) => !r.is_super) ?? roles[0],
    [roles, selectedRole],
  )

  // Keep the draft in step with the selected role (and with refetched data).
  useEffect(() => {
    if (role) setDraft(new Set(role.permissions))
  }, [role])

  const isDirty = useMemo(() => {
    if (!role) return false
    const current = new Set(role.permissions)
    if (current.size !== draft.size) return true
    for (const permission of draft) if (!current.has(permission)) return true
    return false
  }, [role, draft])

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!role) return
      await api.put(`/tenant-roles/${role.value}`, { permissions: Array.from(draft) })
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tenant-roles'] })
      toast({
        title: `${role?.label ?? 'Role'} permissions updated`,
        description: 'Members with this role get the new set on their next request.',
      })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update permissions',
        description: error?.response?.data?.message || 'Unknown error',
      })
    },
  })

  const togglePermission = (name: string, checked: boolean) => {
    setDraft((prev) => {
      const next = new Set(prev)
      if (checked) next.add(name)
      else next.delete(name)
      return next
    })
  }

  const toggleGroup = (group: CatalogGroup, checked: boolean) => {
    setDraft((prev) => {
      const next = new Set(prev)
      for (const permission of group.permissions) {
        if (checked) next.add(permission.name)
        else next.delete(permission.name)
      }
      return next
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-hidden sm:max-w-4xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="solar:shield-keyhole-bold-duotone" className="h-5 w-5 text-primary" />
            Roles &amp; Permissions
          </DialogTitle>
          <DialogDescription>
            Choose what each role can do in this workspace. Changes apply to every member with that role.
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="flex items-center justify-center py-16 text-muted-foreground">
            <Icon icon="eos-icons:loading" className="h-7 w-7 animate-spin text-primary" />
          </div>
        ) : (
          <div className="grid max-h-[62vh] gap-4 overflow-hidden md:grid-cols-[220px_minmax(0,1fr)]">
            {/* Role list */}
            <div className="flex gap-2 overflow-x-auto md:flex-col md:overflow-visible">
              {roles.map((item) => (
                <button
                  key={item.value}
                  type="button"
                  onClick={() => setSelectedRole(item.value)}
                  className={cn(
                    'flex shrink-0 items-center gap-2 rounded-xl border px-3 py-2.5 text-left transition-colors md:shrink',
                    (role?.value === item.value)
                      ? 'border-primary/40 bg-primary/5'
                      : 'hover:bg-muted/50',
                  )}
                >
                  <div className={cn(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                    item.is_super ? 'bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400' : 'bg-muted text-muted-foreground',
                  )}>
                    <Icon icon={ROLE_ICONS[item.value] ?? 'solar:user-bold-duotone'} className="h-4 w-4" />
                  </div>
                  <div className="min-w-0">
                    <div className="flex items-center gap-1.5">
                      <span className="text-sm font-medium">{item.label}</span>
                      {item.is_super && <Icon icon="solar:lock-keyhole-minimalistic-bold-duotone" className="h-3 w-3 text-violet-500" />}
                    </div>
                    <div className="text-xs text-muted-foreground">
                      {item.is_super ? 'Full access' : `${item.permissions.length} permissions`} · {item.member_count} {item.member_count === 1 ? 'member' : 'members'}
                    </div>
                  </div>
                </button>
              ))}
            </div>

            {/* Permission matrix */}
            <div className="flex min-h-0 flex-col rounded-xl border">
              <div className="flex items-center justify-between gap-3 border-b bg-muted/20 px-4 py-3">
                <div>
                  <div className="flex items-center gap-2 text-sm font-semibold">
                    {role?.label ?? 'Role'}
                    {role?.is_super && (
                      <Badge variant="outline" className="border-transparent bg-violet-100 font-normal text-violet-700 dark:bg-violet-950/60 dark:text-violet-300">
                        Full access
                      </Badge>
                    )}
                  </div>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {role?.is_super
                      ? 'Owner and Admin always have every permission and cannot be limited.'
                      : `${draft.size} of ${catalog.reduce((sum, group) => sum + group.permissions.length, 0)} permissions selected`}
                  </p>
                </div>
                {!role?.is_super && (
                  <HeaderActionButton
                    icon="solar:check-circle-bold-duotone"
                    loading={saveMutation.isPending}
                    disabled={!isDirty || saveMutation.isPending}
                    onClick={() => saveMutation.mutate()}
                  >
                    Save Changes
                  </HeaderActionButton>
                )}
              </div>

              <div className="min-h-0 flex-1 overflow-y-auto p-3">
                {role?.is_super ? (
                  <div className="flex flex-col items-center justify-center gap-2 py-16 text-center text-muted-foreground">
                    <Icon icon="solar:lock-keyhole-bold-duotone" className="h-10 w-10 opacity-40" />
                    <p className="text-sm font-medium">This role cannot be restricted</p>
                    <p className="max-w-xs text-xs">
                      Owner and Admin bypass all permission checks by design, so there is nothing to configure.
                    </p>
                  </div>
                ) : (
                  <div className="grid gap-3 sm:grid-cols-2">
                    {catalog.map((group) => {
                      const selectedInGroup = group.permissions.filter((permission) => draft.has(permission.name)).length
                      const allSelected = selectedInGroup === group.permissions.length
                      return (
                        <div key={group.module} className="rounded-xl border bg-card">
                          <label className="flex cursor-pointer items-center justify-between gap-2 border-b px-3 py-2">
                            <div className="flex items-center gap-2">
                              <Checkbox
                                checked={allSelected ? true : selectedInGroup > 0 ? 'indeterminate' : false}
                                onCheckedChange={(checked) => toggleGroup(group, checked === true)}
                              />
                              <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {group.label}
                              </span>
                            </div>
                            <span className="text-[11px] tabular-nums text-muted-foreground">
                              {selectedInGroup}/{group.permissions.length}
                            </span>
                          </label>
                          <div className="space-y-1 p-2">
                            {group.permissions.map((permission) => (
                              <label
                                key={permission.name}
                                className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors hover:bg-muted/50"
                              >
                                <Checkbox
                                  checked={draft.has(permission.name)}
                                  onCheckedChange={(checked) => togglePermission(permission.name, checked === true)}
                                />
                                <span className="min-w-0 truncate">{permission.label}</span>
                              </label>
                            ))}
                          </div>
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export default RolesPermissionsDialog