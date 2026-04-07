import { adminApi } from './client'
import type { AdminActionLog, PaginatedResponse } from '@/types/admin'

export interface AuditLogFilters {
  search?: string
  action?: string
  admin_user_id?: number
  target_type?: string
  target_id?: string
  date_from?: string
  date_to?: string
  status?: 'success' | 'failure'
  page?: number
  per_page?: number
}

export function getAuditLogs(filters: AuditLogFilters = {}) {
  // Strip empty-string values so they don't reach the server as empty filters
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
