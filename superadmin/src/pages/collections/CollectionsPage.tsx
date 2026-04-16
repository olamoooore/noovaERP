import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  getAtRiskInvoices,
  advanceCollectionStage,
  clearCollectionStage,
  type CollectionInvoice,
} from '@/lib/admin-api/collections'

const STAGE_BADGE: Record<string, string> = {
  reminder:     'bg-yellow-100 text-yellow-800',
  warning:      'bg-orange-100 text-orange-800',
  final_notice: 'bg-red-100 text-red-800',
  suspended:    'bg-gray-200 text-gray-700',
}

const STAGE_LABEL: Record<string, string> = {
  reminder:     'Reminder',
  warning:      'Warning',
  final_notice: 'Final Notice',
  suspended:    'Suspended',
}

const NEXT_STAGE: Record<string | 'null', string> = {
  null:         'reminder',
  reminder:     'warning',
  warning:      'final_notice',
  final_notice: 'suspended',
  suspended:    'suspended',
}

function formatCents(cents: number) {
  return '$' + (cents / 100).toFixed(2)
}

function StageModal({
  invoice,
  onClose,
  onDone,
}: {
  invoice: CollectionInvoice
  onClose: () => void
  onDone: () => void
}) {
  const [note, setNote] = useState('')

  const advance = useMutation({
    mutationFn: () => advanceCollectionStage(invoice.id, note || undefined),
    onSuccess: onDone,
  })

  const clear = useMutation({
    mutationFn: () => clearCollectionStage(invoice.id, note || undefined),
    onSuccess: onDone,
  })

  const currentStage = invoice.collection_stage
  const nextStage = NEXT_STAGE[currentStage ?? 'null']

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6 space-y-4">
        <h2 className="text-lg font-semibold text-gray-900">Collection Action</h2>
        <div className="text-sm text-gray-600 space-y-0.5">
          <p>Invoice: <span className="font-medium">{invoice.invoice_number}</span></p>
          <p>Tenant: <span className="font-medium">{invoice.tenant_id}</span></p>
          <p>Amount: <span className="font-medium">{formatCents(invoice.total)}</span></p>
          <p>Current stage: <span className="font-medium">{currentStage ?? 'None'}</span></p>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Note <span className="text-gray-400">(optional)</span>
          </label>
          <textarea
            value={note}
            onChange={e => setNote(e.target.value)}
            rows={2}
            placeholder="Reason or context…"
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
        </div>

        {(advance.isError || clear.isError) && (
          <p className="text-sm text-red-600">
            {((advance.error || clear.error) as { response?: { data?: { message?: string } } })
              ?.response?.data?.message ?? 'Operation failed.'}
          </p>
        )}

        <div className="flex gap-3 justify-end flex-wrap">
          <button onClick={onClose} className="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">
            Cancel
          </button>
          {currentStage !== null && (
            <button
              onClick={() => clear.mutate()}
              disabled={clear.isPending || advance.isPending}
              className="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-40"
            >
              {clear.isPending ? 'Clearing…' : 'Clear (Good Standing)'}
            </button>
          )}
          {currentStage !== 'suspended' && (
            <button
              onClick={() => advance.mutate()}
              disabled={advance.isPending || clear.isPending}
              className="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-40"
            >
              {advance.isPending ? 'Advancing…' : `Advance → ${STAGE_LABEL[nextStage] ?? nextStage}`}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

export default function CollectionsPage() {
  const qc = useQueryClient()
  const [selected, setSelected] = useState<CollectionInvoice | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'collections'],
    queryFn: getAtRiskInvoices,
  })

  const invoices = data ?? []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">Collections</h1>
        <p className="text-sm text-gray-500 mt-1">
          Overdue invoices and invoices in an active collection lifecycle. Tracked per-invoice for financial accuracy.
        </p>
      </div>

      <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
        {isLoading ? (
          <p className="px-5 py-8 text-sm text-gray-400 text-center">Loading…</p>
        ) : invoices.length === 0 ? (
          <p className="px-5 py-8 text-sm text-gray-400 text-center">No at-risk invoices. All good.</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide">
              <tr>
                <th className="px-5 py-3">Invoice</th>
                <th className="px-5 py-3">Tenant</th>
                <th className="px-5 py-3">Plan</th>
                <th className="px-5 py-3">Amount</th>
                <th className="px-5 py-3">Due Date</th>
                <th className="px-5 py-3">Stage</th>
                <th className="px-5 py-3 text-right">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {invoices.map(inv => (
                <tr key={inv.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3 font-medium text-gray-900 font-mono text-xs">{inv.invoice_number}</td>
                  <td className="px-5 py-3 text-gray-600">{inv.tenant_id}</td>
                  <td className="px-5 py-3 text-gray-500 text-xs">
                    {inv.subscription?.plan_version?.family?.name ?? '—'}{' '}
                    {inv.subscription?.plan_version?.version_label ?? ''}
                  </td>
                  <td className="px-5 py-3 font-medium text-gray-900">{formatCents(inv.total)}</td>
                  <td className="px-5 py-3 text-gray-500">
                    {inv.due_date ? new Date(inv.due_date).toLocaleDateString() : '—'}
                  </td>
                  <td className="px-5 py-3">
                    {inv.collection_stage ? (
                      <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${STAGE_BADGE[inv.collection_stage]}`}>
                        {STAGE_LABEL[inv.collection_stage] ?? inv.collection_stage}
                      </span>
                    ) : (
                      <span className="text-gray-400 text-xs">Overdue</span>
                    )}
                  </td>
                  <td className="px-5 py-3 text-right">
                    <button
                      onClick={() => setSelected(inv)}
                      className="text-sm text-brand-600 hover:text-brand-800 font-medium"
                    >
                      Manage
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {selected && (
        <StageModal
          invoice={selected}
          onClose={() => setSelected(null)}
          onDone={() => {
            setSelected(null)
            qc.invalidateQueries({ queryKey: ['admin', 'collections'] })
          }}
        />
      )}
    </div>
  )
}
