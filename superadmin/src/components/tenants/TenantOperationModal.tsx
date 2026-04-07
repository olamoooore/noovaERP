import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { pingTenantHealth, runTenantOperation } from '@/lib/admin-api/tenants'
import { getPlanFamilies } from '@/lib/admin-api/plans'
import type { TenantHealthSnapshot, TenantOperation } from '@/types/admin'

interface TenantOperationModalProps {
  tenantId: string
  operation: 'suspend' | 'reinstate' | 'ping_health' | 'migrate_plan'
  tenantName: string
  onClose: () => void
  onSuccess: () => void
}

const LABELS: Record<string, { title: string; description: string; warning?: string }> = {
  suspend: {
    title: 'Suspend Tenant',
    description: 'This will prevent the tenant from accessing the ERP system.',
    warning: 'This action will immediately lock out all users of this tenant.',
  },
  reinstate: {
    title: 'Reinstate Tenant',
    description: 'This will restore full access for the tenant.',
  },
  ping_health: {
    title: 'Ping Health Check',
    description: 'This will run a live connectivity and storage check on the tenant schema.',
  },
  migrate_plan: {
    title: 'Change Plan',
    description: 'Select a new plan version and billing interval for this tenant. A new invoice will be generated automatically.',
  },
}

export default function TenantOperationModal({
  tenantId,
  operation,
  tenantName,
  onClose,
  onSuccess,
}: TenantOperationModalProps) {
  const [reason, setReason]                     = useState('')
  const [errorMsg, setErrorMsg]                 = useState<string | null>(null)
  const [planVersionId, setPlanVersionId]       = useState<number | ''>('')
  const [billingInterval, setBillingInterval]   = useState<'monthly' | 'yearly'>('monthly')

  const meta = LABELS[operation]

  // Only fetch plan families when the migrate_plan modal is open
  const { data: planFamilies, isLoading: plansLoading } = useQuery({
    queryKey: ['admin', 'plans', 'families-with-versions'],
    queryFn: () => getPlanFamilies(),
    enabled: operation === 'migrate_plan',
  })

  const mutation = useMutation<TenantOperation | TenantHealthSnapshot, Error, void>({
    mutationFn: () => {
      if (operation === 'ping_health') return pingTenantHealth(tenantId)
      if (operation === 'migrate_plan') {
        return runTenantOperation(
          tenantId,
          'migrate_plan',
          { plan_version_id: planVersionId, billing_interval: billingInterval },
          reason || undefined,
        )
      }
      return runTenantOperation(tenantId, operation, {}, reason || undefined)
    },
    onSuccess: () => {
      onSuccess()
      onClose()
    },
    onError: (err: unknown) => {
      const msg = err instanceof Error ? err.message : 'Operation failed. Please try again.'
      setErrorMsg(msg)
    },
  })

  const canSubmit = operation !== 'migrate_plan' || planVersionId !== ''

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6 space-y-5">
        {/* Header */}
        <div>
          <h2 className="text-lg font-semibold text-gray-900">{meta.title}</h2>
          <p className="text-sm text-gray-500 mt-1">
            Tenant: <span className="font-medium text-gray-700">{tenantName}</span>
          </p>
        </div>

        {/* Description */}
        <p className="text-sm text-gray-600">{meta.description}</p>

        {/* Warning */}
        {meta.warning && (
          <div className="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
            {meta.warning}
          </div>
        )}

        {/* Plan picker — only for migrate_plan */}
        {operation === 'migrate_plan' && (
          <div className="space-y-3">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                New Plan Version <span className="text-red-500">*</span>
              </label>
              {plansLoading ? (
                <p className="text-sm text-gray-400">Loading plans…</p>
              ) : (
                <select
                  value={planVersionId}
                  onChange={e => setPlanVersionId(Number(e.target.value))}
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                  <option value="">Select a plan version…</option>
                  {planFamilies?.map(family =>
                    family.versions
                      ?.filter(v => v.is_active)
                      .map(version => (
                        <option key={version.id} value={version.id}>
                          {family.name} — {version.version_label}
                        </option>
                      ))
                  )}
                </select>
              )}
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Billing Interval
              </label>
              <select
                value={billingInterval}
                onChange={e => setBillingInterval(e.target.value as 'monthly' | 'yearly')}
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
              </select>
            </div>
          </div>
        )}

        {/* Reason input — for suspend, reinstate, and migrate_plan */}
        {operation !== 'ping_health' && (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Reason <span className="text-gray-400">(optional)</span>
            </label>
            <textarea
              value={reason}
              onChange={e => setReason(e.target.value)}
              rows={3}
              placeholder="Describe why you are performing this action…"
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
            />
          </div>
        )}

        {/* Error */}
        {errorMsg && (
          <div className="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
            {errorMsg}
          </div>
        )}

        {/* Actions */}
        <div className="flex gap-3 justify-end">
          <button
            onClick={onClose}
            disabled={mutation.isPending}
            className="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-40"
          >
            Cancel
          </button>
          <button
            onClick={() => mutation.mutate()}
            disabled={mutation.isPending || !canSubmit}
            className="px-4 py-2 text-sm bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 disabled:opacity-40"
          >
            {mutation.isPending ? 'Running…' : 'Confirm'}
          </button>
        </div>
      </div>
    </div>
  )
}
