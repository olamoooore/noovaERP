import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'

interface Employee { id: number; name: string; employee_number: string }

interface LeaveRequest {
  id: number
  employee: Employee | null
  date: string
  leave_type: string
  status: 'pending' | 'approved' | 'rejected'
  message: string
  feedback: string | null
}

const STATUS_COLORS: Record<string, string> = {
  pending:  'bg-yellow-100 text-yellow-700',
  approved: 'bg-green-100 text-green-700',
  rejected: 'bg-red-100 text-red-700',
}

const TYPE_LABELS: Record<string, string> = {
  sick:     'Sick Leave',
  vacation: 'Vacation',
  personal: 'Personal',
  unpaid:   'Unpaid',
}

const EMPTY_FORM = {
  employee_id: '',
  date: new Date().toISOString().slice(0, 10),
  leave_type: 'sick',
  message: '',
}

export function LeaveRequestsPage() {
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ ...EMPTY_FORM })
  const [error, setError] = useState<string | null>(null)
  const [filterStatus, setFilterStatus] = useState('')

  // Feedback modal for approve/reject
  const [actionModal, setActionModal] = useState<{ id: number; action: 'approve' | 'reject' } | null>(null)
  const [feedback, setFeedback] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['hrm-leave-requests', filterStatus],
    queryFn: () => api.get('/hrm/leave-requests', {
      params: filterStatus ? { status: filterStatus } : {},
    }).then(r => r.data),
  })

  const { data: empData } = useQuery({
    queryKey: ['hrm-employees'],
    queryFn: () => api.get('/hrm/employees').then(r => r.data),
  })

  const createMutation = useMutation({
    mutationFn: (payload: any) => api.post('/hrm/leave-requests', payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hrm-leave-requests'] }); closeForm() },
    onError: (e: any) => setError(e.response?.data?.message ?? 'Failed to submit request'),
  })

  const actionMutation = useMutation({
    mutationFn: ({ id, action, feedback }: { id: number; action: string; feedback: string }) =>
      api.post(`/hrm/leave-requests/${id}/${action}`, { feedback }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['hrm-leave-requests'] })
      setActionModal(null)
      setFeedback('')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/hrm/leave-requests/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['hrm-leave-requests'] }),
  })

  const requests: LeaveRequest[] = data?.data ?? []
  const employees: Employee[] = empData?.data ?? []

  function closeForm() {
    setShowForm(false)
    setForm({ ...EMPTY_FORM })
    setError(null)
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    createMutation.mutate({ ...form, employee_id: Number(form.employee_id) })
  }

  function handleAction() {
    if (!actionModal) return
    actionMutation.mutate({ id: actionModal.id, action: actionModal.action, feedback })
  }

  return (
    <div className="p-6">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Leave Requests</h1>
          <p className="text-gray-500 text-sm mt-0.5">{requests.length} request{requests.length !== 1 ? 's' : ''}</p>
        </div>
        <div className="flex items-center gap-3">
          <select
            value={filterStatus}
            onChange={e => setFilterStatus(e.target.value)}
            className="border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
          >
            <option value="">All Status</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
          </select>
          <button
            onClick={() => { setForm({ ...EMPTY_FORM }); setError(null); setShowForm(true) }}
            className="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg text-sm font-medium"
          >
            + New Request
          </button>
        </div>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className="text-center py-12 text-gray-400">Loading...</div>
      ) : requests.length === 0 ? (
        <div className="text-center py-12 text-gray-400">No leave requests found.</div>
      ) : (
        <div className="bg-white rounded-lg border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b">
              <tr>
                {['Employee', 'Date', 'Type', 'Status', 'Message', 'Feedback', 'Actions'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {requests.map((r) => (
                <tr key={r.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <div className="font-medium text-gray-900">{r.employee?.name ?? '—'}</div>
                    <div className="text-xs text-gray-400 font-mono">{r.employee?.employee_number ?? ''}</div>
                  </td>
                  <td className="px-4 py-3 text-gray-600">{r.date}</td>
                  <td className="px-4 py-3 text-gray-600">{TYPE_LABELS[r.leave_type] ?? r.leave_type}</td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-1 rounded-full text-xs font-medium ${STATUS_COLORS[r.status]}`}>
                      {r.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-gray-500 text-xs max-w-[160px] truncate">{r.message}</td>
                  <td className="px-4 py-3 text-gray-500 text-xs max-w-[160px] truncate">{r.feedback ?? '—'}</td>
                  <td className="px-4 py-3">
                    <div className="flex gap-2 flex-wrap">
                      {r.status === 'pending' && (
                        <>
                          <button
                            onClick={() => { setActionModal({ id: r.id, action: 'approve' }); setFeedback('') }}
                            className="text-green-600 hover:underline text-xs font-medium"
                          >
                            Approve
                          </button>
                          <button
                            onClick={() => { setActionModal({ id: r.id, action: 'reject' }); setFeedback('') }}
                            className="text-red-500 hover:underline text-xs font-medium"
                          >
                            Reject
                          </button>
                        </>
                      )}
                      <button
                        onClick={() => { if (confirm('Delete this request?')) deleteMutation.mutate(r.id) }}
                        className="text-gray-400 hover:underline text-xs"
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

      {/* New Request Modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
            <h2 className="text-lg font-bold text-gray-900 mb-4">New Leave Request</h2>

            {error && <div className="mb-4 p-3 bg-red-50 text-red-600 rounded text-sm">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
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

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Date *</label>
                  <input
                    required
                    type="date"
                    value={form.date}
                    onChange={e => setForm(f => ({ ...f, date: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Leave Type *</label>
                  <select
                    value={form.leave_type}
                    onChange={e => setForm(f => ({ ...f, leave_type: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  >
                    <option value="sick">Sick Leave</option>
                    <option value="vacation">Vacation</option>
                    <option value="personal">Personal</option>
                    <option value="unpaid">Unpaid</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Reason *</label>
                <textarea
                  required
                  rows={3}
                  value={form.message}
                  onChange={e => setForm(f => ({ ...f, message: e.target.value }))}
                  className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  placeholder="Reason for leave..."
                />
              </div>

              <div className="flex justify-end gap-3 pt-1">
                <button type="button" onClick={closeForm} className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={createMutation.isPending}
                  className="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium disabled:opacity-50"
                >
                  {createMutation.isPending ? 'Submitting...' : 'Submit'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Approve / Reject Modal */}
      {actionModal && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-sm p-6">
            <h2 className="text-lg font-bold text-gray-900 mb-1 capitalize">
              {actionModal.action} Request
            </h2>
            <p className="text-sm text-gray-500 mb-4">Optional feedback to the employee.</p>

            <textarea
              rows={3}
              value={feedback}
              onChange={e => setFeedback(e.target.value)}
              className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
              placeholder="Add feedback (optional)..."
            />

            <div className="flex justify-end gap-3 mt-4">
              <button
                type="button"
                onClick={() => setActionModal(null)}
                className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800"
              >
                Cancel
              </button>
              <button
                onClick={handleAction}
                disabled={actionMutation.isPending}
                className={`px-4 py-2 text-white rounded-lg text-sm font-medium disabled:opacity-50 ${
                  actionModal.action === 'approve'
                    ? 'bg-green-600 hover:bg-green-700'
                    : 'bg-red-600 hover:bg-red-700'
                }`}
              >
                {actionMutation.isPending ? 'Saving...' : `Confirm ${actionModal.action}`}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
