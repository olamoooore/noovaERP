import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { getPlanFamilies, createPlanFamily, deletePlanFamily } from '@/lib/admin-api/plans'
import type { PlanFamily } from '@/types/admin'
import { cn } from '@/lib/utils'

function Badge({ active }: { active: boolean }) {
  return (
    <span className={cn(
      'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
      active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500',
    )}>
      {active ? 'Active' : 'Inactive'}
    </span>
  )
}

function CreateFamilyModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const [key, setKey]   = useState('')
  const [name, setName] = useState('')
  const [desc, setDesc] = useState('')
  const [error, setError] = useState<string | null>(null)

  const { mutate, isPending } = useMutation({
    mutationFn: () => createPlanFamily({ key, name, description: desc || undefined }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'plans'] })
      onClose()
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message ?? 'Failed to create plan family.')
    },
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
        <h2 className="mb-4 text-lg font-semibold text-gray-900">New Plan Family</h2>

        <div className="space-y-3">
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">Key <span className="text-red-500">*</span></label>
            <input value={key} onChange={e => setKey(e.target.value)}
              placeholder="e.g. enterprise"
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">Name <span className="text-red-500">*</span></label>
            <input value={name} onChange={e => setName(e.target.value)}
              placeholder="e.g. Enterprise Suite"
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
            <textarea value={desc} onChange={e => setDesc(e.target.value)} rows={2}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
          </div>
        </div>

        {error && <p className="mt-3 text-sm text-red-600">{error}</p>}

        <div className="mt-5 flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
          <button
            onClick={() => mutate()}
            disabled={!key || !name || isPending}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
          >
            {isPending ? 'Creating…' : 'Create'}
          </button>
        </div>
      </div>
    </div>
  )
}

export function PlanListPage() {
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const [showModal, setShowModal]     = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  const { data: families = [], isLoading, isError } = useQuery<PlanFamily[]>({
    queryKey: ['admin', 'plans'],
    queryFn: getPlanFamilies,
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deletePlanFamily(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'plans'] })
      setDeleteError(null)
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      setDeleteError(e.response?.data?.message ?? 'Failed to delete plan family.')
    },
  })

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Plan Families</h1>
          <p className="mt-1 text-sm text-gray-500">Define and manage the plans available to tenants.</p>
        </div>
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
        >
          <Icon icon="solar:add-circle-bold-duotone" width={16} />
          New Plan Family
        </button>
      </div>

      {isLoading && <p className="text-sm text-gray-400">Loading plans…</p>}
      {isError  && <p className="text-sm text-red-500">Failed to load plans.</p>}

      {deleteError && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {deleteError}
        </div>
      )}

      {!isLoading && !isError && (
        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
          <table className="w-full text-sm">
            <thead className="border-b border-gray-200 bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Key</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Versions</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Created</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {families.length === 0 && (
                <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-400">No plan families yet.</td></tr>
              )}
              {families.map((f) => (
                <tr
                  key={f.id}
                  onClick={() => navigate(`/plans/${f.id}`)}
                  className="cursor-pointer hover:bg-gray-50 transition-colors"
                >
                  <td className="px-4 py-3 font-medium text-gray-900">{f.name}</td>
                  <td className="px-4 py-3 font-mono text-xs text-gray-500">{f.key}</td>
                  <td className="px-4 py-3 text-gray-700">{f.versions_count ?? f.versions?.length ?? 0}</td>
                  <td className="px-4 py-3"><Badge active={f.is_active} /></td>
                  <td className="px-4 py-3 text-gray-400">{new Date(f.created_at).toLocaleDateString()}</td>
                  <td className="px-4 py-3 text-right">
                    <button
                      onClick={(e) => {
                        e.stopPropagation()
                        if (confirm(`Delete plan "${f.name}"? This cannot be undone.`)) {
                          deleteMutation.mutate(f.id)
                        }
                      }}
                      disabled={deleteMutation.isPending}
                      className="inline-flex items-center gap-1 rounded-lg border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-40"
                    >
                      <Icon icon="solar:trash-bin-minimalistic-bold-duotone" width={13} />
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showModal && <CreateFamilyModal onClose={() => setShowModal(false)} />}
    </div>
  )
}
