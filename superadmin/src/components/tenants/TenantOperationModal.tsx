import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { pingTenantHealth, runTenantOperation } from '@/lib/admin-api/tenants'

interface TenantOperationModalProps {
  tenantId: string
  operation: 'suspend' | 'reinstate' | 'ping_health'
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
}

export default function TenantOperationModal({
  tenantId,
  operation,
  tenantName,
  onClose,
  onSuccess,
}: TenantOperationModalProps) {
  const [reason, setReason] = useState('')
  const [errorMsg, setErrorMsg] = useState<string | null>(null)
  const meta = LABELS[operation]

  const mutation = useMutation({
    mutationFn: () => {
      if (operation === 'ping_health') return pingTenantHealth(tenantId)
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

        {/* Reason input — only for suspend/reinstate */}
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
            disabled={mutation.isPending}
            className="px-4 py-2 text-sm bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 disabled:opacity-40"
          >
            {mutation.isPending ? 'Running…' : 'Confirm'}
          </button>
        </div>
      </div>
    </div>
  )
}
