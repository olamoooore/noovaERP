/**
 * Financial Close API service
 * All endpoints hit /api/v1/close/* (registered by FinancialCloseServiceProvider)
 */
import api from '@/lib/api'

// ─── Types ────────────────────────────────────────────────────────────────────

export interface CloseCycle {
  id: string
  entity_id: string
  accounting_book_id: string | null
  period_id: string
  name: string | null
  template_id: string | null
  status: 'draft' | 'opened' | 'ready_to_close' | 'closed'
  created_by: string
  opened_at: string | null
  closed_at: string | null
  created_at: string
  metadata?: Record<string, unknown> | null
}

export interface CycleReadiness {
  ready_to_close: boolean
  incomplete_tasks: number
  open_escalations: number
  authorised_signoffs: number
  required_signoffs: number
  reason?: string
}

export interface ChecklistTemplate {
  id: string
  tenant_id: string
  name: string
  status: 'draft' | 'active' | 'archived'
  created_by: string
  task_definitions: Array<{
    title: string
    priority?: 'low' | 'medium' | 'high' | 'critical'
    is_critical?: boolean
  }>
  metadata: Record<string, unknown> | null
  created_at: string
  updated_at: string
}

export interface CloseTask {
  id: string
  cycle_id: string
  title: string
  status: 'created' | 'assigned' | 'in_progress' | 'submitted' | 'completed' | 'blocked' | 'cancelled'
  priority: 'low' | 'medium' | 'high' | 'critical'
  assigned_to: string | null
  due_date: string | null
  soft_due_date: string | null
  completed_at?: string | null
  is_critical: boolean
  description?: string | null
  owner_label?: string | null
  workstream?: string | null
  deliverable?: string | null
  business_impact?: string | null
  next_step?: string | null
  blocker_reason?: string | null
  related_accounts?: string[]
  evidence_items?: string[]
  checklist_items?: string[]
  checklist_completed?: string[]
  document_links?: Array<{
    type: string
    id: number
    title: string
  }>
  detail_title?: string | null
  dependencies?: Array<{
    id: string
    dependency_type: string
    depends_on_task_id: string
    depends_on_task_title: string | null
    depends_on_task_status: string | null
  }>
  escalations?: Escalation[]
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
  entity_id: string
  account_code: string
  finding_type: string
  status: 'open' | 'accepted' | 'dismissed' | 'escalated' | 'edited'
  risk_status: 'low' | 'medium' | 'high' | 'critical'
  confidence: number
  description: string
  likely_cause: string | null
  recommendation: string | null
  detail_items: Array<{ label: string; value: string }>
  source_refs?: Array<{
    type: string
    id: string
    label: string
    route: string | null
    meta?: Record<string, string | null> | null
  }>
  accepted_by: string | null
  accepted_at: string | null
  dismissed_by: string | null
  dismissed_at: string | null
  created_at: string
}

export interface ReadinessAccount {
  review_id: string
  account_code: string
  account_name: string
  status: 'not_started' | 'in_progress' | 'submitted' | 'approved' | 'locked' | 'returned'
  risk_status: 'normal' | 'watch' | 'high' | 'critical'
  score: number
  open_findings: number
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
  accounts: ReadinessAccount[]
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

export interface LegalEntity {
  id: number
  name: string
  code: string
  group_id: number
  country_code?: string
  base_currency?: string
  status?: string
}

export interface EntityBook {
  id: number
  name: string
  book_type: string
  currency_code: string
  legal_entity_id: number
  is_primary?: boolean
  status?: string
}

export interface FiscalPeriod {
  id: number
  name: string
  code: string
  period_start: string
  period_end: string
  period_type: string
  fiscal_year: number
  status: string
  is_locked: boolean
}

// ─── Close Cycles ─────────────────────────────────────────────────────────────

export const closeCyclesApi = {
  list: (entityId?: string) =>
    api.get<{ data: CloseCycle[] }>('/close/cycles', { params: entityId ? { entity_id: entityId } : undefined }),

  create: (data: {
    entity_id: string
    period_id: string
    name?: string
    accounting_book_id?: string
    template_id?: string
  }) =>
    api.post<{ data: CloseCycle }>('/close/cycles', data),

  open: (cycleId: string) =>
    api.post(`/close/cycles/${cycleId}/open`),

  close: (cycleId: string) =>
    api.post(`/close/cycles/${cycleId}/close`),

  signoff: (cycleId: string, role: string, notes?: string) =>
    api.post(`/close/cycles/${cycleId}/signoff`, { role, notes }),

  signoffCheck: (cycleId: string) =>
    api.get<{ data: { can_signoff: boolean; evaluation: CycleReadiness } }>(`/close/cycles/${cycleId}/signoff-check`),

  revokeSignoff: (cycleId: string) =>
    api.post(`/close/cycles/${cycleId}/revoke-signoff`),

  readiness: (cycleId: string) =>
    api.get<{ data: CycleReadiness }>(`/close/cycles/${cycleId}/readiness`),

  generateTasks: (cycleId: string, templateId?: string) =>
    api.post(`/close/cycles/${cycleId}/generate-tasks`, templateId ? { template_id: templateId } : {}),
}

export const checklistTemplatesApi = {
  list: (status?: string) =>
    api.get<{ data: ChecklistTemplate[] }>('/close/checklist-templates', { params: status ? { status } : undefined }),

  get: (templateId: string) =>
    api.get<{ data: ChecklistTemplate }>(`/close/checklist-templates/${templateId}`),

  create: (data: { name: string; task_definitions: ChecklistTemplate['task_definitions']; metadata?: Record<string, unknown> }) =>
    api.post<{ data: ChecklistTemplate }>('/close/checklist-templates', data),

  update: (templateId: string, data: Partial<ChecklistTemplate>) =>
    api.put<{ data: ChecklistTemplate }>(`/close/checklist-templates/${templateId}`, data),

  destroy: (templateId: string) =>
    api.delete(`/close/checklist-templates/${templateId}`),

  activate: (templateId: string) =>
    api.post<{ data: ChecklistTemplate }>(`/close/checklist-templates/${templateId}/activate`),
}

// ─── Tasks ────────────────────────────────────────────────────────────────────

export const closeTasksApi = {
  users: () =>
    api.get<{ data: Array<{ id: number; name: string; email: string }> }>('/close/users'),

  byCycle: (cycleId: string) =>
    api.get<{ data: CloseTask[] }>(`/close/cycles/${cycleId}/tasks`),

  get: (taskId: string) =>
    api.get<{ data: CloseTask }>(`/close/tasks/${taskId}`),

  myTasks: (status?: string) =>
    api.get<{ data: CloseTask[] }>('/close/tasks/my', { params: status && status !== 'all' ? { status } : undefined }),

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

  checkBlockers: (taskId: string) =>
    api.get<{ can_complete: boolean; blockers: Array<{ type: string; message: string; details?: any }> }>(
      `/close/tasks/${taskId}/blockers`
    ),

  returnTask: (taskId: string) =>
    api.post(`/close/tasks/${taskId}/return`),

  block: (taskId: string, reason: string) =>
    api.post(`/close/tasks/${taskId}/block`, { reason }),

  assign: (taskId: string, assignedTo: string) =>
    api.post(`/close/tasks/${taskId}/assign`, { assigned_to: assignedTo }),

  reassign: (taskId: string, assignedTo: string) =>
    api.post(`/close/tasks/${taskId}/reassign`, { assigned_to: assignedTo }),

  assignmentHistory: (taskId: string) =>
    api.get<{ data: Array<{ id: string; assigned_to: string; assigned_by: string; assigned_at: string }> }>(
      `/close/tasks/${taskId}/assignments`
    ),

  saveChecklist: (taskId: string, completedItems: string[]) =>
    api.post(`/close/tasks/${taskId}/checklist`, { completed_items: completedItems }),

  uploadEvidence: (taskId: string, file: File) => {
    const formData = new FormData()
    formData.append('file', file)
    return api.post(`/close/tasks/${taskId}/evidence`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },

  listEvidence: (taskId: string) =>
    api.get<{ data: Array<{ id: string; file_name: string; file_path: string; status: string; url: string | null; created_at: string }> }>(
      `/close/tasks/${taskId}/evidence`
    ),

  downloadEvidenceUrl: (taskId: string, evidenceId: string) =>
    `/api/financial-close/tasks/${taskId}/evidence/${evidenceId}/download`,

  evidenceAuditTrail: (taskId: string) =>
    api.get<{ data: Array<{ id: string; user_id: string; action: string; was_authorized: boolean; created_at: string }> }>(
      `/close/tasks/${taskId}/evidence/audit-trail`
    ),

  linkDocument: (taskId: string, documentType: string, documentId: number, title?: string) =>
    api.post(`/close/tasks/${taskId}/link-document`, { document_type: documentType, document_id: documentId, title }),

  unlinkDocument: (taskId: string, documentType: string, documentId: number) =>
    api.delete(`/close/tasks/${taskId}/link-document`, { data: { document_type: documentType, document_id: documentId } }),
}

// ─── Escalations ─────────────────────────────────────────────────────────────

export const escalationsApi = {
  list: (taskId: string) =>
    api.get<{ data: Escalation[] }>(`/close/tasks/${taskId}/escalations`),

  create: (taskId: string, reason: string, level?: string, escalatedTo?: string) =>
    api.post<{ data: Escalation }>(`/close/tasks/${taskId}/escalations`, { reason, level: level ?? 'medium', escalated_to: escalatedTo }),

  resolve: (escalationId: string) =>
    api.post(`/close/escalations/${escalationId}/resolve`),

  return: (escalationId: string, reason?: string) =>
    api.post(`/close/escalations/${escalationId}/return`, { reason }),

  approve: (escalationId: string, notes?: string) =>
    api.post(`/close/escalations/${escalationId}/approve`, { notes }),
}

// ─── AI Findings ─────────────────────────────────────────────────────────────

export const findingsApi = {
  listAll: (params?: { status?: string; risk_status?: string; entity_id?: string; period_id?: string }) =>
    api.get<{ data: AiFinding[] }>('/close/findings', { params }),

  list: (reviewId: string) =>
    api.get<{ data: AiFinding[] }>(`/close/reviews/${reviewId}/findings`),

  accept: (findingId: string) =>
    api.post(`/close/findings/${findingId}/accept`),

  dismiss: (findingId: string) =>
    api.post(`/close/findings/${findingId}/dismiss`),

  escalate: (findingId: string) =>
    api.post(`/close/findings/${findingId}/escalate`),

  refreshFindings: (entityId: string, periodId: string) =>
    api.post<{ message: string; findings_created: number; accounts_analyzed: number }>(
      '/close/findings/refresh',
      { entity_id: entityId, period_id: periodId },
    ),
}

// ─── Readiness ────────────────────────────────────────────────────────────────

export const readinessApi = {
  get: (entityId: string, periodId: string, cycleId?: string) =>
    api.get<{ data: ReadinessSummary }>(`/close/readiness/${entityId}/${periodId}`, { params: cycleId ? { cycle_id: cycleId } : undefined }),

  refresh: (entityId: string, periodId: string, cycleId?: string) =>
    api.post<{ data: ReadinessSummary }>(`/close/readiness/${entityId}/${periodId}/refresh`, { cycle_id: cycleId }),

  entities: () =>
    api.get<{ data: { entities: string[]; periods: string[] } }>('/close/readiness/entities'),
}

// ─── Forecast ─────────────────────────────────────────────────────────────────

export const forecastApi = {
  byCycle: (cycleId: string) =>
    api.get<{ data: Forecast }>(`/close/cycles/${cycleId}/forecast`),

  executive: (entityId: string, periodId: string) =>
    api.get<{ data: ExecutiveSummary }>(`/close/forecast/${entityId}/executive`, { params: { period_id: periodId } }),
}

// ─── Audit Trail ──────────────────────────────────────────────────────────────

export interface AuditTrailEntry {
  id: string
  actor_id: string
  actor_name: string
  actor_email: string | null
  action: string
  entity_type: string
  entity_id: string
  description: string
  created_at: string
  source: string
}

export const auditTrailApi = {
  list: (params?: { action?: string; entity_type?: string; date_from?: string; date_to?: string; per_page?: number; page?: number }) =>
    api.get<{ data: AuditTrailEntry[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>('/close/audit-trail', { params }),

  actions: () =>
    api.get<{ data: string[] }>('/close/audit-trail/actions'),

  stats: () =>
    api.get<{ data: { today_count: number; total_count: number } }>('/close/audit-trail/stats'),
}

// ─── Entity / Book / Period lookups ───────────────────────────────────────────

export const entityManagementApi = {
  listEntities: () => api.get<LegalEntity[]>('/accounting/legal-entities'),
  listBooks: (legalEntityId: number) => api.get<EntityBook[]>('/accounting/entity-books', { params: { legal_entity_id: legalEntityId } }),
}

export const fiscalPeriodsApi = {
  list: (params?: { status?: string; fiscal_year?: number }) => api.get<{ data: FiscalPeriod[] }>('/accounting/fiscal-periods', { params }),
}
