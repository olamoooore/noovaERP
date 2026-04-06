import { adminApi } from './client'
import type { ApiResponse, PlanFamily, PlanVersion } from '@/types/admin'

export async function getPlanFamilies(): Promise<PlanFamily[]> {
  const { data } = await adminApi.get<ApiResponse<PlanFamily[]>>('/plans')
  return data.data
}

export async function getPlanFamily(id: number): Promise<PlanFamily> {
  const { data } = await adminApi.get<ApiResponse<PlanFamily>>(`/plans/${id}`)
  return data.data
}

export async function createPlanFamily(
  payload: Pick<PlanFamily, 'key' | 'name'> & Partial<Pick<PlanFamily, 'description' | 'is_active' | 'sort_order'>>,
): Promise<PlanFamily> {
  const { data } = await adminApi.post<ApiResponse<PlanFamily>>('/plans', payload)
  return data.data
}

export async function updatePlanFamily(
  id: number,
  payload: Partial<Pick<PlanFamily, 'name' | 'description' | 'is_active' | 'sort_order'>>,
): Promise<PlanFamily> {
  const { data } = await adminApi.put<ApiResponse<PlanFamily>>(`/plans/${id}`, payload)
  return data.data
}

export async function createPlanVersion(
  familyId: number,
  payload: { version_label: string; description?: string; is_active?: boolean; is_publicly_listed?: boolean },
): Promise<PlanVersion> {
  const { data } = await adminApi.post<ApiResponse<PlanVersion>>(`/plans/${familyId}/versions`, payload)
  return data.data
}

export async function updatePlanVersion(
  familyId: number,
  versionId: number,
  payload: Partial<{ version_label: string; description: string; is_active: boolean; is_publicly_listed: boolean }>,
): Promise<PlanVersion> {
  const { data } = await adminApi.put<ApiResponse<PlanVersion>>(`/plans/${familyId}/versions/${versionId}`, payload)
  return data.data
}

export async function setPlanVersionPrices(
  familyId: number,
  versionId: number,
  prices: Array<{ billing_interval: 'monthly' | 'yearly'; unit_amount: number; currency?: string }>,
): Promise<PlanVersion> {
  const { data } = await adminApi.post<ApiResponse<PlanVersion>>(
    `/plans/${familyId}/versions/${versionId}/prices`,
    { prices },
  )
  return data.data
}

export async function attachModuleToVersion(familyId: number, versionId: number, moduleId: number): Promise<void> {
  await adminApi.post(`/plans/${familyId}/versions/${versionId}/modules/${moduleId}`)
}

export async function detachModuleFromVersion(familyId: number, versionId: number, moduleId: number): Promise<void> {
  await adminApi.delete(`/plans/${familyId}/versions/${versionId}/modules/${moduleId}`)
}
