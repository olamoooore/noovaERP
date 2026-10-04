import api from './api'

/**
 * Issue #914: operations console API over #902's platform exception queue.
 */
export interface ExceptionQueueRow {
  id: string
  module: string
  exception_type: string
  severity: string
  status: string
  message: string
  document_type: string | null
  document_id: string | null
  legal_entity_id: number | null
  owner_role: string | null
  assigned_to: number | null
  assigned_to_name?: string | null
  retry_handler: string | null
  retry_count: number
  max_retries: number
  next_retry_at: string | null
  resolved_at: string | null
  resolution: string | null
  context: Record<string, unknown> | null
  created_at: string
  updated_at: string
  age_hours: number
  sla_hours: number
  sla_due_at: string | null
  sla_breached: boolean
  can_assign: boolean
  can_retry: boolean
}

export interface ExceptionListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ExceptionStats {
  total: number
  by_type: Record<string, number>
  by_module: Record<string, number>
  by_status: Record<string, number>
  by_severity: Record<string, number>
  by_owner_role: Record<string, number>
  by_entity: Record<string, number>
  sla_breached: number
}

export interface ExceptionFilters {
  type?: string
  module?: string
  legal_entity_id?: string
  status?: string
  owner?: string
  severity?: string
  assigned_to?: string
  search?: string
}

export interface RetryResult {
  handler: string
  outcome: 'resolved' | 'failed'
  error: string | null
}

export const operationsConsoleApi = {
  list: (params: ExceptionFilters) =>
    api.get<{ data: ExceptionQueueRow[]; meta: ExceptionListMeta }>('/platform/exceptions', { params }),

  stats: (params: ExceptionFilters) =>
    api.get<{ data: ExceptionStats }>('/platform/exceptions/stats', { params }),

  assign: (id: string, userId: number) =>
    api.post<{ data: ExceptionQueueRow }>(`/platform/exceptions/${id}/assign`, { user_id: userId }),

  retry: (id: string) =>
    api.post<{ data: ExceptionQueueRow; retry: RetryResult }>(
      `/platform/exceptions/${id}/retry`,
      {},
    ),
}
