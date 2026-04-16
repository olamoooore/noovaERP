import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getInvoices, markInvoicePaid, voidInvoice, generateInvoice } from '@/lib/admin-api/billing'
import { useAuthStore } from '@/lib/store/auth'
import type { SubscriptionInvoice, InvoiceStatus } from '@/types/admin'

const STATUS_OPTIONS = [
  { label: 'All Statuses',   value: '' },
  { label: 'Open',           value: 'open' },
  { label: 'Paid',           value: 'paid' },
  { label: 'Draft',          value: 'draft' },
  { label: 'Void',           value: 'void' },
  { label: 'Uncollectible',  value: 'uncollectible' },
]

const INV_STATUS_STYLES: Record<InvoiceStatus, string> = {
  draft:         'bg-gray-100 text-gray-600',
  open:          'bg-yellow-100 text-yellow-800',
  paid:          'bg-green-100 text-green-800',
  void:          'bg-gray-200 text-gray-500',
  uncollectible: 'bg-red-100 text-red-700',
}

function formatDate(d: string | null | undefined) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function formatMoney(cents: number, currency = 'USD') {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(cents / 100)
}

function SummaryCard({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="bg-white rounded-xl border border-gray-200 p-5">
      <p className="text-sm text-gray-500">{label}</p>
      <p className="text-2xl font-bold text-gray-900 mt-1">{value}</p>
      {sub && <p className="text-xs text-gray-400 mt-0.5">{sub}</p>}
    </div>
  )
}

export default function BillingPage() {
  const queryClient = useQueryClient()
  const { user } = useAuthStore()
  const isReadonly = user?.role === 'support_readonly'

  const [status, setStatus]         = useState('')
  const [search, setSearch]         = useState('')
  const [inputVal, setInputVal]     = useState('')
  const [page, setPage]             = useState(1)
  const [error, setError]           = useState<string | null>(null)

  // Generate invoice panel
  const [showGenerate, setShowGenerate]         = useState(false)
  const [genSubId, setGenSubId]                 = useState('')
  const [genInterval, setGenInterval]           = useState<'monthly' | 'yearly'>('monthly')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'billing', 'invoices', { status, search, page }],
    queryFn: () => getInvoices({ status: status || undefined, search: search || undefined, page }),
  })

  const invoices: SubscriptionInvoice[] = data?.data ?? []
  const meta = data?.meta

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin', 'billing', 'invoices'] })

  const markPaidMutation = useMutation({
    mutationFn: (id: number) => markInvoicePaid(id),
    onSuccess: invalidate,
    onError: () => setError('Failed to mark invoice as paid.'),
  })

  const voidMutation = useMutation({
    mutationFn: (id: number) => voidInvoice(id),
    onSuccess: invalidate,
    onError: () => setError('Failed to void invoice.'),
  })

  const generateMutation = useMutation({
    mutationFn: () => generateInvoice(Number(genSubId), genInterval),
    onSuccess: () => {
      invalidate()
      setShowGenerate(false)
      setGenSubId('')
      setGenInterval('monthly')
    },
    onError: () => setError('Failed to generate invoice. Check the subscription ID.'),
  })

  function handleSearch(e: React.FormEvent) {
    e.preventDefault()
    setSearch(inputVal)
    setPage(1)
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Billing</h1>
          <p className="text-sm text-gray-500 mt-1">
            {meta ? `${meta.total} invoice${meta.total !== 1 ? 's' : ''} total` : 'Loading…'}
          </p>
        </div>
        {!isReadonly && (
          <button
            onClick={() => setShowGenerate(v => !v)}
            className="px-4 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors"
          >
            + Generate Invoice
          </button>
        )}
      </div>

      {/* Generate Invoice Panel */}
      {showGenerate && !isReadonly && (
        <div className="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
          <h2 className="text-sm font-semibold text-gray-700">Generate Invoice Manually</h2>
          <div className="flex flex-wrap gap-3 items-end">
            <div className="flex-1 min-w-48">
              <label className="block text-xs text-gray-500 mb-1">Subscription ID</label>
              <input
                type="number"
                value={genSubId}
                onChange={e => setGenSubId(e.target.value)}
                placeholder="e.g. 1"
                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Billing Interval</label>
              <select
                value={genInterval}
                onChange={e => setGenInterval(e.target.value as 'monthly' | 'yearly')}
                className="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
              </select>
            </div>
            <button
              onClick={() => generateMutation.mutate()}
              disabled={!genSubId || generateMutation.isPending}
              className="px-4 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 disabled:opacity-50 transition-colors"
            >
              {generateMutation.isPending ? 'Generating…' : 'Generate'}
            </button>
            <button
              onClick={() => setShowGenerate(false)}
              className="px-4 py-2 border border-gray-300 text-gray-600 text-sm rounded-lg hover:bg-gray-50"
            >
              Cancel
            </button>
          </div>
        </div>
      )}

      {/* Summary cards */}
      {meta && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <SummaryCard
            label="Total Open"
            value={formatMoney(meta.open_total ?? 0)}
            sub="Outstanding invoices"
          />
          <SummaryCard
            label="Paid This Month"
            value={formatMoney(meta.paid_this_month ?? 0)}
            sub={new Date().toLocaleString('en-US', { month: 'long', year: 'numeric' })}
          />
        </div>
      )}

      {/* Filters */}
      <div className="flex flex-wrap gap-3 items-center">
        <form onSubmit={handleSearch} className="flex gap-2">
          <input
            type="text"
            value={inputVal}
            onChange={e => setInputVal(e.target.value)}
            placeholder="Search by tenant or invoice #…"
            className="border border-gray-300 rounded-lg px-3 py-2 text-sm w-72 focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <button type="submit" className="bg-brand-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-700">
            Search
          </button>
        </form>

        <select
          value={status}
          onChange={e => { setStatus(e.target.value); setPage(1) }}
          className="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
          {STATUS_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      </div>

      {error && (
        <div className="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {/* Table */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-gray-400 text-sm">Loading invoices…</div>
        ) : isError ? (
          <div className="p-12 text-center text-red-500 text-sm">Failed to load invoices.</div>
        ) : invoices.length === 0 ? (
          <div className="p-12 text-center text-gray-400 text-sm">No invoices found.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="px-4 py-3 font-medium text-gray-600">Invoice #</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Tenant</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Plan</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Interval</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Total</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Status</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Due Date</th>
                  <th className="px-4 py-3 font-medium text-gray-600">Paid At</th>
                  {!isReadonly && <th className="px-4 py-3 font-medium text-gray-600">Actions</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {invoices.map(inv => (
                  <tr key={inv.id} className="hover:bg-gray-50 transition-colors">
                    <td className="px-4 py-3 font-mono text-xs text-gray-700">{inv.invoice_number}</td>
                    <td className="px-4 py-3 font-mono text-xs text-brand-700">{inv.tenant_id}</td>
                    <td className="px-4 py-3 text-gray-600">
                      {inv.subscription?.plan_version?.family?.name ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-gray-500 capitalize">{inv.billing_interval}</td>
                    <td className="px-4 py-3 font-medium text-gray-900">
                      {formatMoney(inv.total, inv.currency)}
                    </td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${INV_STATUS_STYLES[inv.status] ?? 'bg-gray-100 text-gray-700'}`}>
                        {inv.status}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-gray-500">{formatDate(inv.due_date)}</td>
                    <td className="px-4 py-3 text-gray-500">{formatDate(inv.paid_at)}</td>
                    {!isReadonly && (
                      <td className="px-4 py-3">
                        <div className="flex gap-3">
                          {inv.status === 'open' && (
                            <button
                              onClick={() => markPaidMutation.mutate(inv.id)}
                              disabled={markPaidMutation.isPending}
                              className="text-xs text-green-700 hover:underline disabled:opacity-50"
                            >
                              Mark Paid
                            </button>
                          )}
                          {(inv.status === 'open' || inv.status === 'draft') && (
                            <button
                              onClick={() => voidMutation.mutate(inv.id)}
                              disabled={voidMutation.isPending}
                              className="text-xs text-red-600 hover:underline disabled:opacity-50"
                            >
                              Void
                            </button>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-gray-500">Page {meta.page} of {meta.last_page}</p>
          <div className="flex gap-2">
            <button disabled={meta.page <= 1} onClick={() => setPage(p => p - 1)}
              className="px-3 py-1.5 text-sm border border-gray-300 rounded-lg disabled:opacity-40 hover:bg-gray-50">
              Previous
            </button>
            <button disabled={meta.page >= meta.last_page} onClick={() => setPage(p => p + 1)}
              className="px-3 py-1.5 text-sm border border-gray-300 rounded-lg disabled:opacity-40 hover:bg-gray-50">
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
