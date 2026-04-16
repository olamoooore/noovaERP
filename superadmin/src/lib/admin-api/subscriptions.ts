import { adminApi } from './client'
import type { PaginatedResponse, Subscription } from '@/types/admin'

export interface GetSubscriptionsParams {
  status?: string
  plan_version_id?: number
  search?: string
  page?: number
  per_page?: number
}

export function getSubscriptions(params: GetSubscriptionsParams = {}): Promise<PaginatedResponse<Subscription>> {
  return adminApi.get<PaginatedResponse<Subscription>>('/subscriptions', { params }).then(r => r.data)
}

export function getSubscription(id: number): Promise<{ data: Subscription }> {
  return adminApi.get<{ data: Subscription }>(`/subscriptions/${id}`).then(r => r.data)
}

export function getTenantSubscription(tenantId: string): Promise<{ data: Subscription | null }> {
  return adminApi.get<{ data: Subscription | null }>(`/tenants/${tenantId}/subscription`).then(r => r.data)
}

export function createSubscription(
  tenantId: string,
  payload: { plan_version_id: number; billing_interval: 'monthly' | 'yearly'; trial_ends_at?: string },
): Promise<{ data: Subscription }> {
  return adminApi.post<{ data: Subscription }>(`/tenants/${tenantId}/subscription`, payload).then(r => r.data)
}

export function cancelSubscription(
  id: number,
  reason?: string,
): Promise<{ data: Subscription }> {
  return adminApi.patch<{ data: Subscription }>(`/subscriptions/${id}/cancel`, { reason }).then(r => r.data)
}

export function reactivateSubscription(id: number): Promise<{ data: Subscription }> {
  return adminApi.patch<{ data: Subscription }>(`/subscriptions/${id}/reactivate`).then(r => r.data)
}
