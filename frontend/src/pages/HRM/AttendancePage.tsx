import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'
import { Pagination } from '@/components/ui/Pagination'

// ─── Types ────────────────────────────────────────────────────────────────────

interface EmployeeSnippet {
  id: number
  name: string
  employee_number: string
}

interface AttendanceEvent {
  id: number
  employee_id: number
  event_type: 'check_in' | 'check_out'
  captured_at: string
  source_event_id: string
  status: 'pending' | 'processed' | 'failed'
  device_id: string | null
  employee: EmployeeSnippet | null
}

interface AttendanceSummary {
  id: number
  employee_id: number
  date: string
  first_check_in: string | null
  last_check_out: string | null
  worked_minutes: number | null
  worked_hours: number | null
  status: 'present' | 'absent' | 'partial' | 'holiday'
  has_exception: boolean
  exception_type: 'none' | 'missing_check_in' | 'missing_check_out' | 'partial_day'
  employee: EmployeeSnippet | null
}

interface PaginatedResponse<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatMinutes(minutes: number | null): string {
  if (minutes === null) return '—'
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return `${h}h ${m.toString().padStart(2, '0')}m`
}

const EVENT_TYPE_BADGE: Record<string, string> = {
  check_in:  'bg-green-100 text-green-800',
  check_out: 'bg-blue-100  text-blue-800',
}

const EVENT_STATUS_BADGE: Record<string, string> = {
  processed: 'bg-green-100 text-green-700',
  pending:   'bg-yellow-100 text-yellow-700',
  failed:    'bg-red-100   text-red-700',
}

const SUMMARY_STATUS_BADGE: Record<string, string> = {
  present: 'bg-green-100 text-green-800',
  absent:  'bg-red-100   text-red-800',
  partial: 'bg-yellow-100 text-yellow-800',
  holiday: 'bg-purple-100 text-purple-800',
}

const EXCEPTION_LABEL: Record<string, string> = {
  missing_check_in:  'Missing check-in',
  missing_check_out: 'Missing check-out',
  partial_day:       'Partial day',
}

// ─── Filter Bar ───────────────────────────────────────────────────────────────

function FilterSelect({
  label, value, onChange, options,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  options: { value: string; label: string }[]
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-xs font-medium text-gray-500">{label}</span>
      <select
        value={value}
        onChange={e => onChange(e.target.value)}
        className="rounded border border-gray-200 bg-white px-2 py-1 text-sm text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500"
      >
        {options.map(o => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
    </div>
  )
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function EventsTab() {
  const [page, setPage]           = useState(1)
  const [pageSize, setPageSize]   = useState(20)
  const [statusFilter, setStatus] = useState('')
  const [typeFilter, setType]     = useState('')

  const params = new URLSearchParams({ page: String(page), per_page: String(pageSize) })
  if (statusFilter) params.set('status', statusFilter)
  if (typeFilter)   params.set('event_type', typeFilter)

  const { data, isLoading } = useQuery<PaginatedResponse<AttendanceEvent>>({
    queryKey: ['hrm-attendance-events', page, pageSize, statusFilter, typeFilter],
    queryFn: () =>
      api.get(`/hrm/attendance-events?${params}`).then(r => r.data),
  })

  const events = data?.data ?? []
  const meta   = data?.meta

  return (
    <div className="space-y-3">
      {/* Filter bar */}
      <div className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2">
        <FilterSelect
          label="Status"
          value={statusFilter}
          onChange={v => { setStatus(v); setPage(1) }}
          options={[
            { value: '',          label: 'All statuses' },
            { value: 'pending',   label: 'Pending' },
            { value: 'processed', label: 'Processed' },
            { value: 'failed',    label: 'Failed' },
          ]}
        />
        <FilterSelect
          label="Type"
          value={typeFilter}
          onChange={v => { setType(v); setPage(1) }}
          options={[
            { value: '',          label: 'All types' },
            { value: 'check_in',  label: 'Check In' },
            { value: 'check_out', label: 'Check Out' },
          ]}
        />
        {(statusFilter || typeFilter) && (
          <button
            onClick={() => { setStatus(''); setType(''); setPage(1) }}
            className="ml-auto text-xs text-blue-600 hover:underline"
          >
            Clear filters
          </button>
        )}
      </div>

      {isLoading ? (
        <p className="py-8 text-center text-sm text-gray-500">Loading…</p>
      ) : (
        <>
          <div className="overflow-x-auto rounded-lg border border-gray-200">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
              <thead className="bg-gray-50">
                <tr>
                  {['Employee', 'Type', 'Captured At', 'Source ID', 'Device', 'Status'].map(h => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 bg-white">
                {events.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-8 text-center text-sm text-gray-400">
                      No attendance events found
                    </td>
                  </tr>
                ) : (
                  events.map(ev => (
                    <tr key={ev.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 font-medium text-gray-900">
                        {ev.employee?.name ?? `#${ev.employee_id}`}
                        <span className="ml-1 text-xs text-gray-400">
                          {ev.employee?.employee_number}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${EVENT_TYPE_BADGE[ev.event_type] ?? ''}`}>
                          {ev.event_type === 'check_in' ? '↓ Check In' : '↑ Check Out'}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-gray-600">{ev.captured_at}</td>
                      <td className="px-4 py-3 font-mono text-xs text-gray-500">{ev.source_event_id}</td>
                      <td className="px-4 py-3 text-gray-500">{ev.device_id ?? '—'}</td>
                      <td className="px-4 py-3">
                        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${EVENT_STATUS_BADGE[ev.status] ?? ''}`}>
                          {ev.status}
                        </span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {meta && (
            <Pagination
              page={page}
              totalPages={meta.last_page}
              onPageChange={setPage}
              from={(meta.current_page - 1) * meta.per_page + 1}
              to={Math.min(meta.current_page * meta.per_page, meta.total)}
              total={meta.total}
              label="events"
              pageSize={pageSize}
              onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
            />
          )}
        </>
      )}
    </div>
  )
}

function SummaryTab() {
  const [page, setPage]                     = useState(1)
  const [pageSize, setPageSize]             = useState(20)
  const [statusFilter, setStatus]           = useState('')
  const [exceptionsOnly, setExceptionsOnly] = useState(false)

  const params = new URLSearchParams({ page: String(page), per_page: String(pageSize) })
  if (statusFilter)   params.set('status', statusFilter)
  if (exceptionsOnly) params.set('has_exception', '1')

  const { data, isLoading } = useQuery<PaginatedResponse<AttendanceSummary>>({
    queryKey: ['hrm-attendance-summaries', page, pageSize, statusFilter, exceptionsOnly],
    queryFn: () =>
      api.get(`/hrm/attendance-summaries?${params}`).then(r => r.data),
  })

  const summaries = data?.data ?? []
  const meta      = data?.meta

  return (
    <div className="space-y-3">
      {/* Filter bar */}
      <div className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2">
        <FilterSelect
          label="Status"
          value={statusFilter}
          onChange={v => { setStatus(v); setPage(1) }}
          options={[
            { value: '',        label: 'All statuses' },
            { value: 'present', label: 'Present' },
            { value: 'absent',  label: 'Absent' },
            { value: 'partial', label: 'Partial' },
            { value: 'holiday', label: 'Holiday' },
          ]}
        />
        <label className="flex cursor-pointer items-center gap-2 text-sm text-gray-600">
          <input
            type="checkbox"
            checked={exceptionsOnly}
            onChange={e => { setExceptionsOnly(e.target.checked); setPage(1) }}
            className="h-3.5 w-3.5 rounded border-gray-300 text-blue-600"
          />
          Exceptions only
        </label>
        {(statusFilter || exceptionsOnly) && (
          <button
            onClick={() => { setStatus(''); setExceptionsOnly(false); setPage(1) }}
            className="ml-auto text-xs text-blue-600 hover:underline"
          >
            Clear filters
          </button>
        )}
      </div>

      {isLoading ? (
        <p className="py-8 text-center text-sm text-gray-500">Loading…</p>
      ) : (
        <>
          <div className="overflow-x-auto rounded-lg border border-gray-200">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
              <thead className="bg-gray-50">
                <tr>
                  {['Employee', 'Date', 'Check In', 'Check Out', 'Hours Worked', 'Status', 'Exception'].map(h => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 bg-white">
                {summaries.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="py-8 text-center text-sm text-gray-400">
                      No attendance summaries found
                    </td>
                  </tr>
                ) : (
                  summaries.map(s => (
                    <tr key={s.id} className={`hover:bg-gray-50 ${s.has_exception ? 'bg-orange-50' : ''}`}>
                      <td className="px-4 py-3 font-medium text-gray-900">
                        {s.employee?.name ?? `#${s.employee_id}`}
                        <span className="ml-1 text-xs text-gray-400">
                          {s.employee?.employee_number}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-gray-600">{s.date}</td>
                      <td className="px-4 py-3 text-gray-600">{s.first_check_in ?? '—'}</td>
                      <td className="px-4 py-3 text-gray-600">{s.last_check_out ?? '—'}</td>
                      <td className="px-4 py-3 font-medium text-gray-800">
                        {formatMinutes(s.worked_minutes)}
                      </td>
                      <td className="px-4 py-3">
                        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize ${SUMMARY_STATUS_BADGE[s.status] ?? ''}`}>
                          {s.status}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        {s.has_exception ? (
                          <span className="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-800">
                            ⚠ {EXCEPTION_LABEL[s.exception_type] ?? s.exception_type}
                          </span>
                        ) : (
                          <span className="text-xs text-gray-300">—</span>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {meta && (
            <Pagination
              page={page}
              totalPages={meta.last_page}
              onPageChange={setPage}
              from={(meta.current_page - 1) * meta.per_page + 1}
              to={Math.min(meta.current_page * meta.per_page, meta.total)}
              total={meta.total}
              label="summaries"
              pageSize={pageSize}
              onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
            />
          )}
        </>
      )}
    </div>
  )
}

// ─── Main Page ────────────────────────────────────────────────────────────────

type Tab = 'summary' | 'events'

export function AttendancePage() {
  const [activeTab, setActiveTab] = useState<Tab>('summary')

  const tabs: { id: Tab; label: string }[] = [
    { id: 'summary', label: 'Daily Summary' },
    { id: 'events',  label: 'Attendance Events' },
  ]

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">Attendance</h1>
        <p className="mt-1 text-sm text-gray-500">
          Event-driven attendance records — ready for IoT / palm-vein integration
        </p>
      </div>

      {/* Tabs */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex gap-6">
          {tabs.map(tab => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`pb-3 text-sm font-medium transition-colors ${
                activeTab === tab.id
                  ? 'border-b-2 border-blue-600 text-blue-600'
                  : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Tab content */}
      {activeTab === 'summary' ? <SummaryTab /> : <EventsTab />}
    </div>
  )
}
