import { adminApi as api } from './client'
import type { TenantApiKey } from '@/types/admin'

export async function listApiKeys(tenantId: string): Promise<TenantApiKey[]> {
  const { data } = await api.get(`/tenants/${tenantId}/api-keys`)
  return data.data
}

export async function createApiKey(
  tenantId: string,
  payload: { name: string; expires_at?: string | null },
): Promise<{ data: TenantApiKey; raw_key: string; warning: string }> {
  const { data } = await api.post(`/tenants/${tenantId}/api-keys`, payload)
  return data
}

export async function updateApiKey(
  tenantId: string,
  keyId: number,
  payload: { name?: string; is_active?: boolean },
): Promise<TenantApiKey> {
  const { data } = await api.patch(`/tenants/${tenantId}/api-keys/${keyId}`, payload)
  return data.data
}

export async function revokeApiKey(tenantId: string, keyId: number): Promise<void> {
  await api.delete(`/tenants/${tenantId}/api-keys/${keyId}`)
}
