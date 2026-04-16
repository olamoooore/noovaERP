import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'

interface PayrollEntry {
  id: number
  employee: { id: number; name: string; employee_number: string } | null
  base_monthly_salary: number
  total_deductions: number
  net_pay: number
  status: string
  items: Array<{ id: number; type: string; category: string; amount: number; description: string }>
}

interface PayrollRun {
  id: number
  reference: string
  period_start: string
  period_end: string
  status: 'draft' | 'calculated' | 'approved' | 'paid'
  total_gross: number
  total_deductions: number
  total_net: number
  currency: string
  entries_count: number
  approved_at: string | null
  notes: string | null
  payrolls?: PayrollEntry[]
  created_at: string
}

const STATUS_COLORS: Record<string, string> = {
  draft:      'bg-gray-100 text-gray-600',
  calculated: 'bg-blue-100 text-blue-700',
  approved:   'bg-green-100 text-green-700',
  paid:       'bg-teal-100 text-teal-700',
}

const EMPTY_FORM = {
  period_start: '',
  period_end:   '',
  currency:     'USD',
  notes:        '',
}

function fmt(val: number, currency = 'USD') {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(val)
}

export function PayrollPage() {
  const queryClient = useQueryClient()

  const [showForm, setShowForm]           = useState(false)
  const [form, setForm]                   = useState({ ...EMPTY_FORM })
  const [error, setError]                 = useState<string | null>(null)
  const [selectedRun, setSelectedRun]     = useState<PayrollRun | null>(null)
  const [showDetail, setShowDetail]       = useState(false)
  const [successMsg, setSuccessMsg]       = useState<string | null>(null)

  // ─── List ──────────────────────────────────────────────────────────────────
  const { data, isLoading } = useQuery({
    queryKey: ['hrm-payroll-runs'],
    queryFn: () => api.get('/hrm/payroll-runs').then(r => r.data),
  })

  const runs: PayrollRun[] = data?.data ?? []

  // ─── Detail (fetch when viewing breakdown) ─────────────────────────────────
  const { data: detailData, isLoading: detailLoading } = useQuery({
    queryKey: ['hrm-payroll-run', selectedRun?.id],
    queryFn: () => api.get(`/hrm/payroll-runs/${selectedRun!.id}`).then(r => r.data.data),
    enabled: showDetail && !!selectedRun,
  })

  // ─── Mutations ─────────────────────────────────────────────────────────────
  const createMutation = useMutation({
    mutationFn: (payload: typeof EMPTY_FORM) => api.post('/hrm/payroll-runs', payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['hrm-payroll-runs'] })
      setShowForm(false)
      setForm({ ...EMPTY_FORM })
      setError(null)
    },
    onError: (err: any) => {
      setError(err.response?.data?.message ?? 'Failed to create payroll run.')
    },
  })

  const actionMutation = useMutation({
    mutationFn: ({ id, action }: { id: number; action: string }) =>
      api.post(`/hrm/payroll-runs/${id}/${action}`),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['hrm-payroll-runs'] })
      queryClient.invalidateQueries({ queryKey: ['hrm-payroll-run', selectedRun?.id] })
      if (variables.action === 'recalculate') {
        setSuccessMsg('Payroll recalculated successfully')
        setTimeout(() => setSuccessMsg(null), 3000)
      }
    },
    onError: (err: any) => {
      alert(err.response?.data?.message ?? 'Action failed.')
    },
  })

  const handleAction = (run: PayrollRun, action: string) => {
    actionMutation.mutate({ id: run.id, action })
  }

  const openDetail = (run: PayrollRun) => {
    setSelectedRun(run)
    setShowDetail(true)
  }

  // ─── Render ────────────────────────────────────────────────────────────────
  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Payroll Runs</h1>
          <p className="text-sm text-gray-500 mt-1">Generate, review, and approve monthly payroll.</p>
        </div>
        <button
          onClick={() => setShowForm(true)}
          className="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700"
        >
          + New Payroll Run
        </button>
      </div>

      {/* Success toast */}
      {successMsg && (
        <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
          ✓ {successMsg}
        </div>
      )}

      {/* Table */}
      <div className="rounded-lg border border-gray-200 bg-white shadow-sm overflow-hidden">
        {isLoading ? (
          <div className="p-8 text-center text-gray-400">Loading…</div>
        ) : runs.length === 0 ? (
          <div className="p-8 text-center text-gray-400">No payroll runs yet.</div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-4 py-3 text-left font-medium text-gray-500">Reference</th>
                <th className="px-4 py-3 text-left font-medium text-gray-500">Period</th>
                <th className="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                <th className="px-4 py-3 text-right font-medium text-gray-500">Gross</th>
                <th className="px-4 py-3 text-right font-medium text-gray-500">Deductions</th>
                <th className="px-4 py-3 text-right font-medium text-gray-500">Net Pay</th>
                <th className="px-4 py-3 text-center font-medium text-gray-500">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {runs.map(run => (
                <tr key={run.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-mono font-medium text-gray-800">
                    <button
                      onClick={() => openDetail(run)}
                      className="text-teal-600 hover:underline"
                    >
                      {run.reference}
                    </button>
                  </td>
                  <td className="px-4 py-3 text-gray-600">
                    {run.period_start} → {run.period_end}
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize ${STATUS_COLORS[run.status] ?? ''}`}>
                      {run.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right text-gray-600">{fmt(Number(run.total_gross), run.currency)}</td>
                  <td className="px-4 py-3 text-right text-red-600">-{fmt(Number(run.total_deductions), run.currency)}</td>
                  <td className="px-4 py-3 text-right font-semibold text-gray-900">{fmt(Number(run.total_net), run.currency)}</td>
                  <td className="px-4 py-3 text-center">
                    <div className="flex items-center justify-center gap-2">
                      {run.status === 'draft' && (
                        <button
                          onClick={() => handleAction(run, 'calculate')}
                          disabled={actionMutation.isPending}
                          className="rounded bg-blue-600 px-2 py-1 text-xs text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                          Calculate
                        </button>
                      )}
                      {run.status === 'calculated' && (
                        <>
                          <button
                            onClick={() => handleAction(run, 'approve')}
                            disabled={actionMutation.isPending}
                            className="rounded bg-green-600 px-2 py-1 text-xs text-white hover:bg-green-700 disabled:opacity-50"
                          >
                            Approve
                          </button>
                          <button
                            onClick={() => handleAction(run, 'recalculate')}
                            disabled={actionMutation.isPending}
                            className="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700 hover:bg-gray-200 disabled:opacity-50"
                            title="Recalculate payroll (for corrections before approval)"
                          >
                            ↺ Recalculate
                          </button>
                        </>
                      )}
                      {run.status === 'approved' && (
                        <button
                          onClick={() => handleAction(run, 'mark-paid')}
                          disabled={actionMutation.isPending}
                          className="rounded bg-teal-600 px-2 py-1 text-xs text-white hover:bg-teal-700 disabled:opacity-50"
                        >
                          Mark Paid
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* New Run Modal */}
      {showForm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 className="mb-4 text-lg font-semibold text-gray-900">New Payroll Run</h2>

            {error && (
              <div className="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                {error}
              </div>
            )}

            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Period Start *</label>
                  <input
                    type="date"
                    value={form.period_start}
                    onChange={e => setForm(f => ({ ...f, period_start: e.target.value }))}
                    className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Period End *</label>
                  <input
                    type="date"
                    value={form.period_end}
                    onChange={e => setForm(f => ({ ...f, period_end: e.target.value }))}
                    className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Currency</label>
                <input
                  type="text"
                  maxLength={3}
                  value={form.currency}
                  onChange={e => setForm(f => ({ ...f, currency: e.target.value.toUpperCase() }))}
                  className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Notes</label>
                <textarea
                  rows={2}
                  value={form.notes}
                  onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
                  className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"
                />
              </div>
            </div>

            <div className="mt-5 flex justify-end gap-3">
              <button
                onClick={() => { setShowForm(false); setError(null) }}
                className="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={() => createMutation.mutate(form)}
                disabled={createMutation.isPending}
                className="rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-50"
              >
                {createMutation.isPending ? 'Creating…' : 'Create Run'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Detail Panel */}
      {showDetail && selectedRun && (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center">
          <div className="w-full max-w-2xl max-h-[80vh] overflow-y-auto rounded-lg bg-white shadow-xl">
            <div className="sticky top-0 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">{selectedRun.reference}</h2>
                <p className="text-xs text-gray-500">{selectedRun.period_start} → {selectedRun.period_end}</p>
              </div>
              <button
                onClick={() => setShowDetail(false)}
                className="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
              >
                ✕
              </button>
            </div>

            <div className="p-6">
              {detailLoading ? (
                <div className="py-8 text-center text-gray-400">Loading entries…</div>
              ) : !detailData?.payrolls?.length ? (
                <div className="py-8 text-center text-gray-400">
                  No entries yet. Use <strong>Calculate</strong> to generate payroll.
                </div>
              ) : (
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-gray-100">
                      <th className="pb-2 text-left font-medium text-gray-500">Employee</th>
                      <th className="pb-2 text-right font-medium text-gray-500">Base Salary</th>
                      <th className="pb-2 text-right font-medium text-gray-500">Deductions</th>
                      <th className="pb-2 text-right font-medium text-gray-500">Net Pay</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {detailData.payrolls.map((entry: PayrollEntry) => (
                      <tr key={entry.id} className="hover:bg-gray-50">
                        <td className="py-2">
                          <div className="font-medium text-gray-800">
                            {entry.employee?.name ?? '—'}
                          </div>
                          <div className="text-xs text-gray-400">{entry.employee?.employee_number}</div>
                        </td>
                        <td className="py-2 text-right text-gray-600">
                          {fmt(Number(entry.base_monthly_salary), detailData.currency)}
                        </td>
                        <td className="py-2 text-right text-red-600">
                          -{fmt(Number(entry.total_deductions), detailData.currency)}
                        </td>
                        <td className="py-2 text-right font-semibold text-gray-900">
                          {fmt(Number(entry.net_pay), detailData.currency)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t-2 border-gray-200 font-semibold">
                      <td className="pt-3 text-gray-700">Total</td>
                      <td className="pt-3 text-right text-gray-700">{fmt(Number(detailData.total_gross), detailData.currency)}</td>
                      <td className="pt-3 text-right text-red-600">-{fmt(Number(detailData.total_deductions), detailData.currency)}</td>
                      <td className="pt-3 text-right text-gray-900">{fmt(Number(detailData.total_net), detailData.currency)}</td>
                    </tr>
                  </tfoot>
                </table>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
