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

/** Issue #634: one tracked field's old → new value on an edit. */
export interface ChatterFieldChange {
  field: string
  label: string
  old: string
  new: string
}

/** Issue #634: the tracked changes of one document line, grouped. */
export interface ChatterLineChange {
  line: number
  action: 'changed' | 'added' | 'removed'
  description?: string
  changes: ChatterFieldChange[]
  summary: string
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

export interface ChatterMention {
  id: number
  name?: string | null
  email?: string | null
}

/** Issue #636: which thread a message belongs to. */
export type ChatterMessageCategory = 'customer' | 'internal' | 'system'

export type ChatterCustomerChannel = 'email' | 'whatsapp'

export interface ChatterMessageEntry {
  id: number | string
  body: string
  /** Issue #635: resolved @mentions persisted with the message. */
  mentions?: ChatterMention[]
  created_at: string
  actor?: ChatterActor | null
  /** Issue #636: customer thread, internal note, or system message. */
  category?: ChatterMessageCategory
  is_internal_note?: boolean
  /** Issue #636: `outbound` = sent to the customer, `inbound` = their reply. */
  direction?: 'inbound' | 'outbound' | null
  channel?: string | null
  recipient?: string | null
  subject?: string | null
  status?: string | null
  /** Issue #636: the message this one replies to (inbound replies). */
  parent_id?: number | null
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
  /**
   * Issue #636: `customer` sends to the document's customer contact;
   * `note` is internal only and never reaches the customer.
   */
  mode?: 'customer' | 'note'
  /** Only for mode `customer`. */
  channel?: ChatterCustomerChannel
}

// ─── Issue #639: document followers ─────────────────────────────────────────

export interface ChatterFollower {
  id: number | string
  follower_type: 'internal' | 'partner'
  follow_source: string
  subscribe_messages: boolean
  subscribe_activities: boolean
  subscribe_stage_changes: boolean
  created_at?: string | null
  user?: ChatterActor | null
  contact?: { id: number; name?: string | null; email?: string | null } | null
}

export interface ChatterFollowerCandidate {
  id: number
  name?: string | null
  email?: string | null
}

export interface ChatterFollowersResult {
  followers: ChatterFollower[]
  /** Contacts of the document's customer that may be added as partners. */
  partnerContacts: ChatterFollowerCandidate[]
}

export interface FollowRequest {
  contact_id?: number
  subscribe_messages?: boolean
  subscribe_activities?: boolean
  subscribe_stage_changes?: boolean
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

  // ─── Issue #639: document followers ───────────────────────────────────────

  async listFollowers(
    subjectType: string,
    subjectId: number | string
  ): Promise<ChatterFollowersResult> {
    const response = await api.get<{
      data: ChatterFollower[]
      meta?: { partner_contacts?: ChatterFollowerCandidate[] }
    }>(`/chatter/${encodeURIComponent(subjectType)}/${subjectId}/followers`)

    return {
      followers: response.data.data ?? [],
      partnerContacts: response.data.meta?.partner_contacts ?? [],
    }
  }

  async follow(
    subjectType: string,
    subjectId: number | string,
    payload: FollowRequest = {}
  ): Promise<ChatterFollower> {
    const response = await api.post<{ data: ChatterFollower }>(
      `/chatter/${encodeURIComponent(subjectType)}/${subjectId}/followers`,
      payload
    )
    return response.data.data
  }

  async unfollow(
    subjectType: string,
    subjectId: number | string,
    followerId: number | string
  ): Promise<void> {
    await api.delete(
      `/chatter/${encodeURIComponent(subjectType)}/${subjectId}/followers/${followerId}`
    )
  }
}

const chatterApi = new ChatterApiClient()
export default chatterApi
