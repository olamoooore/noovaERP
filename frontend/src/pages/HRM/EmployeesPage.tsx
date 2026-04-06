import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'

interface Employee {
  id: number
  employee_number: string
  name: string
  email: string
  contact_number: string | null
  status: 'active' | 'on_leave' | 'probation' | 'terminated'
  department: { id: number; name: string } | null
  location: { id: number; name: string } | null
  manager: { id: number; name: string } | null
  created_at: string
}

interface Department { id: number; name: string }
interface Location { id: number; name: string }

const STATUS_COLORS: Record<string, string> = {
  active:     'bg-green-100 text-green-700',
  on_leave:   'bg-yellow-100 text-yellow-700',
  probation:  'bg-blue-100 text-blue-700',
  terminated: 'bg-red-100 text-red-700',
}

const EMPTY_FORM = {
  name: '', email: '', contact_number: '',
  department_id: '', location_id: '', manager_id: '', status: 'active',
}

export function EmployeesPage() {
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState({ ...EMPTY_FORM })
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['hrm-employees'],
    queryFn: () => api.get('/hrm/employees').then(r => r.data),
  })

  const { data: deptData } = useQuery({
    queryKey: ['hrm-lookup-departments'],
    queryFn: () => api.get('/hrm/lookup/departments').then(r => r.data),
  })

  const { data: locData } = useQuery({
    queryKey: ['hrm-lookup-locations'],
    queryFn: () => api.get('/hrm/lookup/locations').then(r => r.data),
  })

  const createMutation = useMutation({
    mutationFn: (payload: typeof EMPTY_FORM) => api.post('/hrm/employees', payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hrm-employees'] }); closeForm() },
    onError: (e: any) => setError(e.response?.data?.message ?? 'Failed to create employee'),
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: typeof EMPTY_FORM }) =>
      api.put(`/hrm/employees/${id}`, payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hrm-employees'] }); closeForm() },
    onError: (e: any) => setError(e.response?.data?.message ?? 'Failed to update employee'),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/hrm/employees/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['hrm-employees'] }),
  })

  const employees: Employee[] = data?.data ?? []
  const departments: Department[] = deptData?.data ?? []
  const locations: Location[] = locData?.data ?? []

  function openCreate() {
    setForm({ ...EMPTY_FORM })
    setEditingId(null)
    setError(null)
    setShowForm(true)
  }

  function openEdit(emp: Employee) {
    setForm({
      name: emp.name,
      email: emp.email,
      contact_number: emp.contact_number ?? '',
      department_id: emp.department?.id?.toString() ?? '',
      location_id: emp.location?.id?.toString() ?? '',
      manager_id: emp.manager?.id?.toString() ?? '',
      status: emp.status,
    })
    setEditingId(emp.id)
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
      department_id: form.department_id || null,
      location_id: form.location_id || null,
      manager_id: form.manager_id || null,
    } as any
    if (editingId) {
      updateMutation.mutate({ id: editingId, payload })
    } else {
      createMutation.mutate(payload)
    }
  }

  return (
    <div className="p-6">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Employees</h1>
          <p className="text-gray-500 text-sm mt-0.5">{data?.meta?.total ?? 0} total employees</p>
        </div>
        <button
          onClick={openCreate}
          className="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg text-sm font-medium"
        >
          + Add Employee
        </button>
      </div>

      {/* Table */}
      {isLoading ? (
        <div className="text-center py-12 text-gray-400">Loading...</div>
      ) : employees.length === 0 ? (
        <div className="text-center py-12 text-gray-400">No employees yet. Add your first employee.</div>
      ) : (
        <div className="bg-white rounded-lg border overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b">
              <tr>
                {['#', 'Name', 'Email', 'Department', 'Location', 'Status', 'Actions'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {employees.map((emp) => (
                <tr key={emp.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-mono text-gray-500 text-xs">{emp.employee_number}</td>
                  <td className="px-4 py-3 font-medium text-gray-900">{emp.name}</td>
                  <td className="px-4 py-3 text-gray-600">{emp.email}</td>
                  <td className="px-4 py-3 text-gray-600">{emp.department?.name ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-600">{emp.location?.name ?? '—'}</td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-1 rounded-full text-xs font-medium ${STATUS_COLORS[emp.status]}`}>
                      {emp.status.replace('_', ' ')}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex gap-2">
                      <button onClick={() => openEdit(emp)} className="text-teal-600 hover:underline text-xs">Edit</button>
                      <button
                        onClick={() => { if (confirm('Delete this employee?')) deleteMutation.mutate(emp.id) }}
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
          <div className="bg-white rounded-xl shadow-xl w-full max-w-lg p-6">
            <h2 className="text-lg font-bold text-gray-900 mb-4">
              {editingId ? 'Edit Employee' : 'Add Employee'}
            </h2>

            {error && <div className="mb-4 p-3 bg-red-50 text-red-600 rounded text-sm">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="col-span-2">
                  <label className="block text-xs font-medium text-gray-700 mb-1">Full Name *</label>
                  <input
                    required
                    value={form.name}
                    onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  />
                </div>
                <div className="col-span-2">
                  <label className="block text-xs font-medium text-gray-700 mb-1">Email *</label>
                  <input
                    required type="email"
                    value={form.email}
                    onChange={e => setForm(f => ({ ...f, email: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Contact Number</label>
                  <input
                    value={form.contact_number}
                    onChange={e => setForm(f => ({ ...f, contact_number: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Status</label>
                  <select
                    value={form.status}
                    onChange={e => setForm(f => ({ ...f, status: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  >
                    <option value="active">Active</option>
                    <option value="probation">Probation</option>
                    <option value="on_leave">On Leave</option>
                    <option value="terminated">Terminated</option>
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Department</label>
                  <select
                    value={form.department_id}
                    onChange={e => setForm(f => ({ ...f, department_id: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  >
                    <option value="">— None —</option>
                    {departments.map((d: Department) => (
                      <option key={d.id} value={d.id}>{d.name}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Location</label>
                  <select
                    value={form.location_id}
                    onChange={e => setForm(f => ({ ...f, location_id: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                  >
                    <option value="">— None —</option>
                    {locations.map((l: Location) => (
                      <option key={l.id} value={l.id}>{l.name}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="flex justify-end gap-3 pt-2">
                <button type="button" onClick={closeForm} className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={createMutation.isPending || updateMutation.isPending}
                  className="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium disabled:opacity-50"
                >
                  {createMutation.isPending || updateMutation.isPending ? 'Saving...' : (editingId ? 'Update' : 'Create')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
