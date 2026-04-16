import { adminApi as api } from './client'
import type {
  CatalogItem,
  CatalogItemType,
  CatalogDependency,
  CatalogIncompatibility,
  CatalogVisibilityRule,
  CatalogSyncResult,
} from '@/types/admin'

// =============================================================================
// Item Types
// =============================================================================

export async function getCatalogTypes(): Promise<CatalogItemType[]> {
  const res = await api.get<{ data: CatalogItemType[] }>('/catalog/types')
  return res.data.data
}

export async function createCatalogType(data: {
  key: string
  name: string
  description?: string
  sort_order?: number
}): Promise<CatalogItemType> {
  const res = await api.post<{ data: CatalogItemType }>('/catalog/types', data)
  return res.data.data
}

// =============================================================================
// Catalog Items
// =============================================================================

/** Full tree: top-level modules → submodules → features */
export async function getCatalogTree(): Promise<CatalogItem[]> {
  const res = await api.get<{ data: CatalogItem[] }>('/catalog')
  return res.data.data
}

/** Flat list — for dropdowns and parent selectors */
export async function getCatalogFlat(): Promise<CatalogItem[]> {
  const res = await api.get<{ data: CatalogItem[] }>('/catalog/flat')
  return res.data.data
}

export async function getCatalogItem(id: number): Promise<CatalogItem> {
  const res = await api.get<{ data: CatalogItem }>(`/catalog/${id}`)
  return res.data.data
}

export async function createCatalogItem(data: {
  key: string
  name: string
  description?: string
  type_id: number
  parent_id?: number | null
  is_active?: boolean
  sort_order?: number
  metadata?: Record<string, unknown>
  effective_from?: string
  effective_to?: string | null
}): Promise<CatalogItem> {
  const res = await api.post<{ data: CatalogItem }>('/catalog', data)
  return res.data.data
}

export async function updateCatalogItem(
  id: number,
  data: Partial<{
    name: string
    description: string | null
    type_id: number
    parent_id: number | null
    is_active: boolean
    sort_order: number
    metadata: Record<string, unknown> | null
    effective_from: string
    effective_to: string | null
  }>,
): Promise<CatalogItem> {
  const res = await api.put<{ data: CatalogItem }>(`/catalog/${id}`, data)
  return res.data.data
}

/** Soft deactivation — sets effective_to + is_active = false. Never hard-deletes. */
export async function deactivateCatalogItem(id: number): Promise<CatalogItem> {
  const res = await api.delete<{ data: CatalogItem }>(`/catalog/${id}`)
  return res.data.data
}

// =============================================================================
// Dependencies
// =============================================================================

export async function addCatalogDependency(
  itemId: number,
  data: { depends_on_item_id: number; type?: 'required' | 'optional' },
): Promise<CatalogDependency> {
  const res = await api.post<{ data: CatalogDependency }>(`/catalog/${itemId}/dependencies`, data)
  return res.data.data
}

export async function removeCatalogDependency(dependencyId: number): Promise<void> {
  await api.delete(`/catalog/dependencies/${dependencyId}`)
}

// =============================================================================
// Incompatibilities
// =============================================================================

export async function addCatalogIncompatibility(
  itemId: number,
  data: { incompatible_item_id: number; reason?: string },
): Promise<CatalogIncompatibility> {
  const res = await api.post<{ data: CatalogIncompatibility }>(`/catalog/${itemId}/incompatibilities`, data)
  return res.data.data
}

export async function removeCatalogIncompatibility(incompatibilityId: number): Promise<void> {
  await api.delete(`/catalog/incompatibilities/${incompatibilityId}`)
}

// =============================================================================
// Visibility Rules
// =============================================================================

export async function addCatalogVisibilityRule(
  itemId: number,
  data: { rule_type: string; condition: Record<string, unknown> },
): Promise<CatalogVisibilityRule> {
  const res = await api.post<{ data: CatalogVisibilityRule }>(`/catalog/${itemId}/visibility-rules`, data)
  return res.data.data
}

export async function removeCatalogVisibilityRule(ruleId: number): Promise<void> {
  await api.delete(`/catalog/visibility-rules/${ruleId}`)
}

// =============================================================================
// Legacy Sync
// =============================================================================

export async function syncCatalogFromLegacy(): Promise<CatalogSyncResult> {
  const res = await api.post<{ data: CatalogSyncResult }>('/catalog/sync')
  return res.data.data
}
