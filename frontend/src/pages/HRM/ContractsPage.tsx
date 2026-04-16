import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'

interface Employee { id: number; name: string; employee_number: string }
interface Timeshift { id: number; name: string }

interface Contract {
  id: number
  employee: Employee | null
  job_title: string | null
  employment_type: string
  status: string
  base_monthly_salary: number
  overtime_eligible: boolean
  timeshift: Timeshift | null
  fixed_working_days: number
  paid_leaves: number
  start_date: string | null
  end_date: string | null
  termination_reason: string | null
  created_at: string
}

const STATUS_COLORS: Record<string, string> = {
  draft:       'bg-gray-100 text-gray-600',
  onboarding:  'bg-blue-100 text-blue-700',
  active:      'bg-green-100 text-green-700',
  offboarding: 'bg-yellow-100 text-yellow-700',
  terminated:  'bg-red-100 text-red-700',
}

const EMPTY_FORM = {
  employee_id: '',
  job_title: '',
  employment_type: 'full_time',
  status: 'draft',
  base_monthly_salary: '',
  overtime_eligible: true,
  timeshift_id: '',
  fixed_working_days: '26',
  paid_leaves: '0',
  start_date: '',
  end_date: '',
  termination_reason: '',
}

export function ContractsPage() {
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState({ ...EMPTY_FORM })
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['hrm-contracts'],
    queryFn: () => api.get('/hrm/contracts').then(r => r.data),
  })

  const { data: empData } = useQuery({
    queryKey: ['hrm-employees'],
    queryFn: () => api.get('/hrm/employees').then(r => r.data),
  })

  const { data: shiftData } = useQuery({
    queryKey: ['hrm-timeshifts'],
    queryFn: () => api.get('/hrm/timeshifts').then(r => r.data),
    retry: false,
    throwOnError: false,
  })

  const createMutation = useMutation({
    mutationFn: (payload: any) => api.post('/hrm/contracts', payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hrm-contracts'] }); closeForm() },
    onError: (e: any) => setError(e.response?.data?.message ?? 'Failed to create contract'),
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: any }) =>
      api.put(`/hrm/contracts/${id}`, payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hrm-contracts'] }); closeForm() },
    onError: (e: any) => setError(e.response?.data?.message ?? 'Failed to update contract'),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/hrm/contracts/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['hrm-contracts'] }),
  })

  const contracts: Contract[] = data?.data ?? []
  const employees: Employee[] = empData?.data ?? []
  const timeshifts: Timeshift[] = shiftData?.data ?? shiftData ?? []

  function openCreate() {
    setForm({ ...EMPTY_FORM })
    setEditingId(null)
    setError(null)
    setShowForm(true)
  }

  function openEdit(c: Contract) {
    setForm({
      employee_id: c.employee?.id?.toString() ?? '',
      job_title: c.job_title ?? '',
      employment_type: c.employment_type,
      status: c.status,
      base_monthly_salary: c.base_monthly_salary?.toString() ?? '',
      overtime_eligible: c.overtime_eligible,
      timeshift_id: c.timeshift?.id?.toString() ?? '',
      fixed_working_days: c.fixed_working_days?.toString() ?? '26',
      paid_leaves: c.paid_leaves?.toString() ?? '0',
      start_date: c.start_date ?? '',
      end_date: c.end_date ?? '',
      termination_reason: c.termination_reason ?? '',
    })
    setEditingId(c.id)
    setError(null)
    setShowForm(true)
  }

  function closeForm() {
    setShowForm(false)
    setEditingId(null)
    setForm({ ...EMPTY_FORM })
    setError(null)
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    const payload = {
      ...form,
      employee_id: form.employee_id ? Number(form.employee_id) : undefined,
      base_monthly_salary: form.base_monthly_salary ? Number(form.base_monthly_salary) : 0,
      timeshift_id: form.timeshift_id ? Number(form.timeshift_id) : null,
      fixed_working_days: Number(form.fixed_working_days),
      paid_leaves: Number(form.paid_leaves),
      end_date: form.end_date || null,
      termination_reason: form.termination_reason || null,
    }
    if (editingId) {
      const { employee_id: _, ...updatePayload } = payload
      updateMutation.mutate({ id: editingId, payload: updatePayload })
    } else {
      createMutation.mutate(payload)
    }
  }

  const isPending = createMutation.isPending || updateMutation.isPending

  return (
    <div className="p-6">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Contracts</h1>
          <p className="text-gray-500 text-sm mt-0.5">{data?.meta?.total ?? contracts.length} total contracts</p>
        </div>
        <button
          onClick={openCreate}
          className="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg text-sm font-medium"
        >
          + New Contract
        </button>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className="text-center py-12 text-gray-400">Loading...</div>
      ) : contracts.length === 0 ? (
        <div className="text-center py-12 text-gray-400">No contracts yet. Create the first one.</div>
      ) : (
        <div className="bg-white rounded-lg border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b">
              <tr>
                {['Employee', 'Job Title', 'Type', 'Salary', 'Start Date', 'Status', 'Actions'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {contracts.map((c) => (
                <tr key={c.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <div className="font-medium text-gray-900">{c.employee?.name ?? '—'}</div>
                    <div className="text-xs text-gray-400 font-mono">{c.employee?.employee_number ?? ''}</div>
                  </td>
                  <td className="px-4 py-3 text-gray-600">{c.job_title ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-600 capitalize">{c.employment_type.replace('_', ' ')}</td>
                  <td className="px-4 py-3 text-gray-600">
                    {c.base_monthly_salary != null
                      ? Number(c.base_monthly_salary).toLocaleString(undefined, { minimumFractionDigits: 2 })
                      : '—'}
                  </td>
                  <td className="px-4 py-3 text-gray-600">{c.start_date ?? '—'}</td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-1 rounded-full text-xs font-medium ${STATUS_COLORS[c.status]}`}>
                      {c.status}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex gap-2">
                      <button onClick={() => openEdit(c)} className="text-teal-600 hover:underline text-xs">Edit</button>
                      <button
                        onClick={() => { if (confirm('Delete this contract?')) deleteMutation.mutate(c.id) }}
                        className="text-red-500 hover:underline text-xs"
                      >
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Modal Form */}
      {showForm && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-2xl p-6 max-h-[90vh] overflow-y-auto">
            <h2 className="text-lg font-bold text-gray-900 mb-4">
              {editingId ? 'Edit Contract' : 'New Contract'}
            </h2>

            {error && <div className="mb-4 p-3 bg-red-50 text-red-600 rounded text-sm">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">

                {/* Employee — only on create */}
                {!editingId && (
                  <div className="col-span-2">
                    <label className="block text-xs font-medium text-gray-700 mb-1">Employee *</label>
                    <select
                      required
                      value={form.employee_id}
                      onChange={e => setForm(f => ({ ...f, employee_id: e.target.value }))}
                      className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                    >
                      <option value="">— Select Employee —</option>
                      {employees.map((emp: Employee) => (
                        <option key={emp.id} value={emp.id}>{emp.name} ({emp.employee_number})</option>
                      ))}
                    </select>
                  </div>
                )}

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Job Title</label>
                  <input
                    value={form.job_title}
                    onChange={e => setForm(f => ({ ...f, job_title: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                    placeholder="e.g. Software Engineer"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Employment Type *</label>
                  <select
                    value={form.employment_type}
                    onChange={e => setForm(f => ({ ...f, employment_type: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  >
                    <option value="full_time">Full Time</option>
                    <option value="part_time">Part Time</option>
                    <option value="contract">Contract</option>
                    <option value="intern">Intern</option>
                    <option value="commission">Commission</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Status</label>
                  <select
                    value={form.status}
                    onChange={e => setForm(f => ({ ...f, status: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  >
                    <option value="draft">Draft</option>
                    <option value="onboarding">Onboarding</option>
                    <option value="active">Active</option>
                    <option value="offboarding">Offboarding</option>
                    <option value="terminated">Terminated</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Base Monthly Salary *</label>
                  <input
                    required
                    type="number"
                    min="0"
                    step="0.01"
                    value={form.base_monthly_salary}
                    onChange={e => setForm(f => ({ ...f, base_monthly_salary: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                    placeholder="0.00"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Fixed Working Days</label>
                  <input
                    type="number"
                    min="1"
                    max="31"
                    value={form.fixed_working_days}
                    onChange={e => setForm(f => ({ ...f, fixed_working_days: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Paid Leaves (days/year)</label>
                  <input
                    type="number"
                    min="0"
                    value={form.paid_leaves}
                    onChange={e => setForm(f => ({ ...f, paid_leaves: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Timeshift</label>
                  <select
                    value={form.timeshift_id}
                    onChange={e => setForm(f => ({ ...f, timeshift_id: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  >
                    <option value="">— None —</option>
                    {timeshifts.map((s: Timeshift) => (
                      <option key={s.id} value={s.id}>{s.name}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Start Date</label>
                  <input
                    type="date"
                    value={form.start_date}
                    onChange={e => setForm(f => ({ ...f, start_date: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">End Date</label>
                  <input
                    type="date"
                    value={form.end_date}
                    onChange={e => setForm(f => ({ ...f, end_date: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  />
                </div>

                <div className="col-span-2 flex items-center gap-2">
                  <input
                    type="checkbox"
                    id="overtime_eligible"
                    checked={form.overtime_eligible as boolean}
                    onChange={e => setForm(f => ({ ...f, overtime_eligible: e.target.checked }))}
                    className="rounded"
                  />
                  <label htmlFor="overtime_eligible" className="text-sm text-gray-700">Overtime Eligible</label>
                </div>

                {(form.status === 'terminated' || form.status === 'offboarding') && (
                  <div className="col-span-2">
                    <label className="block text-xs font-medium text-gray-700 mb-1">Termination Reason</label>
                    <textarea
                      value={form.termination_reason}
                      onChange={e => setForm(f => ({ ...f, termination_reason: e.target.value }))}
                      rows={3}
                      className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                    />
                  </div>
                )}
              </div>

              <div className="flex justify-end gap-3 pt-2">
                <button type="button" onClick={closeForm} className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isPending}
                  className="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium disabled:opacity-50"
                >
                  {isPending ? 'Saving...' : (editingId ? 'Update' : 'Create')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
