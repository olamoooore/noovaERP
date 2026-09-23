import { useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import { useAuthStore } from '@/store/authStore'
import { cn, formatDate } from '@/lib/utils'

interface TenantUser {
  id: number
  name: string
  email: string
  role: string
  role_display: string | null
  is_default: boolean
  last_accessed_at: string | null
}

const ROLES = [
  { value: 'owner', label: 'Owner' },
  { value: 'admin', label: 'Admin' },
  { value: 'manager', label: 'Manager' },
  { value: 'sales', label: 'Sales' },
  { value: 'accountant', label: 'Accountant' },
  { value: 'cashier', label: 'Cashier' },
  { value: 'viewer', label: 'Viewer' },
]

/** Per-role colour treatment for avatars + badges. */
const ROLE_STYLES: Record<string, { chip: string; avatar: string; icon: string }> = {
  owner: {
    chip: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
    avatar: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
    icon: 'solar:crown-bold-duotone',
  },
  admin: {
    chip: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
    avatar: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
    icon: 'solar:shield-user-bold-duotone',
  },
  manager: {
    chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    avatar: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    icon: 'solar:user-check-bold-duotone',
  },
  sales: {
    chip: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    avatar: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    icon: 'solar:medal-star-bold-duotone',
  },
  accountant: {
    chip: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300',
    avatar: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300',
    icon: 'solar:calculator-minimalistic-bold-duotone',
  },
  cashier: {
    chip: 'bg-pink-100 text-pink-700 dark:bg-pink-950/60 dark:text-pink-300',
    avatar: 'bg-pink-100 text-pink-700 dark:bg-pink-950/60 dark:text-pink-300',
    icon: 'solar:card-bold-duotone',
  },
  viewer: {
    chip: 'bg-slate-100 text-slate-700 dark:bg-slate-800/70 dark:text-slate-300',
    avatar: 'bg-slate-100 text-slate-700 dark:bg-slate-800/70 dark:text-slate-300',
    icon: 'solar:eye-bold-duotone',
  },
}

const FALLBACK_STYLE = ROLE_STYLES.viewer

function styleFor(role: string) {
  return ROLE_STYLES[role?.toLowerCase()] ?? FALLBACK_STYLE
}

function initials(name: string, email: string): string {
  const source = (name || email || '?').trim()
  const parts = source.split(/\s+/).filter(Boolean)
  if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase()
  return source.slice(0, 2).toUpperCase()
}

async function getTenantUsers(): Promise<TenantUser[]> {
  const res = await api.get('/tenant-users')
  return res.data.data
}

async function updateTenantUserRole(userId: number, role: string): Promise<void> {
  await api.put(`/tenant-users/${userId}/role`, { role })
}

async function removeTenantUser(userId: number): Promise<void> {
  await api.delete(`/tenant-users/${userId}`)
}

async function inviteTenantUser(email: string, role: string, name?: string): Promise<void> {
  await api.post('/tenant-users', { email, role, name })
}

export default function TenantUsersPage() {
  const qc = useQueryClient()
  const { toast } = useToast()
  const { role: currentRole } = useAuthStore()
  const [updatingId, setUpdatingId] = useState<number | null>(null)
  const [inviteOpen, setInviteOpen] = useState(false)
  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole] = useState('viewer')
  const [inviteName, setInviteName] = useState('')

  const canManageRoles = currentRole === 'Owner' || currentRole === 'Admin'
  const canRemoveUsers = currentRole === 'Owner'

  const { data, isLoading } = useQuery({
    queryKey: ['tenant-users'],
    queryFn: getTenantUsers,
  })

  const roleMutation = useMutation({
    mutationFn: ({ id, role }: { id: number; role: string }) => updateTenantUserRole(id, role),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tenant-users'] })
      toast({ title: 'Role updated' })
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update role',
        description: err?.response?.data?.message || 'Unknown error',
      })
    },
  })

  const removeMutation = useMutation({
    mutationFn: (id: number) => removeTenantUser(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tenant-users'] })
      toast({ title: 'User removed from tenant' })
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to remove user',
        description: err?.response?.data?.message || 'Unknown error',
      })
    },
  })

  const inviteMutation = useMutation({
    mutationFn: () => inviteTenantUser(inviteEmail, inviteRole, inviteName || undefined),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tenant-users'] })
      toast({ title: 'User invited successfully' })
      setInviteEmail('')
      setInviteName('')
      setInviteRole('viewer')
      setInviteOpen(false)
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to invite user',
        description: err?.response?.data?.message || 'Unknown error',
      })
    },
  })

  const users = data ?? []

  const roleCounts = useMemo(() => {
    const counts = new Map<string, number>()
    for (const u of users) {
      const key = (u.role || 'viewer').toLowerCase()
      counts.set(key, (counts.get(key) ?? 0) + 1)
    }
    return counts
  }, [users])

  const highlightRoles = ['owner', 'admin', 'manager'].filter((r) => (roleCounts.get(r) ?? 0) > 0)

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Team Members</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Manage who has access to this workspace and what they can do.
          </p>
        </div>
        {canManageRoles && (
          <Button onClick={() => setInviteOpen(true)}>
            <Icon icon="solar:user-plus-bold-duotone" className="mr-2 h-4 w-4" />
            Invite Team Member
          </Button>
        )}
      </div>

      {/* Stats */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card className="overflow-hidden">
          <CardContent className="flex items-center gap-4 p-5">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10">
              <Icon icon="solar:users-group-rounded-bold-duotone" className="h-6 w-6 text-primary" />
            </div>
            <div>
              <div className="text-2xl font-bold tabular-nums">{users.length}</div>
              <div className="text-xs text-muted-foreground">Total members</div>
            </div>
          </CardContent>
        </Card>

        {highlightRoles.map((role) => {
          const style = styleFor(role)
          const label = ROLES.find((r) => r.value === role)?.label ?? role
          return (
            <Card key={role} className="overflow-hidden">
              <CardContent className="flex items-center gap-4 p-5">
                <div className={cn('flex h-12 w-12 shrink-0 items-center justify-center rounded-xl', style.avatar)}>
                  <Icon icon={style.icon} className="h-6 w-6" />
                </div>
                <div>
                  <div className="text-2xl font-bold tabular-nums">{roleCounts.get(role)}</div>
                  <div className="text-xs text-muted-foreground">{label}{roleCounts.get(role) === 1 ? '' : 's'}</div>
                </div>
              </CardContent>
            </Card>
          )
        })}
      </div>

      {/* Members */}
      <Card className="overflow-hidden">
        <div className="flex items-center justify-between border-b bg-muted/20 px-6 py-4">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
              <Icon icon="solar:users-group-two-rounded-bold-duotone" className="h-5 w-5 text-primary" />
            </div>
            <div>
              <h3 className="text-base font-semibold leading-none">Active Members</h3>
              <p className="mt-1 text-xs text-muted-foreground">
                {users.length} {users.length === 1 ? 'person has' : 'people have'} access to this workspace
              </p>
            </div>
          </div>
        </div>

        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex items-center justify-center py-16 text-muted-foreground">
              <Icon icon="eos-icons:loading" className="h-7 w-7 animate-spin text-primary" />
            </div>
          ) : users.length === 0 ? (
            <div className="flex flex-col items-center gap-2 py-16 text-muted-foreground">
              <Icon icon="solar:users-group-rounded-bold-duotone" className="h-10 w-10 opacity-40" />
              <p className="text-sm font-medium">No team members yet</p>
              <p className="text-xs">Invite a colleague to get started.</p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Member</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead>Last access</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {users.map((user) => {
                  const style = styleFor(user.role)
                  return (
                    <TableRow key={user.id}>
                      <TableCell>
                        <div className="flex items-center gap-3">
                          <div
                            className={cn(
                              'flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                              style.avatar,
                            )}
                          >
                            {initials(user.name, user.email)}
                          </div>
                          <div className="min-w-0">
                            <div className="flex items-center gap-2">
                              <span className="truncate text-sm font-medium">{user.name}</span>
                              {user.is_default && (
                                <Badge variant="outline" className="shrink-0 text-[10px]">
                                  Default
                                </Badge>
                              )}
                            </div>
                            <div className="truncate text-xs text-muted-foreground">{user.email}</div>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell>
                        {canManageRoles ? (
                          <Select
                            value={user.role}
                            onValueChange={(value) => {
                              setUpdatingId(user.id)
                              roleMutation.mutate(
                                { id: user.id, role: value },
                                { onSettled: () => setUpdatingId(null) },
                              )
                            }}
                            disabled={updatingId === user.id}
                          >
                            <SelectTrigger className="h-8 w-40 text-xs">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              {ROLES.map((r) => (
                                <SelectItem key={r.value} value={r.value}>
                                  {r.label}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        ) : (
                          <span
                            className={cn(
                              'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
                              style.chip,
                            )}
                          >
                            <Icon icon={style.icon} className="h-3.5 w-3.5" />
                            {user.role_display || user.role}
                          </span>
                        )}
                      </TableCell>
                      <TableCell>
                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                          <Icon icon="solar:clock-circle-bold-duotone" className="h-3.5 w-3.5" />
                          {user.last_accessed_at ? formatDate(user.last_accessed_at) : 'Never'}
                        </span>
                      </TableCell>
                      <TableCell className="text-right">
                        {canRemoveUsers && !user.is_default && (
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 text-destructive hover:text-destructive"
                            title="Remove from tenant"
                            onClick={() => {
                              if (confirm(`Remove ${user.name} from this workspace?`)) {
                                removeMutation.mutate(user.id)
                              }
                            }}
                            disabled={removeMutation.isPending}
                          >
                            <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4" />
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Invite dialog */}
      <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Invite Team Member</DialogTitle>
            <DialogDescription>
              They will get access to this workspace with the role you choose.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            <div className="space-y-2">
              <Label htmlFor="invite-email">Email</Label>
              <Input
                id="invite-email"
                type="email"
                placeholder="colleague@company.com"
                value={inviteEmail}
                onChange={(e) => setInviteEmail(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="invite-name">Name (optional)</Label>
              <Input
                id="invite-name"
                placeholder="John Doe"
                value={inviteName}
                onChange={(e) => setInviteName(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="invite-role">Role</Label>
              <Select value={inviteRole} onValueChange={setInviteRole}>
                <SelectTrigger id="invite-role">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {ROLES.map((r) => (
                    <SelectItem key={r.value} value={r.value}>
                      {r.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setInviteOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => inviteMutation.mutate()}
              disabled={!inviteEmail || inviteMutation.isPending}
            >
              {inviteMutation.isPending ? (
                <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
              ) : (
                <Icon icon="solar:user-plus-bold-duotone" className="mr-2 h-4 w-4" />
              )}
              Invite Member
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
