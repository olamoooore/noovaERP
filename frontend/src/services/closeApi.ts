/**
 * Financial Close API service
 * All endpoints hit /api/v1/close/* (registered by FinancialCloseServiceProvider)
 */
import api from '@/lib/api'

// ─── Types ────────────────────────────────────────────────────────────────────

export interface CloseCycle {
  id: string
  entity_id: string
  period_id: string
  status: 'draft' | 'opened' | 'ready_to_close' | 'closed'
  created_by: string
  opened_at: string | null
  closed_at: string | null
  created_at: string
}

export interface CloseTask {
  id: string
  cycle_id: string
  title: string
  status: 'created' | 'in_progress' | 'submitted' | 'completed' | 'blocked' | 'cancelled'
  priority: 'low' | 'medium' | 'high' | 'critical'
  assigned_to: string | null
  due_date: string | null
  soft_due_date: string | null
  is_critical: boolean
  metadata: Record<string, unknown> | null
  created_at: string
}

export interface Escalation {
  id: string
  task_id: string
  escalation_level: 'low' | 'medium' | 'high' | 'critical'
  status: 'open' | 'resolved'
  reason: string
  escalated_to: string | null
  resolved_at: string | null
  created_at: string
}

export interface AiFinding {
  id: string
  review_id: string
  account_code: string
  finding_type: string
  status: 'open' | 'accepted' | 'dismissed' | 'escalated' | 'edited'
  risk_status: 'low' | 'medium' | 'high' | 'critical'
  confidence: number
  description: string
  accepted_by: string | null
  accepted_at: string | null
  dismissed_by: string | null
  dismissed_at: string | null
  created_at: string
}

export interface ReadinessSummary {
  readiness_score: number
  total_accounts: number
  reviewed_accounts: number
  signed_off_accounts: number
  open_blockers: number
  open_findings: number
  is_ready: boolean
  snapshot_available: boolean
  generated_at: string | null
}

export interface Forecast {
  forecast_available: boolean
  cycle_id?: string
  completion_rate?: number
  total_tasks?: number
  completed_tasks?: number
  overdue_tasks?: number
  blocked_tasks?: number
  risk_level?: 'low' | 'medium' | 'high'
  generated_at?: string
}

export interface ExecutiveSummary {
  summary_available: boolean
  entity_id?: string
  period_id?: string
  readiness_score?: number
  open_blockers?: number
  open_findings?: number
  accounts_signed_off?: number
  total_accounts?: number
  generated_at?: string
}

// ─── Close Cycles ─────────────────────────────────────────────────────────────

export const closeCyclesApi = {
  list: (entityId: string) =>
    api.get<{ data: CloseCycle[] }>('/close/cycles', { params: { entity_id: entityId } }),

  create: (entityId: string, periodId: string) =>
    api.post<{ data: CloseCycle }>('/close/cycles', { entity_id: entityId, period_id: periodId }),

  open: (cycleId: string) =>
    api.post(`/close/cycles/${cycleId}/open`),

  close: (cycleId: string) =>
    api.post(`/close/cycles/${cycleId}/close`),
}

// ─── Tasks ────────────────────────────────────────────────────────────────────

export const closeTasksApi = {
  byCycle: (cycleId: string) =>
    api.get<{ data: CloseTask[] }>(`/close/cycles/${cycleId}/tasks`),

  myTasks: () =>
    api.get<{ data: CloseTask[] }>('/close/tasks/my'),

  reviewQueue: () =>
    api.get<{ data: CloseTask[] }>('/close/tasks/review-queue'),

  start: (taskId: string) =>
    api.post(`/close/tasks/${taskId}/start`),

  submit: (taskId: string) =>
    api.post(`/close/tasks/${taskId}/submit`),

  complete: (taskId: string) =>
    api.post<{ message: string; blockers?: Array<{ depends_on_task_id: string; depends_on_task_title: string; depends_on_status: string }> }>(
      `/close/tasks/${taskId}/complete`
    ),

  returnTask: (taskId: string) =>
    api.post(`/close/tasks/${taskId}/return`),

  block: (taskId: string, reason: string) =>
    api.post(`/close/tasks/${taskId}/block`, { reason }),
}

// ─── Escalations ─────────────────────────────────────────────────────────────

export const escalationsApi = {
  list: (taskId: string) =>
    api.get<{ data: Escalation[] }>(`/close/tasks/${taskId}/escalations`),

  create: (taskId: string, reason: string, escalatedTo?: string) =>
    api.post<{ data: Escalation }>(`/close/tasks/${taskId}/escalations`, { reason, escalated_to: escalatedTo }),

  resolve: (escalationId: string) =>
    api.post(`/close/escalations/${escalationId}/resolve`),
}

// ─── AI Findings ─────────────────────────────────────────────────────────────

export const findingsApi = {
  list: (reviewId: string) =>
    api.get<{ data: AiFinding[] }>(`/close/reviews/${reviewId}/findings`),

  accept: (findingId: string) =>
    api.post(`/close/findings/${findingId}/accept`),

  dismiss: (findingId: string) =>
    api.post(`/close/findings/${findingId}/dismiss`),

  escalate: (findingId: string) =>
    api.post(`/close/findings/${findingId}/escalate`),
}

// ─── Readiness ────────────────────────────────────────────────────────────────

export const readinessApi = {
  get: (entityId: string, periodId: string) =>
    api.get<{ data: ReadinessSummary }>(`/close/readiness/${entityId}/${periodId}`),

  refresh: (entityId: string, periodId: string, cycleId?: string) =>
    api.post<{ data: ReadinessSummary }>(`/close/readiness/${entityId}/${periodId}/refresh`, { cycle_id: cycleId }),
}

// ─── Forecast ─────────────────────────────────────────────────────────────────

export const forecastApi = {
  byCycle: (cycleId: string) =>
    api.get<{ data: Forecast }>(`/close/cycles/${cycleId}/forecast`),

  executive: (entityId: string, periodId: string) =>
    api.get<{ data: ExecutiveSummary }>(`/close/forecast/${entityId}/executive`, { params: { period_id: periodId } }),
}
