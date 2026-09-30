import { useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
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
import { RolesPermissionsDialog } from '@/components/team/RolesPermissionsDialog'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { cn, formatDate } from '@/lib/utils'

interface TenantUser {
  id: number
  name: string
  email: string
  role: string
  role_display: string | null
  is_default: boolean
  last_accessed_at: string | null
  added_at: string | null
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

async function inviteTenantUser(payload: {
  email: string
  role: string
  name?: string
  password?: string
}): Promise<{ email_sent?: boolean | null; password_set?: boolean }> {
  const res = await api.post('/tenant-users', payload)
  return res.data ?? {}
}

export interface UserCreationRequestRow {
  id: number
  name: string | null
  email: string
  role: string
  note: string | null
  status: 'pending' | 'approved' | 'declined'
  review_note: string | null
  created_at: string
}

async function fetchUserRequests(): Promise<UserCreationRequestRow[]> {
  const res = await api.get('/tenant-users/requests')
  return res.data?.requests ?? []
}

async function requestTenantUser(payload: {
  email: string
  role: string
  name?: string
  note?: string
}): Promise<void> {
  await api.post('/tenant-users/requests', payload)
}

async function sendPasswordLink(userId: number): Promise<{ email_sent?: boolean }> {
  const res = await api.post(`/tenant-users/${userId}/password-link`)
  return res.data ?? {}
}

interface MemberActivityEntry {
  source: string
  action: string | null
  description: string
  at: string
}

async function getMemberActivity(userId: number): Promise<MemberActivityEntry[]> {
  const res = await api.get(`/tenant-users/${userId}/activity`)
  return res.data?.data?.entries ?? []
}

const ACTIVITY_SOURCE_META: Record<string, { icon: string; tone: string }> = {
  accounting: { icon: 'solar:calculator-minimalistic-bold-duotone', tone: 'bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300' },
  invoice: { icon: 'solar:document-text-bold-duotone', tone: 'bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-300' },
  product: { icon: 'solar:box-bold-duotone', tone: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-300' },
  chatter: { icon: 'solar:chat-round-dots-bold-duotone', tone: 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-300' },
  notification: { icon: 'solar:bell-bold-duotone', tone: 'bg-slate-100 text-slate-600 dark:bg-slate-800/70 dark:text-slate-300' },
  system: { icon: 'solar:server-square-cloud-bold-duotone', tone: 'bg-slate-100 text-slate-600 dark:bg-slate-800/70 dark:text-slate-300' },
}

const FALLBACK_ACTIVITY_META = { icon: 'solar:history-bold-duotone', tone: 'bg-muted text-muted-foreground' }

function relativeTime(value: string | null): string {
  if (!value) return 'Never'
  const then = new Date(value).getTime()
  if (Number.isNaN(then)) return 'Never'
  const diffMs = Date.now() - then
  const minutes = Math.floor(diffMs / 60000)
  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  if (days < 7) return `${days}d ago`
  return formatDate(value)
}

export default function TenantUsersPage() {
  const qc = useQueryClient()
  const { toast } = useToast()
  const { role: currentRole, user } = useAuthStore()
  // User creation is platform-only; the invite UI is reserved for the
  // NoovaERP platform team and everyone else files a request.
  const isPlatformSuperAdmin = user?.role === 'platform_super_admin' || currentRole === 'platform_super_admin'
  const [updatingId, setUpdatingId] = useState<number | null>(null)
  const [inviteOpen, setInviteOpen] = useState(false)
  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole] = useState('viewer')
  const [inviteName, setInviteName] = useState('')
  const [inviteMode, setInviteMode] = useState<'link' | 'password'>('link')
  const [invitePassword, setInvitePassword] = useState('')
  const [showInvitePassword, setShowInvitePassword] = useState(false)
  const [linkSendingId, setLinkSendingId] = useState<number | null>(null)
  const [removeTarget, setRemoveTarget] = useState<TenantUser | null>(null)
  const [activityTarget, setActivityTarget] = useState<TenantUser | null>(null)
  const [passwordTarget, setPasswordTarget] = useState<TenantUser | null>(null)
  const [newPassword, setNewPassword] = useState('')
  const [showNewPassword, setShowNewPassword] = useState(false)
  const [rolesOpen, setRolesOpen] = useState(false)
  const [requestOpen, setRequestOpen] = useState(false)
  const [requestName, setRequestName] = useState('')
  const [requestEmail, setRequestEmail] = useState('')
  const [requestRole, setRequestRole] = useState('viewer')
  const [requestNote, setRequestNote] = useState('')

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
      setRemoveTarget(null)
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

  const requestsQuery = useQuery({
    queryKey: ['user-creation-requests'],
    queryFn: fetchUserRequests,
    enabled: canManageRoles,
  })

  const requestMutation = useMutation({
    mutationFn: () => requestTenantUser({
      email: requestEmail,
      role: requestRole,
      name: requestName || undefined,
      note: requestNote || undefined,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['user-creation-requests'] })
      setRequestOpen(false)
      setRequestName('')
      setRequestEmail('')
      setRequestRole('viewer')
      setRequestNote('')
      toast({
        title: 'Request submitted',
        description: 'The NoovaERP team will create the account and share the credentials.',
      })
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not submit request',
        description: err?.response?.data?.message || 'Unknown error',
      })
    },
  })

  const inviteMutation = useMutation({
    mutationFn: () => inviteTenantUser({
      email: inviteEmail,
      role: inviteRole,
      name: inviteName || undefined,
      password: inviteMode === 'password' ? invitePassword : undefined,
    }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['tenant-users'] })
      if (data?.password_set) {
        toast({
          title: 'Member created',
          description: 'They can sign in with the password you set. Share it securely.',
        })
      } else if (data?.email_sent === false) {
        toast({
          title: 'Member added — email not delivered',
          description: 'The set-password email could not be sent. Use "Send password reset link" to retry.',
        })
      } else {
        toast({ title: 'Invitation sent', description: 'They will receive an email with a link to set their password.' })
      }
      setInviteEmail('')
      setInviteName('')
      setInviteRole('viewer')
      setInviteMode('link')
      setInvitePassword('')
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

  const activityQuery = useQuery({
    queryKey: ['tenant-user-activity', activityTarget?.id],
    queryFn: () => getMemberActivity(activityTarget!.id),
    enabled: Boolean(activityTarget),
  })

  const setPasswordMutation = useMutation({
    mutationFn: ({ id, password }: { id: number; password: string }) =>
      api.post(`/tenant-users/${id}/password`, { password }),
    onSuccess: () => {
      toast({ title: 'Password updated', description: 'Their previous sessions were signed out.' })
      setPasswordTarget(null)
      setNewPassword('')
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to set password',
        description: err?.response?.data?.message || 'Unknown error',
      })
    },
  })

  const sendLinkMutation = useMutation({
    mutationFn: (id: number) => sendPasswordLink(id),
    onSuccess: (data) => {
      if (data?.email_sent === false) {
        toast({
          variant: 'destructive',
          title: 'Email not delivered',
          description: 'The reset link could not be emailed. Check the mail configuration and try again.',
        })
      } else {
        toast({ title: 'Reset link sent', description: 'They will receive an email with a link to set a new password.' })
      }
    },
    onError: (err: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to send reset link',
        description: err?.response?.data?.message || 'Unknown error',
      })
    },
    onSettled: () => setLinkSendingId(null),
  })

  const users = useMemo(() => data ?? [], [data])

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
          <div className="flex flex-wrap items-center gap-2">
            <HeaderActionButton variant="outline" icon="solar:shield-keyhole-bold-duotone" onClick={() => setRolesOpen(true)}>
              Roles &amp; Permissions
            </HeaderActionButton>
            {isPlatformSuperAdmin ? (
              <HeaderActionButton icon="solar:user-plus-bold-duotone" onClick={() => setInviteOpen(true)}>
                Invite Team Member
              </HeaderActionButton>
            ) : (
              <HeaderActionButton icon="solar:user-plus-bold-duotone" onClick={() => setRequestOpen(true)}>
                Request New User
              </HeaderActionButton>
            )}
          </div>
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

      {/* User-creation requests (platform team fulfils them) */}
      {canManageRoles && (requestsQuery.data?.length ?? 0) > 0 && (
        <Card className="overflow-hidden">
          <div className="flex items-center gap-3 border-b bg-muted/20 px-6 py-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
              <Icon icon="solar:user-plus-bold-duotone" className="h-5 w-5 text-primary" />
            </div>
            <div>
              <h3 className="text-base font-semibold leading-none">User Requests</h3>
              <p className="mt-1 text-xs text-muted-foreground">
                Accounts are created by the NoovaERP team — requests and their status appear here.
              </p>
            </div>
          </div>
          <CardContent className="p-0">
            <div className="divide-y">
              {(requestsQuery.data ?? []).map((row) => (
                <div key={row.id} className="flex flex-wrap items-center gap-3 px-6 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{row.name || row.email}</p>
                    <p className="truncate text-xs text-muted-foreground">
                      {row.email} · {ROLES.find((r) => r.value === row.role)?.label ?? row.role}
                      {row.note ? ` · ${row.note}` : ''}
                    </p>
                  </div>
                  <span
                    className={cn(
                      'shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-medium capitalize',
                      row.status === 'approved'
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400'
                        : row.status === 'declined'
                          ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400'
                          : 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-400',
                    )}
                  >
                    {row.status}
                  </span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

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
              <p className="text-xs">
                {isPlatformSuperAdmin
                  ? 'Invite a colleague to get started.'
                  : 'Use "Request New User" and the NoovaERP team will create the account.'}
              </p>
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
                        <div className="flex flex-col gap-0.5">
                          <span className="flex items-center gap-1.5 text-xs" title={user.last_accessed_at ? formatDate(user.last_accessed_at) : undefined}>
                            {user.last_accessed_at ? (
                              <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                            ) : (
                              <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/40" />
                            )}
                            <span className={cn(user.last_accessed_at ? 'text-foreground' : 'text-muted-foreground')}>
                              {relativeTime(user.last_accessed_at)}
                            </span>
                          </span>
                          {user.added_at && (
                            <span className="text-[11px] text-muted-foreground">Added {formatDate(user.added_at)}</span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="text-right">
                        {canManageRoles ? (
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button variant="outline" size="sm" className="h-7 rounded-full px-3 text-xs">
                                Manage
                                <Icon icon="solar:alt-arrow-down-bold" className="ml-1.5 h-3 w-3" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                              <DropdownMenuLabel>{user.name}</DropdownMenuLabel>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem onClick={() => setActivityTarget(user)}>
                                <Icon icon="solar:history-bold-duotone" className="mr-2 h-4 w-4" />
                                View activity
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                disabled={linkSendingId === user.id}
                                onClick={() => {
                                  setLinkSendingId(user.id)
                                  sendLinkMutation.mutate(user.id)
                                }}
                              >
                                <Icon icon="solar:letter-unread-bold-duotone" className="mr-2 h-4 w-4" />
                                Send password reset link
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => { setPasswordTarget(user); setNewPassword(''); setShowNewPassword(false) }}>
                                <Icon icon="solar:key-minimalistic-square-bold-duotone" className="mr-2 h-4 w-4" />
                                Set password
                              </DropdownMenuItem>
                              {canRemoveUsers && !user.is_default && (
                                <>
                                  <DropdownMenuSeparator />
                                  <DropdownMenuItem
                                    className="text-destructive focus:bg-destructive/10 focus:text-destructive"
                                    onClick={() => setRemoveTarget(user)}
                                  >
                                    <Icon icon="solar:user-block-bold-duotone" className="mr-2 h-4 w-4" />
                                    Remove from workspace
                                  </DropdownMenuItem>
                                </>
                              )}
                            </DropdownMenuContent>
                          </DropdownMenu>
                        ) : (
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-7 rounded-full px-3 text-xs"
                            onClick={() => setActivityTarget(user)}
                          >
                            <Icon icon="solar:history-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
                            Activity
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

      {/* Roles & permissions matrix */}
      <RolesPermissionsDialog open={rolesOpen} onOpenChange={setRolesOpen} />

      {/* Invite dialog */}
      <Dialog open={requestOpen} onOpenChange={setRequestOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Request New User</DialogTitle>
            <DialogDescription>
              User accounts are created by the NoovaERP platform team. Submit the details and they will
              create the account and send the credentials.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="request-email">Email <span className="text-destructive">*</span></Label>
                <Input
                  id="request-email"
                  type="email"
                  placeholder="colleague@company.com"
                  value={requestEmail}
                  onChange={(e) => setRequestEmail(e.target.value)}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="request-name">Name (optional)</Label>
                <Input
                  id="request-name"
                  placeholder="John Doe"
                  value={requestName}
                  onChange={(e) => setRequestName(e.target.value)}
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="request-role">Role</Label>
              <Select value={requestRole} onValueChange={setRequestRole}>
                <SelectTrigger id="request-role" className="h-9">
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

            <div className="space-y-2">
              <Label htmlFor="request-note">Note (optional)</Label>
              <textarea
                id="request-note"
                value={requestNote}
                onChange={(e) => setRequestNote(e.target.value)}
                rows={2}
                maxLength={1000}
                placeholder="Anything the NoovaERP team should know…"
                className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setRequestOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => requestMutation.mutate()}
              disabled={requestMutation.isPending || !requestEmail.trim()}
            >
              Submit request
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Invite Team Member</DialogTitle>
            <DialogDescription>
              They will get access to this workspace with the role you choose.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="invite-email">Email <span className="text-destructive">*</span></Label>
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
            </div>

            <div className="space-y-2">
              <Label htmlFor="invite-role">Role</Label>
              <Select value={inviteRole} onValueChange={setInviteRole}>
                <SelectTrigger id="invite-role" className="h-9">
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

            <div className="space-y-2">
              <Label>Password delivery</Label>
              <div className="grid gap-2 sm:grid-cols-2">
                <button
                  type="button"
                  onClick={() => setInviteMode('link')}
                  className={cn(
                    'flex items-start gap-3 rounded-xl border p-3 text-left transition-colors',
                    inviteMode === 'link'
                      ? 'border-primary/40 bg-primary/5'
                      : 'border-slate-200/80 hover:bg-muted/40 dark:border-slate-800',
                  )}
                >
                  <Icon
                    icon={inviteMode === 'link' ? 'solar:check-circle-bold' : 'solar:circle-bold'}
                    className={cn('mt-0.5 h-4 w-4 shrink-0', inviteMode === 'link' ? 'text-primary' : 'opacity-50')}
                  />
                  <span className="min-w-0">
                    <span className="block text-sm font-medium">Email a set-password link</span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                      They choose their own password. Recommended.
                    </span>
                  </span>
                </button>
                <button
                  type="button"
                  onClick={() => setInviteMode('password')}
                  className={cn(
                    'flex items-start gap-3 rounded-xl border p-3 text-left transition-colors',
                    inviteMode === 'password'
                      ? 'border-primary/40 bg-primary/5'
                      : 'border-slate-200/80 hover:bg-muted/40 dark:border-slate-800',
                  )}
                >
                  <Icon
                    icon={inviteMode === 'password' ? 'solar:check-circle-bold' : 'solar:circle-bold'}
                    className={cn('mt-0.5 h-4 w-4 shrink-0', inviteMode === 'password' ? 'text-primary' : 'opacity-50')}
                  />
                  <span className="min-w-0">
                    <span className="block text-sm font-medium">Set a password now</span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                      Share it with them securely out-of-band.
                    </span>
                  </span>
                </button>
              </div>

              {inviteMode === 'password' && (
                <div className="space-y-2 pt-1">
                  <Label htmlFor="invite-password">Password <span className="text-destructive">*</span></Label>
                  <div className="flex gap-2">
                    <div className="relative flex-1">
                      <Input
                        id="invite-password"
                        type={showInvitePassword ? 'text' : 'password'}
                        value={invitePassword}
                        onChange={(e) => setInvitePassword(e.target.value)}
                        placeholder="At least 8 characters"
                        className="pr-9"
                      />
                      <button
                        type="button"
                        onClick={() => setShowInvitePassword((v) => !v)}
                        className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        aria-label={showInvitePassword ? 'Hide password' : 'Show password'}
                      >
                        <Icon icon={showInvitePassword ? 'solar:eye-closed-bold' : 'solar:eye-bold'} className="h-4 w-4" />
                      </button>
                    </div>
                    <HeaderActionButton
                      type="button"
                      variant="outline"
                      icon="solar:refresh-bold"
                      onClick={() => {
                        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%'
                        let generated = ''
                        for (let i = 0; i < 12; i++) generated += chars[Math.floor(Math.random() * chars.length)]
                        setInvitePassword(generated)
                        setShowInvitePassword(true)
                      }}
                    >
                      Generate
                    </HeaderActionButton>
                  </div>
                </div>
              )}
            </div>
          </div>

          <DialogFooter>
            <HeaderActionButton variant="outline" onClick={() => setInviteOpen(false)} disabled={inviteMutation.isPending}>
              Cancel
            </HeaderActionButton>
            <HeaderActionButton
              icon="solar:user-plus-bold-duotone"
              loading={inviteMutation.isPending}
              disabled={
                !inviteEmail
                || inviteMutation.isPending
                || (inviteMode === 'password' && invitePassword.length < 8)
              }
              onClick={() => inviteMutation.mutate()}
            >
              Invite Member
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Member activity log */}
      <Dialog open={Boolean(activityTarget)} onOpenChange={(open) => !open && setActivityTarget(null)}>
        <DialogContent className="max-w-xl">
          {activityTarget && (
            <>
              <DialogHeader>
                <div className="flex items-center gap-3">
                  <div className={cn('flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-semibold', styleFor(activityTarget.role).avatar)}>
                    {initials(activityTarget.name, activityTarget.email)}
                  </div>
                  <div className="min-w-0">
                    <DialogTitle className="truncate">{activityTarget.name}</DialogTitle>
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">{activityTarget.email}</p>
                  </div>
                </div>
              </DialogHeader>

              <div className="flex flex-wrap items-center gap-2 py-1">
                <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-medium', styleFor(activityTarget.role).chip)}>
                  <Icon icon={styleFor(activityTarget.role).icon} className="h-3 w-3" />
                  {activityTarget.role_display || activityTarget.role}
                </span>
                <span className="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                  Last access: {relativeTime(activityTarget.last_accessed_at)}
                </span>
                {activityTarget.added_at && (
                  <span className="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                    Added {formatDate(activityTarget.added_at)}
                  </span>
                )}
              </div>

              <div className="max-h-96 space-y-2 overflow-y-auto py-2">
                {activityQuery.isLoading ? (
                  <div className="flex items-center justify-center py-12">
                    <div className="h-7 w-7 animate-spin rounded-full border-b-2 border-primary" />
                  </div>
                ) : (activityQuery.data ?? []).length === 0 ? (
                  <div className="rounded-xl border border-dashed border-slate-300 py-12 text-center text-muted-foreground dark:border-slate-700">
                    <Icon icon="solar:history-bold-duotone" className="mx-auto mb-2 h-10 w-10 opacity-40" />
                    <p className="text-sm">No recorded activity yet</p>
                    <p className="mt-0.5 text-xs">Actions in accounting, documents, products and notifications will appear here.</p>
                  </div>
                ) : (
                  (activityQuery.data ?? []).map((entry, index) => {
                    const meta = ACTIVITY_SOURCE_META[entry.source] ?? FALLBACK_ACTIVITY_META
                    return (
                      <div key={index} className="flex items-start gap-3 rounded-xl border border-slate-200/80 p-3 dark:border-slate-800">
                        <div className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', meta.tone)}>
                          <Icon icon={meta.icon} className="h-4 w-4" />
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="text-sm">{entry.description}</p>
                          <p className="mt-0.5 text-[11px] text-muted-foreground">
                            {formatDate(entry.at)} · {relativeTime(entry.at)}
                          </p>
                        </div>
                      </div>
                    )
                  })
                )}
              </div>

              <DialogFooter>
                <HeaderActionButton variant="outline" onClick={() => setActivityTarget(null)}>
                  Close
                </HeaderActionButton>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      {/* Set password */}
      <Dialog open={Boolean(passwordTarget)} onOpenChange={(open) => !open && setPasswordTarget(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Set a new password</DialogTitle>
            <DialogDescription>
              {passwordTarget
                ? `Set a new password for ${passwordTarget.name} (${passwordTarget.email}). Their existing sessions will be signed out.`
                : undefined}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-2 py-2">
            <Label htmlFor="member-password">Password <span className="text-destructive">*</span></Label>
            <div className="flex gap-2">
              <div className="relative flex-1">
                <Input
                  id="member-password"
                  type={showNewPassword ? 'text' : 'password'}
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  placeholder="At least 8 characters"
                  className="pr-9"
                />
                <button
                  type="button"
                  onClick={() => setShowNewPassword((v) => !v)}
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  aria-label={showNewPassword ? 'Hide password' : 'Show password'}
                >
                  <Icon icon={showNewPassword ? 'solar:eye-closed-bold' : 'solar:eye-bold'} className="h-4 w-4" />
                </button>
              </div>
              <HeaderActionButton
                type="button"
                variant="outline"
                icon="solar:refresh-bold"
                onClick={() => {
                  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%'
                  let generated = ''
                  for (let i = 0; i < 12; i++) generated += chars[Math.floor(Math.random() * chars.length)]
                  setNewPassword(generated)
                  setShowNewPassword(true)
                }}
              >
                Generate
              </HeaderActionButton>
            </div>
          </div>

          <DialogFooter>
            <HeaderActionButton variant="outline" onClick={() => setPasswordTarget(null)} disabled={setPasswordMutation.isPending}>
              Cancel
            </HeaderActionButton>
            <HeaderActionButton
              icon="solar:key-minimalistic-square-bold-duotone"
              loading={setPasswordMutation.isPending}
              disabled={newPassword.length < 8 || setPasswordMutation.isPending}
              onClick={() => passwordTarget && setPasswordMutation.mutate({ id: passwordTarget.id, password: newPassword })}
            >
              Set password
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Remove member confirmation */}
      <ConfirmDialog
        open={Boolean(removeTarget)}
        onOpenChange={(open) => !open && setRemoveTarget(null)}
        title="Remove this member?"
        description={
          removeTarget
            ? `${removeTarget.name} (${removeTarget.email}) will lose access to this workspace. Their account and other workspace memberships are not affected.`
            : undefined
        }
        confirmLabel="Remove"
        isPending={removeMutation.isPending}
        onConfirm={() => removeTarget && removeMutation.mutate(removeTarget.id)}
      />
    </div>
  )
}
