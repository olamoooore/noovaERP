import {
  AdminNote,
  PaginatedResponse,
  Tenant,
  TenantDetail,
  TenantDomain,
  TenantHealthSnapshot,
  TenantOperation,
  TenantStatus,
} from '@/types/admin'
import { adminApi } from './client'

export async function getTenants(params: {
  search?: string
  status?: TenantStatus
  plan?: string
  page?: number
  per_page?: number
}): Promise<PaginatedResponse<Tenant>> {
  const res = await adminApi.get('/tenants', { params })
  return res.data
}

export async function getTenantDetail(tenantId: string): Promise<TenantDetail> {
  const res = await adminApi.get(`/tenants/${tenantId}`)
  return res.data.data
}

export async function updateTenantStatus(
  tenantId: string,
  status: 'suspended' | 'active',
  reason?: string,
): Promise<TenantDetail> {
  const res = await adminApi.patch(`/tenants/${tenantId}/status`, { status, reason })
  return res.data.data
}

export async function getTenantOperations(
  tenantId: string,
  page = 1,
): Promise<PaginatedResponse<TenantOperation>> {
  const res = await adminApi.get(`/tenants/${tenantId}/operations`, { params: { page } })
  return res.data
}

export async function runTenantOperation(
  tenantId: string,
  operation: string,
  payload?: Record<string, unknown>,
  reason?: string,
): Promise<TenantOperation> {
  const res = await adminApi.post(`/tenants/${tenantId}/operations`, { operation, payload, reason })
  return res.data.data
}

export async function getTenantNotes(
  tenantId: string,
  page = 1,
): Promise<PaginatedResponse<AdminNote>> {
  const res = await adminApi.get(`/tenants/${tenantId}/notes`, { params: { page } })
  return res.data
}

export async function createTenantNote(tenantId: string, body: string): Promise<AdminNote> {
  const res = await adminApi.post(`/tenants/${tenantId}/notes`, { body })
  return res.data.data
}

export async function deleteTenantNote(tenantId: string, noteId: number): Promise<void> {
  await adminApi.delete(`/tenants/${tenantId}/notes/${noteId}`)
}

export async function getTenantDomains(tenantId: string): Promise<TenantDomain[]> {
  const res = await adminApi.get(`/tenants/${tenantId}/domains`)
  return res.data.data
}

export async function addTenantDomain(
  tenantId: string,
  domain: string,
  type: 'platform' | 'custom',
): Promise<TenantDomain> {
  const res = await adminApi.post(`/tenants/${tenantId}/domains`, { domain, type })
  return res.data.data
}

export async function deleteTenantDomain(tenantId: string, domainId: number): Promise<void> {
  await adminApi.delete(`/tenants/${tenantId}/domains/${domainId}`)
}

export async function verifyTenantDomain(tenantId: string, domainId: number): Promise<TenantDomain> {
  const res = await adminApi.post(`/tenants/${tenantId}/domains/${domainId}/verify`)
  return res.data.data
}

export async function checkTenantDomain(tenantId: string, domainId: number): Promise<TenantDomain> {
  const res = await adminApi.post(`/tenants/${tenantId}/domains/${domainId}/check`)
  return res.data.data
}

export async function getTenantHealth(tenantId: string): Promise<TenantHealthSnapshot | null> {
  const res = await adminApi.get(`/tenants/${tenantId}/health`)
  return res.data.data
}

export async function pingTenantHealth(tenantId: string): Promise<TenantHealthSnapshot> {
  const res = await adminApi.post(`/tenants/${tenantId}/health/ping`)
  return res.data.data
}
