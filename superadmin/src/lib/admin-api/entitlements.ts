import { adminApi as api } from './client'
import type {
  TenantConfigurationView,
  TenantEntitlement,
  TenantSelectedItem,
  EntitlementRebuildJob,
  EntitlementCheckResult,
  SelectionType,
} from '@/types/admin'

// =============================================================================
// Configuration view
// =============================================================================

/** Full configuration view: tier + defaults + selections + entitlements + jobs */
export async function getTenantConfiguration(tenantId: string): Promise<TenantConfigurationView> {
  const res = await api.get<{ data: TenantConfigurationView }>(`/tenants/${tenantId}/configuration`)
  return res.data.data
}

// =============================================================================
// Selections
// =============================================================================

export async function addTenantSelection(
  tenantId: string,
  data: {
    catalog_item_id: number
    selection_type: SelectionType
    notes?: string | null
  },
): Promise<TenantSelectedItem> {
  const res = await api.post<{ data: TenantSelectedItem }>(
    `/tenants/${tenantId}/configuration/selections`,
    data,
  )
  return res.data.data
}

export async function removeTenantSelection(
  tenantId: string,
  catalogItemId: number,
): Promise<void> {
  await api.delete(`/tenants/${tenantId}/configuration/selections/${catalogItemId}`)
}

export async function resetTenantConfiguration(tenantId: string): Promise<void> {
  await api.post(`/tenants/${tenantId}/configuration/reset`)
}

// =============================================================================
// Entitlements
// =============================================================================

export async function getTenantEntitlements(tenantId: string): Promise<TenantEntitlement[]> {
  const res = await api.get<{ data: TenantEntitlement[] }>(`/tenants/${tenantId}/entitlements`)
  return res.data.data
}

export async function checkTenantEntitlement(
  tenantId: string,
  key: string,
): Promise<EntitlementCheckResult> {
  const res = await api.get<{ data: EntitlementCheckResult }>(
    `/tenants/${tenantId}/entitlements/check`,
    { params: { key } },
  )
  return res.data.data
}

// =============================================================================
// Rebuild jobs
// =============================================================================

export async function rebuildTenantEntitlements(
  tenantId: string,
  reason?: string,
): Promise<EntitlementRebuildJob> {
  const res = await api.post<{ data: EntitlementRebuildJob }>(
    `/tenants/${tenantId}/entitlements/rebuild`,
    { reason: reason ?? 'manual_rebuild' },
  )
  return res.data.data
}

export async function getTenantRebuildJobs(tenantId: string): Promise<EntitlementRebuildJob[]> {
  const res = await api.get<{ data: EntitlementRebuildJob[] }>(
    `/tenants/${tenantId}/entitlements/jobs`,
  )
  return res.data.data
}
