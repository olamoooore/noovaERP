import { Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getPendingPlanChanges, applyPlanChange, cancelPlanChange } from '@/lib/admin-api/plan-changes'
import type { TenantPlanChange } from '@/types/admin'

function cents(n: number): string {
  return '$' + (n / 100).toFixed(2)
}

function StatusBadge({ status }: { status: TenantPlanChange['status'] }) {
  const colors = {
    pending:   'bg-yellow-100 text-yellow-800',
    applied:   'bg-green-100 text-green-800',
    cancelled: 'bg-gray-100 text-gray-500',
  }
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${colors[status]}`}>
      {status}
    </span>
  )
}

export default function PendingPlanChangesPage() {
  const qc = useQueryClient()

  const { data: changes = [], isLoading } = useQuery({
    queryKey: ['plan-changes', 'pending'],
    queryFn:  getPendingPlanChanges,
    refetchInterval: 30_000,
  })

  const apply = useMutation({
    mutationFn: (id: number) => applyPlanChange(id),
    onSuccess:  () => qc.invalidateQueries({ queryKey: ['plan-changes'] }),
  })

  const cancel = useMutation({
    mutationFn: (id: number) => cancelPlanChange(id),
    onSuccess:  () => qc.invalidateQueries({ queryKey: ['plan-changes'] }),
  })

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Pending Plan Changes</h1>
        <p className="mt-1 text-sm text-gray-500">
          All plan changes waiting to be applied across all tenants.
        </p>
      </div>

      {isLoading ? (
        <div className="p-6 text-sm text-gray-500">Loading…</div>
      ) : changes.length === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-300 p-16 text-center">
          <div className="text-3xl mb-3">✅</div>
          <p className="text-sm font-medium text-gray-600">No pending plan changes</p>
          <p className="text-xs text-gray-400 mt-1">Request plan changes from individual tenant configuration pages.</p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-gray-200">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Tenant</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Change</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Effective</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Credit</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Charge</th>
                <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Net</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Status</th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Requested By</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 bg-white">
              {changes.map((change: TenantPlanChange) => {
                const net = change.proration_charge_cents - change.proration_credit_cents
                const isApplying = apply.isPending && apply.variables === change.id
                const isCancelling = cancel.isPending && cancel.variables === change.id

                return (
                  <tr key={change.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <Link
                        to={`/tenants/${change.tenant_id}/configuration`}
                        className="font-mono text-xs text-brand-600 hover:underline"
                      >
                        {change.tenant_id.slice(0, 8)}…
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-sm">
                      <span className="text-gray-400">{change.from_family?.name ?? '—'}</span>
                      <span className="mx-1.5 text-gray-300">→</span>
                      <span className="font-medium text-gray-900">{change.to_family?.name}</span>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">{change.effective_date}</td>
                    <td className="px-4 py-3 text-right text-sm text-green-600">
                      {change.proration_credit_cents > 0 ? cents(change.proration_credit_cents) : '—'}
                    </td>
                    <td className="px-4 py-3 text-right text-sm text-gray-700">
                      {change.proration_charge_cents > 0 ? cents(change.proration_charge_cents) : '—'}
                    </td>
                    <td className={`px-4 py-3 text-right text-sm font-medium ${net <= 0 ? 'text-green-600' : 'text-gray-900'}`}>
                      {net < 0 ? `−${cents(Math.abs(net))}` : cents(net)}
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge status={change.status} />
                    </td>
                    <td className="px-4 py-3 text-xs text-gray-500">
                      {change.created_by_admin?.name ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-right">
                      {change.status === 'pending' && (
                        <div className="flex items-center justify-end gap-3">
                          <button
                            onClick={() => apply.mutate(change.id)}
                            disabled={isApplying || isCancelling}
                            className="rounded-lg bg-brand-600 px-3 py-1 text-xs font-medium text-white hover:bg-brand-700 disabled:opacity-50"
                          >
                            {isApplying ? 'Applying…' : 'Apply'}
                          </button>
                          <button
                            onClick={() => cancel.mutate(change.id)}
                            disabled={isApplying || isCancelling}
                            className="text-xs text-red-500 hover:text-red-700 disabled:opacity-50"
                          >
                            {isCancelling ? 'Cancelling…' : 'Cancel'}
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
