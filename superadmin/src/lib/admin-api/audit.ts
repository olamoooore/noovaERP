import { adminApi } from './client'
import type { AdminActionLog, ObservabilityStats, PaginatedResponse, TenantTimelineEvent } from '@/types/admin'

export interface AuditLogFilters {
  search?: string
  action?: string
  admin_user_id?: number
  target_type?: string
  target_id?: string
  date_from?: string
  date_to?: string
  status?: 'success' | 'failure'
  severity?: 'info' | 'warning' | 'critical'
  page?: number
  per_page?: number
}

export function getAuditLogs(filters: AuditLogFilters = {}) {
  const params = Object.fromEntries(
    Object.entries(filters).filter(([, v]) => v !== '' && v !== undefined),
  )
  return adminApi
    .get<PaginatedResponse<AdminActionLog>>('/audit-logs', { params })
    .then(r => r.data)
}

export function getAuditLog(id: number) {
  return adminApi
    .get<{ data: AdminActionLog }>(`/audit-logs/${id}`)
    .then(r => r.data.data)
}

export function getAuditStats() {
  return adminApi
    .get<{ data: ObservabilityStats }>('/audit-logs/stats')
    .then(r => r.data.data)
}

export function getTenantTimeline(tenantId: string, limit = 50) {
  return adminApi
    .get<{ data: TenantTimelineEvent[] }>(`/tenants/${tenantId}/timeline`, { params: { limit } })
    .then(r => r.data.data)
}

/**
 * Build the export download URL with current filter params.
 * The CSV endpoint is authenticated via Bearer token — we pass it in the
 * query string only as a fallback; the primary mechanism is the Authorization
 * header added by the Axios interceptor. Opening in a new tab loses that
 * header, so we construct a full Axios-backed download instead.
 */
export function downloadAuditCsv(filters: Omit<AuditLogFilters, 'page' | 'per_page'> = {}): Promise<void> {
  const params = Object.fromEntries(
    Object.entries(filters).filter(([, v]) => v !== '' && v !== undefined),
  )
  return adminApi
    .get('/audit-logs/export', { params, responseType: 'blob' })
    .then(r => {
      const url = URL.createObjectURL(new Blob([r.data as BlobPart], { type: 'text/csv' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `audit-log-${new Date().toISOString().slice(0, 10)}.csv`
      a.click()
      URL.revokeObjectURL(url)
    })
}
