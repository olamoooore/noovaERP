import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import { useAuthStore } from '@/store/authStore'

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

const ROLE_BADGE: Record<string, string> = {
  owner: 'bg-purple-100 text-purple-800',
  admin: 'bg-blue-100 text-blue-800',
  manager: 'bg-green-100 text-green-800',
  sales: 'bg-yellow-100 text-yellow-800',
  accountant: 'bg-indigo-100 text-indigo-800',
  cashier: 'bg-pink-100 text-pink-800',
  viewer: 'bg-gray-100 text-gray-700',
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
    mutationFn: ({ id, role }: { id: number; role: string }) =>
      updateTenantUserRole(id, role),
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

  return (
    <div className="space-y-6 p-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Team Members</h1>
        <p className="text-sm text-muted-foreground mt-1">
          Manage who has access to this workspace and what they can do.
        </p>
      </div>

      {canManageRoles && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">Invite Team Member</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 items-end">
              <div className="space-y-1.5">
                <Label htmlFor="invite-email" className="text-xs">Email</Label>
                <Input
                  id="invite-email"
                  type="email"
                  placeholder="colleague@company.com"
                  value={inviteEmail}
                  onChange={(e) => setInviteEmail(e.target.value)}
                  className="h-9"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="invite-name" className="text-xs">Name (optional)</Label>
                <Input
                  id="invite-name"
                  placeholder="John Doe"
                  value={inviteName}
                  onChange={(e) => setInviteName(e.target.value)}
                  className="h-9"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="invite-role" className="text-xs">Role</Label>
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
              <Button
                onClick={() => inviteMutation.mutate()}
                disabled={!inviteEmail || inviteMutation.isPending}
                className="h-9"
              >
                {inviteMutation.isPending ? 'Inviting…' : 'Invite Member'}
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="text-sm font-medium">
            Active Members ({users.length})
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : users.length === 0 ? (
            <p className="text-sm text-muted-foreground">No users found.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Email</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {users.map((user) => (
                  <TableRow key={user.id}>
                    <TableCell className="font-medium">
                      {user.name}
                      {user.is_default && (
                        <Badge variant="outline" className="ml-2 text-xs">
                          Default
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {user.email}
                    </TableCell>
                    <TableCell>
                      {canManageRoles ? (
                        <Select
                          value={user.role}
                          onValueChange={(value) => {
                            setUpdatingId(user.id)
                            roleMutation.mutate(
                              { id: user.id, role: value },
                              { onSettled: () => setUpdatingId(null) }
                            )
                          }}
                          disabled={updatingId === user.id}
                        >
                          <SelectTrigger className="w-36 h-8 text-xs">
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
                        <Badge
                          className={`text-xs ${ROLE_BADGE[user.role] ?? 'bg-gray-100 text-gray-700'}`}
                          variant="secondary"
                        >
                          {user.role_display || user.role}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      {canRemoveUsers && !user.is_default && (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="text-destructive hover:text-destructive"
                          onClick={() => removeMutation.mutate(user.id)}
                          disabled={removeMutation.isPending}
                        >
                          Remove
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
