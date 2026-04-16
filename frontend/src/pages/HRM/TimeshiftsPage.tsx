import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'

const DAYS = [
  { key: 'monday',    label: 'Mon' },
  { key: 'tuesday',   label: 'Tue' },
  { key: 'wednesday', label: 'Wed' },
  { key: 'thursday',  label: 'Thu' },
  { key: 'friday',    label: 'Fri' },
  { key: 'saturday',  label: 'Sat' },
  { key: 'sunday',    label: 'Sun' },
]

interface ScheduleDay {
  day: string
  enabled: boolean
  start: string
  end: string
}

interface Timeshift {
  id: number
  name: string
  is_default: boolean
  schedule: ScheduleDay[]
}

function defaultSchedule(): ScheduleDay[] {
  return DAYS.map(d => ({
    day: d.key,
    enabled: ['monday','tuesday','wednesday','thursday','friday'].includes(d.key),
    start: '09:00',
    end: '18:00',
  }))
}

function formatDay(s: ScheduleDay) {
  if (!s.enabled) return 'Off'
  return `${s.start} – ${s.end}`
}

export function TimeshiftsPage() {
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [isDefault, setIsDefault] = useState(false)
  const [schedule, setSchedule] = useState<ScheduleDay[]>(defaultSchedule())
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['hrm-timeshifts'],
    queryFn: () => api.get('/hrm/timeshifts').then(r => r.data),
  })

  const createMutation = useMutation({
    mutationFn: (payload: any) => api.post('/hrm/timeshifts', payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hrm-timeshifts'] }); closeForm() },
    onError: (e: any) => setError(e.response?.data?.message ?? 'Failed to create timeshift'),
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: any }) =>
      api.put(`/hrm/timeshifts/${id}`, payload),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hrm-timeshifts'] }); closeForm() },
    onError: (e: any) => setError(e.response?.data?.message ?? 'Failed to update timeshift'),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/hrm/timeshifts/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['hrm-timeshifts'] }),
  })

  const timeshifts: Timeshift[] = data?.data ?? []

  function openCreate() {
    setName('')
    setIsDefault(false)
    setSchedule(defaultSchedule())
    setEditingId(null)
    setError(null)
    setShowForm(true)
  }

  function openEdit(t: Timeshift) {
    setName(t.name)
    setIsDefault(t.is_default)
    // Merge saved schedule with default to ensure all 7 days present
    const saved = Object.fromEntries((t.schedule ?? []).map(s => [s.day, s]))
    setSchedule(DAYS.map(d => saved[d.key] ?? { day: d.key, enabled: false, start: '09:00', end: '18:00' }))
    setEditingId(t.id)
    setError(null)
    setShowForm(true)
  }

  function closeForm() {
    setShowForm(false)
    setEditingId(null)
    setError(null)
  }

  function toggleDay(idx: number) {
    setSchedule(prev => prev.map((s, i) => i === idx ? { ...s, enabled: !s.enabled } : s))
  }

  function updateDayTime(idx: number, field: 'start' | 'end', value: string) {
    setSchedule(prev => prev.map((s, i) => i === idx ? { ...s, [field]: value } : s))
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    const payload = { name, is_default: isDefault, schedule }
    if (editingId) {
      updateMutation.mutate({ id: editingId, payload })
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
          <h1 className="text-2xl font-bold text-gray-900">Timeshifts</h1>
          <p className="text-gray-500 text-sm mt-0.5">{timeshifts.length} shift{timeshifts.length !== 1 ? 's' : ''} configured</p>
        </div>
        <button
          onClick={openCreate}
          className="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg text-sm font-medium"
        >
          + New Shift
        </button>
      </div>

      {/* Cards */}
      {isLoading ? (
        <div className="text-center py-12 text-gray-400">Loading...</div>
      ) : timeshifts.length === 0 ? (
        <div className="text-center py-12 text-gray-400">No timeshifts yet. Create the first one.</div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {timeshifts.map((t) => (
            <div key={t.id} className="bg-white rounded-lg border shadow-sm p-4">
              <div className="flex items-start justify-between mb-3">
                <div>
                  <h3 className="font-semibold text-gray-900">{t.name}</h3>
                  {t.is_default && (
                    <span className="inline-block mt-1 px-2 py-0.5 bg-teal-50 text-teal-700 text-xs rounded-full font-medium">
                      Default
                    </span>
                  )}
                </div>
                <div className="flex gap-2 text-xs">
                  <button onClick={() => openEdit(t)} className="text-teal-600 hover:underline">Edit</button>
                  <button
                    onClick={() => { if (confirm('Delete this timeshift?')) deleteMutation.mutate(t.id) }}
                    className="text-red-500 hover:underline"
                  >
                    Delete
                  </button>
                </div>
              </div>

              {/* Day grid */}
              <div className="grid grid-cols-7 gap-1 text-center">
                {DAYS.map(d => {
                  const day = (t.schedule ?? []).find(s => s.day === d.key)
                  const on = day?.enabled ?? false
                  return (
                    <div key={d.key} className="flex flex-col items-center gap-0.5">
                      <span className={`text-xs font-medium ${on ? 'text-teal-700' : 'text-gray-300'}`}>
                        {d.label}
                      </span>
                      <div className={`w-6 h-6 rounded-full flex items-center justify-center text-xs ${
                        on ? 'bg-teal-100 text-teal-700' : 'bg-gray-100 text-gray-300'
                      }`}>
                        {on ? '✓' : '–'}
                      </div>
                      {on && (
                        <span className="text-gray-400" style={{ fontSize: '9px' }}>
                          {day?.start}
                        </span>
                      )}
                    </div>
                  )
                })}
              </div>

              {/* Hours summary */}
              {(() => {
                const activeDays = (t.schedule ?? []).filter(s => s.enabled && s.start && s.end)
                if (activeDays.length === 0) return null
                const sample = activeDays[0]
                return (
                  <p className="mt-3 text-xs text-gray-500 border-t pt-2">
                    {activeDays.length} working day{activeDays.length !== 1 ? 's' : ''} &nbsp;·&nbsp; {sample.start} – {sample.end}
                  </p>
                )
              })()}
            </div>
          ))}
        </div>
      )}

      {/* Modal Form */}
      {showForm && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-xl p-6 max-h-[90vh] overflow-y-auto">
            <h2 className="text-lg font-bold text-gray-900 mb-4">
              {editingId ? 'Edit Timeshift' : 'New Timeshift'}
            </h2>

            {error && <div className="mb-4 p-3 bg-red-50 text-red-600 rounded text-sm">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-5">
              {/* Name */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Shift Name *</label>
                <input
                  required
                  value={name}
                  onChange={e => setName(e.target.value)}
                  placeholder="e.g. Standard Office Hours"
                  className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                />
              </div>

              {/* Default toggle */}
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_default"
                  checked={isDefault}
                  onChange={e => setIsDefault(e.target.checked)}
                  className="rounded"
                />
                <label htmlFor="is_default" className="text-sm text-gray-700">
                  Set as default shift
                </label>
              </div>

              {/* Schedule builder */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-2">Weekly Schedule</label>
                <div className="space-y-2">
                  {schedule.map((s, idx) => {
                    const dayLabel = DAYS.find(d => d.key === s.day)?.label ?? s.day
                    return (
                      <div key={s.day} className="flex items-center gap-3">
                        {/* Day toggle */}
                        <button
                          type="button"
                          onClick={() => toggleDay(idx)}
                          className={`w-12 text-center text-xs font-semibold py-1.5 rounded-lg transition-colors ${
                            s.enabled
                              ? 'bg-teal-600 text-white'
                              : 'bg-gray-100 text-gray-400 hover:bg-gray-200'
                          }`}
                        >
                          {dayLabel}
                        </button>

                        {s.enabled ? (
                          <>
                            <input
                              type="time"
                              value={s.start}
                              onChange={e => updateDayTime(idx, 'start', e.target.value)}
                              className="border rounded px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                            />
                            <span className="text-gray-400 text-sm">to</span>
                            <input
                              type="time"
                              value={s.end}
                              onChange={e => updateDayTime(idx, 'end', e.target.value)}
                              className="border rounded px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                            />
                          </>
                        ) : (
                          <span className="text-xs text-gray-400 italic">Day off</span>
                        )}
                      </div>
                    )
                  })}
                </div>
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
