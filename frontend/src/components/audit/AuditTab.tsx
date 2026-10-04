import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cn } from '@/lib/utils'

export interface AuditEntry {
  id: string
  source: 'audit_log' | 'system_log' | string
  occurred_at: string
  user: { id: number; name: string } | null
  kind: 'field' | 'lifecycle' | string
  event: string
  field: string | null
  path: string | null
  old_value: unknown
  new_value: unknown
  request_id?: string | null
}

interface AuditPayload {
  data: AuditEntry[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  filters: {
    fields: string[]
    users: { id: number; name: string }[]
  }
}

export interface AuditTabProps {
  /**
   * Read-only audit endpoint for this document, e.g. `/invoices/5/audit`.
   * The export route is derived as `${endpoint}/export`.
   */
  endpoint: string
  /**
   * The permission that opens the record (e.g. `invoices.view`). The page
   * already gates on it; the tab double-checks so a stale shell can never
   * show audit rows the user cannot read.
   */
  permission?: string
  title?: string
  className?: string
}

function displayValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function formatWhen(value: string): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/**
 * Issue #905 (SALES-PLAT / PCU1): the shared, read-only "Audit" tab used by
 * every Sales document detail page. It renders the #895 trail — field-level
 * before/after rows from the observer plus explicit lifecycle events — and
 * offers field/user filters and a CSV export (neutralised server-side by the
 * shared #898 formula guard). It never mutates an audit row.
 */
export function AuditTab({ endpoint, permission, title = 'Audit', className }: AuditTabProps) {
  const hasPermission = useAuthStore((state) => state.hasPermission)
  const [field, setField] = useState('')
  const [userId, setUserId] = useState('')
  const [exporting, setExporting] = useState(false)

  const allowed = !permission || hasPermission(permission)

  const params = useMemo(
    () => ({
      ...(field ? { field } : {}),
      ...(userId ? { user_id: Number(userId) } : {}),
      per_page: 100,
    }),
    [field, userId],
  )

  const { data, isLoading, isError } = useQuery({
    queryKey: ['document-audit', endpoint, field, userId],
    queryFn: async () => {
      const response = await api.get(endpoint, { params })
      return response.data as AuditPayload
    },
    enabled: allowed,
  })

  if (!allowed) {
    return null
  }

  const entries = data?.data ?? []
  const fields = data?.filters?.fields ?? []
  const users = data?.filters?.users ?? []

  const downloadFilename = `${endpoint.replace(/^\//, '').replace(/[^\w.-]+/g, '-')}.csv`

  const exportCsv = async () => {
    setExporting(true)
    try {
      const response = await api.get(`${endpoint}/export`, { params, responseType: 'blob' })
      const blob = new Blob([response.data], { type: 'text/csv;charset=utf-8;' })
      const url = window.URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = downloadFilename
      document.body.appendChild(anchor)
      anchor.click()
      document.body.removeChild(anchor)
      window.URL.revokeObjectURL(url)
    } finally {
      setExporting(false)
    }
  }

  return (
    <section data-testid="audit-tab" className={cn('space-y-4', className)}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Icon icon="solar:shield-check-bold-duotone" className="h-5 w-5 text-primary" />
          <h2 className="text-base font-semibold">{title}</h2>
          <span className="text-xs text-muted-foreground">
            Read-only · every field change and lifecycle event from the audit trail
          </span>
        </div>
        <Button
          variant="outline"
          size="sm"
          aria-label="Export audit CSV"
          onClick={exportCsv}
          disabled={exporting}
        >
          <Icon icon="solar:download-minimalistic-bold-duotone" className="mr-1.5 h-4 w-4" />
          {exporting ? 'Exporting…' : 'Export CSV'}
        </Button>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <label className="text-xs font-medium text-muted-foreground" htmlFor="audit-field-filter">
          Field
        </label>
        <select
          id="audit-field-filter"
          aria-label="Filter by field"
          className="h-8 rounded-md border border-input bg-background px-2 text-sm"
          value={field}
          onChange={(event) => setField(event.target.value)}
        >
          <option value="">All fields</option>
          {fields.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>

        <label className="text-xs font-medium text-muted-foreground" htmlFor="audit-user-filter">
          User
        </label>
        <select
          id="audit-user-filter"
          aria-label="Filter by user"
          className="h-8 rounded-md border border-input bg-background px-2 text-sm"
          value={userId}
          onChange={(event) => setUserId(event.target.value)}
        >
          <option value="">All users</option>
          {users.map((user) => (
            <option key={user.id} value={String(user.id)}>
              {user.name}
            </option>
          ))}
        </select>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-10 text-sm text-muted-foreground">
          <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4 animate-spin" />
          Loading audit trail…
        </div>
      ) : isError ? (
        <div className="rounded-md border border-destructive/30 bg-destructive/5 px-4 py-6 text-sm text-destructive">
          Could not load the audit trail.
        </div>
      ) : entries.length === 0 ? (
        <div className="rounded-md border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
          No audit entries yet.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-44">Time</TableHead>
                <TableHead className="w-40">User</TableHead>
                <TableHead>Field</TableHead>
                <TableHead>Change</TableHead>
                <TableHead className="w-44">Event</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {entries.map((entry) => (
                <TableRow key={entry.id}>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                    {formatWhen(entry.occurred_at)}
                  </TableCell>
                  <TableCell className="text-sm">{entry.user?.name ?? 'System'}</TableCell>
                  <TableCell className="text-sm">
                    <span className="font-medium">{entry.path ?? entry.field ?? '—'}</span>
                  </TableCell>
                  <TableCell className="text-sm">
                    <span className="inline-flex items-center gap-1.5">
                      <span className="text-muted-foreground line-through decoration-muted-foreground/40">
                        {displayValue(entry.old_value)}
                      </span>
                      <Icon icon="solar:arrow-right-bold" className="h-3 w-3 text-muted-foreground" />
                      <span className="font-medium">{displayValue(entry.new_value)}</span>
                    </span>
                  </TableCell>
                  <TableCell>
                    <span
                      className={cn(
                        'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                        entry.kind === 'lifecycle'
                          ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'
                          : 'bg-muted text-muted-foreground',
                      )}
                    >
                      {entry.event}
                    </span>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </section>
  )
}

export default AuditTab
