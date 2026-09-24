import api from '@/lib/api'

// ─── Types ───────────────────────────────────────────────────────────────────

export type ChatterEventType =
  | 'created'
  | 'updated'
  | 'status_changed'
  | 'posted'
  | 'paid'
  | 'cancelled'
  | 'reversed'
  | 'attachment_uploaded'
  | 'note'
  | 'message'

export type ActivityType =
  | 'follow_up'
  | 'call'
  | 'meeting'
  | 'todo'
  | 'reminder'

export interface ChatterActor {
  id: number
  name: string
  avatar?: string | null
  email?: string | null
}

export interface ChatterLogEntry {
  id: number | string
  event: ChatterEventType
  description: string
  properties?: Record<string, unknown> | null
  created_at: string
  actor?: ChatterActor | null
}

export interface ChatterActivity {
  id: number | string
  activity_type: ActivityType
  summary: string
  note?: string | null
  due_date?: string | null
  assigned_to?: ChatterActor | null
  status: 'planned' | 'done' | 'cancelled'
  created_at: string
  completed_at?: string | null
  actor?: ChatterActor | null
}

export interface ChatterAttachment {
  id: number | string
  filename: string
  mime_type: string
  size: number
  url: string
  uploaded_at: string
  uploaded_by?: ChatterActor | null
  /** Display name when the uploader was not a platform user (customer portal). */
  uploaded_by_name?: string | null
}

export interface ChatterMessageEntry {
  id: number | string
  body: string
  mentions?: number[]
  created_at: string
  actor?: ChatterActor | null
}

export interface ChatterBundle {
  logs: ChatterLogEntry[]
  messages: ChatterMessageEntry[]
  activities: ChatterActivity[]
  attachments: ChatterAttachment[]
}

export interface CreateActivityRequest {
  activity_type: ActivityType
  summary: string
  note?: string
  due_date?: string
  assigned_to_id?: number
}

export interface CreateMessageRequest {
  body: string
}

// ─── API Client ──────────────────────────────────────────────────────────────

class ChatterApiClient {
  async list(
    subjectType: string,
    subjectId: number | string
  ): Promise<ChatterBundle> {
    const response = await api.get<{ data: ChatterBundle }>(
      `/chatter/${encodeURIComponent(subjectType)}/${subjectId}`
    )
    return response.data.data
  }

  async addMessage(
    subjectType: string,
    subjectId: number | string,
    payload: CreateMessageRequest
  ): Promise<ChatterLogEntry> {
    const response = await api.post<{ data: ChatterLogEntry }>(
      `/chatter/${encodeURIComponent(subjectType)}/${subjectId}/messages`,
      payload
    )
    return response.data.data
  }

  async addActivity(
    subjectType: string,
    subjectId: number | string,
    payload: CreateActivityRequest
  ): Promise<ChatterActivity> {
    const response = await api.post<{ data: ChatterActivity }>(
      `/chatter/${encodeURIComponent(subjectType)}/${subjectId}/activities`,
      payload
    )
    return response.data.data
  }

  async markActivityDone(
    subjectType: string,
    subjectId: number | string,
    activityId: number | string
  ): Promise<ChatterActivity> {
    const response = await api.post<{ data: ChatterActivity }>(
      `/chatter/${encodeURIComponent(subjectType)}/${subjectId}/activities/${activityId}/done`
    )
    return response.data.data
  }

  async uploadAttachment(
    subjectType: string,
    subjectId: number | string,
    file: File
  ): Promise<ChatterAttachment> {
    const form = new FormData()
    form.append('file', file)
    const response = await api.post<{ data: ChatterAttachment }>(
      `/chatter/${encodeURIComponent(subjectType)}/${subjectId}/attachments`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } }
    )
    return response.data.data
  }

  async deleteAttachment(
    subjectType: string,
    subjectId: number | string,
    attachmentId: number | string
  ): Promise<void> {
    await api.delete(
      `/chatter/${encodeURIComponent(subjectType)}/${subjectId}/attachments/${attachmentId}`
    )
  }
}

const chatterApi = new ChatterApiClient()
export default chatterApi
