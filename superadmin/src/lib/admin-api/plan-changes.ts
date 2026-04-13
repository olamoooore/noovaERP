import { adminApi as api } from './client'
import type { TenantPlanChange, ProrationPreview } from '@/types/admin'

export async function requestPlanChange(
  tenantId: string,
  payload: { to_family_id: number; effective_date: string; reason?: string },
): Promise<TenantPlanChange> {
  const { data } = await api.post(`/tenants/${tenantId}/plan-changes`, payload)
  return data.data
}

export async function getPlanChangeHistory(tenantId: string): Promise<TenantPlanChange[]> {
  const { data } = await api.get(`/tenants/${tenantId}/plan-changes`)
  return data.data
}

export async function previewProration(
  tenantId: string,
  payload: { to_family_id: number; effective_date: string },
): Promise<ProrationPreview> {
  const { data } = await api.post(`/tenants/${tenantId}/plan-changes/preview`, payload)
  return data.data
}

export async function getPendingPlanChanges(): Promise<TenantPlanChange[]> {
  const { data } = await api.get('/plan-changes')
  return data.data
}

export async function applyPlanChange(changeId: number): Promise<TenantPlanChange> {
  const { data } = await api.post(`/plan-changes/${changeId}/apply`)
  return data.data
}

export async function cancelPlanChange(changeId: number): Promise<TenantPlanChange> {
  const { data } = await api.post(`/plan-changes/${changeId}/cancel`)
  return data.data
}
