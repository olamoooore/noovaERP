import api from '@/lib/api'

// ─── Types ───────────────────────────────────────────────────────────────────

export type WorkOrderStatus =
  | 'draft'
  | 'scheduled'
  | 'in_progress'
  | 'on_hold'
  | 'completed'
  | 'invoiced'
  | 'cancelled'

export type WorkOrderPriority = 'low' | 'normal' | 'high' | 'urgent'

export interface WorkOrderType {
  id: number
  name: string
  description?: string | null
  color?: string | null
  icon?: string | null
  is_active: boolean
}

export interface Team {
  id: number
  name: string
  description?: string | null
  leader_id?: number | null
  color?: string | null
  is_active: boolean
  leader?: { id: number; name: string; avatar?: string | null } | null
  technicians?: Technician[]
}

export interface Technician {
  id: number
  user_id: number
  team_id?: number | null
  skills?: string | null
  hourly_rate?: number | null
  is_active: boolean
  user?: { id: number; name: string; email: string; avatar?: string | null }
  team?: Team | null
}

export interface WorkOrderPart {
  id: number
  work_order_id: number
  product_id?: number | null
  description: string
  quantity: number
  unit_price: number
  line_total: number
  is_billable: boolean
  stock_deducted: boolean
  product?: { id: number; name: string; sku?: string | null } | null
}

export interface TimeLog {
  id: number
  work_order_id: number
  technician_id?: number | null
  description?: string | null
  start_time: string
  end_time?: string | null
  duration_minutes?: number | null
  hourly_rate: number
  line_total: number
  is_billable: boolean
  technician?: Technician | null
}

export interface WorkOrderActivity {
  id: number
  work_order_id: number
  user_id?: number | null
  type: 'note' | 'status_change' | 'assignment' | 'creation' | 'update' | 'invoice'
  description: string
  created_at: string
  user?: { id: number; name: string; avatar?: string | null } | null
}

export interface WorkOrder {
  id: number
  reference: string
  title: string
  description?: string | null
  type_id?: number | null
  status: WorkOrderStatus
  priority: WorkOrderPriority
  customer_id?: number | null
  team_id?: number | null
  assigned_to?: number | null
  invoice_id?: number | null
  scheduled_start?: string | null
  scheduled_end?: string | null
  actual_start?: string | null
  actual_end?: string | null
  completed_at?: string | null
  location_address?: string | null
  location_lat?: number | null
  location_lng?: number | null
  estimated_duration_hours?: number | null
  customer_rating?: number | null
  customer_feedback?: string | null
  total_parts_amount: number
  total_labor_amount: number
  total_amount: number
  type?: WorkOrderType | null
  customer?: { id: number; name: string; email?: string | null; phone?: string | null } | null
  team?: Team | null
  assignedUser?: { id: number; name: string } | null
  technicians?: Technician[]
  parts?: WorkOrderPart[]
  timeLogs?: TimeLog[]
  activities?: WorkOrderActivity[]
  invoice?: { id: number; number: string } | null
  created_at: string
  updated_at: string
}

export interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface WorkOrderFilters {
  status?: WorkOrderStatus
  priority?: WorkOrderPriority
  type_id?: number
  team_id?: number
  technician_id?: number
  customer_id?: number
  date_from?: string
  date_to?: string
  search?: string
  page?: number
  per_page?: number
}

// ─── Work Orders ─────────────────────────────────────────────────────────────

export const fieldServiceApi = {
  // Work Orders
  getWorkOrders: (filters?: WorkOrderFilters) =>
    api.get<PaginatedResponse<WorkOrder>>('/field-service/work-orders', { params: filters }),

  getWorkOrder: (id: number) =>
    api.get<WorkOrder>(`/field-service/work-orders/${id}`),

  createWorkOrder: (data: Partial<WorkOrder>) =>
    api.post<WorkOrder>('/field-service/work-orders', data),

  updateWorkOrder: (id: number, data: Partial<WorkOrder>) =>
    api.put<WorkOrder>(`/field-service/work-orders/${id}`, data),

  deleteWorkOrder: (id: number) =>
    api.delete(`/field-service/work-orders/${id}`),

  updateStatus: (id: number, status: WorkOrderStatus, notes?: string) =>
    api.post(`/field-service/work-orders/${id}/status`, { status, notes }),

  assignTechnicians: (id: number, technicianIds: number[]) =>
    api.post(`/field-service/work-orders/${id}/assign`, { technician_ids: technicianIds }),

  addNote: (id: number, note: string) =>
    api.post(`/field-service/work-orders/${id}/note`, { note }),

  generateInvoice: (id: number) =>
    api.post(`/field-service/work-orders/${id}/invoice`),

  submitRating: (id: number, rating: number, feedback?: string) =>
    api.post(`/field-service/work-orders/${id}/rating`, { customer_rating: rating, customer_feedback: feedback }),

  duplicateWorkOrder: (id: number) =>
    api.post<WorkOrder>(`/field-service/work-orders/${id}/duplicate`),

  getCalendar: (from: string, to: string) =>
    api.get('/field-service/work-orders/calendar', { params: { from, to } }),

  getMap: () =>
    api.get('/field-service/work-orders/map'),

  // Parts
  getParts: (workOrderId: number) =>
    api.get<WorkOrderPart[]>(`/field-service/work-orders/${workOrderId}/parts`),

  createPart: (workOrderId: number, data: Partial<WorkOrderPart>) =>
    api.post<WorkOrderPart>(`/field-service/work-orders/${workOrderId}/parts`, data),

  updatePart: (partId: number, data: Partial<WorkOrderPart>) =>
    api.put<WorkOrderPart>(`/field-service/parts/${partId}`, data),

  deletePart: (partId: number) =>
    api.delete(`/field-service/parts/${partId}`),

  checkStock: (workOrderId: number) =>
    api.get(`/field-service/work-orders/${workOrderId}/parts/check-stock`),

  // Time Logs
  getTimeLogs: (workOrderId: number) =>
    api.get<TimeLog[]>(`/field-service/work-orders/${workOrderId}/time-logs`),

  createTimeLog: (workOrderId: number, data: Partial<TimeLog>) =>
    api.post<TimeLog>(`/field-service/work-orders/${workOrderId}/time-logs`, data),

  updateTimeLog: (timeLogId: number, data: Partial<TimeLog>) =>
    api.put<TimeLog>(`/field-service/time-logs/${timeLogId}`, data),

  deleteTimeLog: (timeLogId: number) =>
    api.delete(`/field-service/time-logs/${timeLogId}`),

  // Technicians
  getTechnicians: () =>
    api.get<Technician[]>('/field-service/technicians'),

  getTechnician: (id: number) =>
    api.get<Technician>(`/field-service/technicians/${id}`),

  createTechnician: (data: { user_id: number; team_id?: number; skills?: string; hourly_rate?: number }) =>
    api.post<Technician>('/field-service/technicians', data),

  updateTechnician: (id: number, data: Partial<Technician>) =>
    api.put<Technician>(`/field-service/technicians/${id}`, data),

  deleteTechnician: (id: number) =>
    api.delete(`/field-service/technicians/${id}`),

  getTechnicianStats: (id: number) =>
    api.get(`/field-service/technicians/${id}/stats`),

  getNonTechnicians: () =>
    api.get<{ id: number; name: string; email: string }[]>('/field-service/technicians/non-technicians'),

  // Teams
  getTeams: () =>
    api.get<Team[]>('/field-service/teams'),

  getTeam: (id: number) =>
    api.get<Team>(`/field-service/teams/${id}`),

  createTeam: (data: Partial<Team>) =>
    api.post<Team>('/field-service/teams', data),

  updateTeam: (id: number, data: Partial<Team>) =>
    api.put<Team>(`/field-service/teams/${id}`, data),

  deleteTeam: (id: number) =>
    api.delete(`/field-service/teams/${id}`),

  // Config
  getWorkOrderTypes: () =>
    api.get<WorkOrderType[]>('/field-service/config/work-order-types'),

  createWorkOrderType: (data: Partial<WorkOrderType>) =>
    api.post<WorkOrderType>('/field-service/config/work-order-types', data),

  updateWorkOrderType: (id: number, data: Partial<WorkOrderType>) =>
    api.put<WorkOrderType>(`/field-service/config/work-order-types/${id}`, data),

  deleteWorkOrderType: (id: number) =>
    api.delete(`/field-service/config/work-order-types/${id}`),

  // Analytics
  getDashboard: () =>
    api.get('/field-service/analytics/dashboard'),

  getTechnicianPerformance: (from?: string, to?: string) =>
    api.get('/field-service/analytics/technician-performance', { params: { from, to } }),

  getRevenue: (from?: string, to?: string) =>
    api.get('/field-service/analytics/revenue', { params: { from, to } }),

  getJobsAnalysis: (filters?: WorkOrderFilters & { priority?: string }) =>
    api.get('/field-service/analytics/jobs', { params: filters }),

  exportJobsCsvUrl: (filters?: Partial<WorkOrderFilters & { priority?: string }>) => {
    const params = new URLSearchParams(
      Object.fromEntries(Object.entries(filters ?? {}).filter(([, v]) => v !== undefined && v !== '')) as Record<string, string>
    ).toString()
    return `/api/v1/field-service/analytics/jobs/export${params ? `?${params}` : ''}`
  },

  // Gantt
  getGantt: (from: string, to: string) =>
    api.get('/field-service/work-orders/gantt', { params: { from, to } }),

  // Worksheet templates
  getWorksheetTemplates: () =>
    api.get<WorksheetTemplate[]>('/field-service/worksheets/templates'),

  createWorksheetTemplate: (data: Partial<WorksheetTemplate>) =>
    api.post<WorksheetTemplate>('/field-service/worksheets/templates', data),

  updateWorksheetTemplate: (id: number, data: Partial<WorksheetTemplate>) =>
    api.put<WorksheetTemplate>(`/field-service/worksheets/templates/${id}`, data),

  deleteWorksheetTemplate: (id: number) =>
    api.delete(`/field-service/worksheets/templates/${id}`),

  // Worksheet responses (per work order)
  getWorksheet: (workOrderId: number) =>
    api.get<{ template: WorksheetTemplate | null; response: WorksheetResponse | null }>(
      `/field-service/work-orders/${workOrderId}/worksheet`
    ),

  saveWorksheet: (workOrderId: number, data: { template_id: number; responses: Record<string, unknown> }) =>
    api.post<WorksheetResponse>(`/field-service/work-orders/${workOrderId}/worksheet`, data),

  signWorksheet: (workOrderId: number, data: { signer: 'technician' | 'customer'; signature: string }) =>
    api.post<WorksheetResponse>(`/field-service/work-orders/${workOrderId}/worksheet/sign`, data),

  getWorksheetPdfUrl: (workOrderId: number) =>
    `/api/v1/field-service/work-orders/${workOrderId}/worksheet/pdf`,
}

// ─── Supplementary Types ─────────────────────────────────────────────────────

export interface WorksheetField {
  id: string
  label: string
  type: 'text' | 'number' | 'checkbox' | 'dropdown' | 'date' | 'photo' | 'signature'
  required?: boolean
  options?: string[]
  placeholder?: string
  sort_order?: number
}

export interface WorksheetTemplate {
  id: number
  name: string
  description?: string | null
  is_default: boolean
  fields: WorksheetField[]
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface WorksheetResponse {
  id: number
  work_order_id: number
  template_id: number
  responses: Record<string, unknown>
  technician_signature?: string | null
  customer_signature?: string | null
  completed_by?: number | null
  completed_at?: string | null
  pdf_path?: string | null
  template?: WorksheetTemplate | null
}

export interface JobsAnalysisSummary {
  total: number
  completed: number
  invoiced: number
  revenue: number
  by_type: Record<string, number>
  by_status: Record<string, number>
}

export default fieldServiceApi
