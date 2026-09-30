import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  approveUserRequest,
  declineUserRequest,
  getUserRequests,
  type TenantUserCreationRequest,
} from '@/lib/admin-api/user-requests'

const STATUS_TABS = ['pending', 'approved', 'declined', 'all'] as const

const STATUS_BADGE: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800',
  approved: 'bg-green-100 text-green-800',
  declined: 'bg-red-100 text-red-700',
}

const ROLE_LABEL: Record<string, string> = {
  owner: 'Owner',
  admin: 'Admin',
  manager: 'Manager',
  accountant: 'Accountant',
  sales: 'Sales',
  viewer: 'Viewer',
  member: 'Member',
}

export default function UserRequestsPage() {
  const qc = useQueryClient()
  const [status, setStatus] = useState<string>('pending')
  const [busyId, setBusyId] = useState<number | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'user-requests', status],
    queryFn: () => getUserRequests(status),
  })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['admin', 'user-requests'] })
  }

  const approveMutation = useMutation({
    mutationFn: (id: number) => approveUserRequest(id),
    onMutate: (id) => {
      setBusyId(id)
      setMessage(null)
      setError(null)
    },
    onSuccess: (res) => {
      setMessage(res?.message || 'Request approved — the account was created.')
      refresh()
    },
    onError: (err: any) => setError(err?.response?.data?.message || 'Approval failed'),
    onSettled: () => setBusyId(null),
  })

  const declineMutation = useMutation({
    mutationFn: ({ id, note }: { id: number; note?: string }) => declineUserRequest(id, note),
    onMutate: ({ id }) => {
      setBusyId(id)
      setMessage(null)
      setError(null)
    },
    onSuccess: () => {
      setMessage('Request declined.')
      refresh()
    },
    onError: (err: any) => setError(err?.response?.data?.message || 'Decline failed'),
    onSettled: () => setBusyId(null),
  })

  const handleDecline = (row: TenantUserCreationRequest) => {
    const note = window.prompt(`Reason for declining ${row.email} (optional)`) ?? undefined
    declineMutation.mutate({ id: row.id, note: note || undefined })
  }

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">User Requests</h1>
        <p className="mt-1 text-sm text-gray-500">
          Tenant owners can no longer create accounts directly — they request, you create. Approving
          creates the account through the same member service as the platform invite (set-password
          email to the new user).
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {STATUS_TABS.map((tab) => (
          <button
            key={tab}
            type="button"
            onClick={() => setStatus(tab)}
            className={`rounded-full px-3.5 py-1.5 text-sm font-medium capitalize transition-colors ${
              status === tab
                ? 'bg-indigo-600 text-white'
                : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
            }`}
          >
            {tab}
          </button>
        ))}
      </div>

      {message && (
        <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
          {message}
        </div>
      )}
      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
        {isLoading ? (
          <div className="px-6 py-16 text-center text-sm text-gray-500">Loading requests…</div>
        ) : (data?.length ?? 0) === 0 ? (
          <div className="px-6 py-16 text-center">
            <p className="text-sm font-medium text-gray-700">No {status === 'all' ? '' : status} requests</p>
            <p className="mt-1 text-xs text-gray-500">
              Tenant owners file these from Settings → Users → “Request New User”.
            </p>
          </div>
        ) : (
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
              <tr>
                <th className="px-4 py-3">Tenant</th>
                <th className="px-4 py-3">Account</th>
                <th className="px-4 py-3">Role</th>
                <th className="px-4 py-3">Requested by</th>
                <th className="px-4 py-3">Note</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {(data ?? []).map((row) => (
                <tr key={row.id} className="align-top">
                  <td className="px-4 py-3 font-medium text-gray-900">
                    {row.tenant_name || row.tenant_id}
                  </td>
                  <td className="px-4 py-3">
                    <div className="font-medium text-gray-900">{row.name || row.email}</div>
                    <div className="text-xs text-gray-500">{row.email}</div>
                  </td>
                  <td className="px-4 py-3 text-gray-700">{ROLE_LABEL[row.role] ?? row.role}</td>
                  <td className="px-4 py-3 text-gray-700">{row.requested_by_name || '—'}</td>
                  <td className="max-w-[16rem] px-4 py-3 text-xs text-gray-500">
                    {row.note || row.review_note || '—'}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${
                        STATUS_BADGE[row.status] ?? 'bg-gray-100 text-gray-700'
                      }`}
                    >
                      {row.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    {row.status === 'pending' ? (
                      <div className="flex justify-end gap-2">
                        <button
                          type="button"
                          disabled={busyId === row.id}
                          onClick={() => approveMutation.mutate(row.id)}
                          className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        >
                          {busyId === row.id ? 'Working…' : 'Approve & create'}
                        </button>
                        <button
                          type="button"
                          disabled={busyId === row.id}
                          onClick={() => handleDecline(row)}
                          className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                        >
                          Decline
                        </button>
                      </div>
                    ) : (
                      <span className="text-xs text-gray-400">
                        {row.reviewed_at ? new Date(row.reviewed_at).toLocaleDateString() : '—'}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}
