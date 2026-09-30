import { adminApi } from './client'

export interface TenantUserCreationRequest {
  id: number
  tenant_id: string
  tenant_name: string | null
  requested_by: number | null
  requested_by_name: string | null
  name: string | null
  email: string
  role: string
  note: string | null
  status: 'pending' | 'approved' | 'declined'
  review_note: string | null
  reviewed_at: string | null
  created_at: string
}

export async function getUserRequests(status: string = 'pending'): Promise<TenantUserCreationRequest[]> {
  const res = await adminApi.get('/user-requests', { params: { status } })
  return res.data?.requests ?? []
}

export async function approveUserRequest(id: number): Promise<{ message: string }> {
  const res = await adminApi.post(`/user-requests/${id}/approve`)
  return res.data
}

export async function declineUserRequest(id: number, reviewNote?: string): Promise<{ message: string }> {
  const res = await adminApi.post(`/user-requests/${id}/decline`, {
    review_note: reviewNote || undefined,
  })
  return res.data
}
