import { adminApi } from './client'
import type { ApiResponse, ModuleCatalogEntry, SyncResult } from '@/types/admin'

export async function getModules(): Promise<ModuleCatalogEntry[]> {
  const { data } = await adminApi.get<ApiResponse<ModuleCatalogEntry[]>>('/modules')
  return data.data
}

export async function getModule(id: number): Promise<ModuleCatalogEntry> {
  const { data } = await adminApi.get<ApiResponse<ModuleCatalogEntry>>(`/modules/${id}`)
  return data.data
}

export async function syncModules(): Promise<SyncResult> {
  const { data } = await adminApi.post<{ data: SyncResult }>('/modules/sync')
  return data.data
}

export async function updateModule(
  id: number,
  payload: Partial<Pick<ModuleCatalogEntry, 'name' | 'description' | 'is_active' | 'sort_order'>>,
): Promise<ModuleCatalogEntry> {
  const { data } = await adminApi.put<ApiResponse<ModuleCatalogEntry>>(`/modules/${id}`, payload)
  return data.data
}
