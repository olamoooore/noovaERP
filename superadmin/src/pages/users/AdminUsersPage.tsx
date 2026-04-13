import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  getAdminUsers,
  inviteAdminUser,
  updateAdminRole,
  deactivateAdminUser,
  type AdminUser,
} from '@/lib/admin-api/users'

const ROLES = [
  { value: 'platform_super_admin', label: 'Super Admin' },
  { value: 'platform_ops',         label: 'Platform Ops' },
  { value: 'sales_ops',            label: 'Sales Ops' },
  { value: 'billing_ops',          label: 'Billing Ops' },
  { value: 'support_readonly',     label: 'Support (Read-only)' },
]

const ROLE_BADGE: Record<string, string> = {
  platform_super_admin: 'bg-purple-100 text-purple-800',
  platform_ops:         'bg-blue-100 text-blue-800',
  sales_ops:            'bg-green-100 text-green-800',
  billing_ops:          'bg-yellow-100 text-yellow-800',
  support_readonly:     'bg-gray-100 text-gray-700',
}

export default function AdminUsersPage() {
  const qc = useQueryClient()
  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole]   = useState('platform_ops')
  const [inviteLink, setInviteLink]   = useState<string | null>(null)
  const [inviteError, setInviteError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'users'],
    queryFn: getAdminUsers,
  })

  const inviteMutation = useMutation({
    mutationFn: () => inviteAdminUser(inviteEmail, inviteRole),
    onSuccess: (result) => {
      setInviteLink(result.accept_url)
      setInviteEmail('')
      setInviteError(null)
      qc.invalidateQueries({ queryKey: ['admin', 'users'] })
    },
    onError: (err: unknown) => {
      const axiosErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const msg = axiosErr?.response?.data?.errors
        ? Object.values(axiosErr.response.data.errors).flat().join(' ')
        : axiosErr?.response?.data?.message ?? 'Failed to create invitation.'
      setInviteError(msg)
    },
  })

  const roleMutation = useMutation({
    mutationFn: ({ id, role }: { id: number; role: string }) => updateAdminRole(id, role),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'users'] }),
  })

  const deactivateMutation = useMutation({
    mutationFn: (id: number) => deactivateAdminUser(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'users'] }),
  })

  const admins      = data?.admins      ?? []
  const invitations = data?.invitations ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">Admin Users</h1>
        <p className="text-sm text-gray-500 mt-1">Manage who has access to this control plane.</p>
      </div>

      {/* Invite form */}
      <div className="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
        <h2 className="text-sm font-semibold text-gray-700">Invite New Admin</h2>
        <div className="flex gap-3 flex-wrap">
          <input
            type="email"
            value={inviteEmail}
            onChange={e => setInviteEmail(e.target.value)}
            placeholder="admin@company.com"
            className="flex-1 min-w-[200px] border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <select
            value={inviteRole}
            onChange={e => setInviteRole(e.target.value)}
            className="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
          >
            {ROLES.map(r => (
              <option key={r.value} value={r.value}>{r.label}</option>
            ))}
          </select>
          <button
            onClick={() => inviteMutation.mutate()}
            disabled={!inviteEmail || inviteMutation.isPending}
            className="px-4 py-2 text-sm bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 disabled:opacity-40"
          >
            {inviteMutation.isPending ? 'Sending…' : 'Send Invite'}
          </button>
        </div>
        {inviteError && (
          <p className="text-sm text-red-600">{inviteError}</p>
        )}
        {inviteLink && (
          <div className="bg-green-50 border border-green-200 rounded-lg p-3 space-y-1">
            <p className="text-xs font-medium text-green-800">Invitation created — share this link:</p>
            <code className="block text-xs break-all text-green-900 select-all">{inviteLink}</code>
          </div>
        )}
      </div>

      {/* Active admins */}
      <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <div className="px-5 py-3 border-b border-gray-100">
          <h2 className="text-sm font-semibold text-gray-700">Active Admins ({admins.length})</h2>
        </div>
        {isLoading ? (
          <p className="px-5 py-6 text-sm text-gray-400">Loading…</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide">
              <tr>
                <th className="px-5 py-3">Name</th>
                <th className="px-5 py-3">Email</th>
                <th className="px-5 py-3">Role</th>
                <th className="px-5 py-3">Joined</th>
                <th className="px-5 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {admins.map((admin: AdminUser) => (
                <tr key={admin.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3 font-medium text-gray-900">{admin.name}</td>
                  <td className="px-5 py-3 text-gray-600">{admin.email}</td>
                  <td className="px-5 py-3">
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${ROLE_BADGE[admin.role] ?? 'bg-gray-100 text-gray-700'}`}>
                      {ROLES.find(r => r.value === admin.role)?.label ?? admin.role}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-gray-500">
                    {new Date(admin.created_at).toLocaleDateString()}
                  </td>
                  <td className="px-5 py-3">
                    <div className="flex gap-2 justify-end">
                      <select
                        defaultValue={admin.role}
                        onChange={e => roleMutation.mutate({ id: admin.id, role: e.target.value })}
                        className="border border-gray-200 rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-brand-500"
                      >
                        {ROLES.map(r => (
                          <option key={r.value} value={r.value}>{r.label}</option>
                        ))}
                      </select>
                      <button
                        onClick={() => {
                          if (confirm(`Deactivate ${admin.email}?`)) {
                            deactivateMutation.mutate(admin.id)
                          }
                        }}
                        className="text-xs text-red-600 hover:text-red-800"
                      >
                        Deactivate
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Pending invitations */}
      {invitations.length > 0 && (
        <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
          <div className="px-5 py-3 border-b border-gray-100">
            <h2 className="text-sm font-semibold text-gray-700">Pending Invitations ({invitations.length})</h2>
          </div>
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide">
              <tr>
                <th className="px-5 py-3">Email</th>
                <th className="px-5 py-3">Role</th>
                <th className="px-5 py-3">Invited By</th>
                <th className="px-5 py-3">Expires</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {invitations.map(inv => (
                <tr key={inv.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3 text-gray-700">{inv.email}</td>
                  <td className="px-5 py-3">
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${ROLE_BADGE[inv.role] ?? 'bg-gray-100 text-gray-700'}`}>
                      {ROLES.find(r => r.value === inv.role)?.label ?? inv.role}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-gray-500">{inv.invited_by?.name ?? '—'}</td>
                  <td className="px-5 py-3 text-gray-500">{new Date(inv.expires_at).toLocaleDateString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
