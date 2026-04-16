import { adminApi as api } from './client'
import type {
  TierMatrix,
  PlanItemDefault,
  ItemAvailability,
  BulkSetResult,
} from '@/types/admin'

// =============================================================================
// Matrix
// =============================================================================

/** Full tier × item grid — tiers as columns, catalog items as rows */
export async function getTierMatrix(): Promise<TierMatrix> {
  const res = await api.get<{ data: TierMatrix }>('/packaging/matrix')
  return res.data.data
}

// =============================================================================
// Single Cell
// =============================================================================

/** Set (upsert) the availability for a single tier × catalog item cell */
export async function setMatrixCell(
  planFamilyId: number,
  catalogItemId: number,
  availability: ItemAvailability,
  notes?: string | null,
): Promise<PlanItemDefault> {
  const res = await api.put<{ data: PlanItemDefault }>(
    `/packaging/matrix/${planFamilyId}/${catalogItemId}`,
    { availability, notes: notes ?? null },
  )
  return res.data.data
}

/** Clear a cell — availability falls back to 'hidden' */
export async function clearMatrixCell(
  planFamilyId: number,
  catalogItemId: number,
): Promise<void> {
  await api.delete(`/packaging/matrix/${planFamilyId}/${catalogItemId}`)
}

// =============================================================================
// Tier-level
// =============================================================================

/** All configured items for a single tier */
export async function getTierDefaults(planFamilyId: number): Promise<PlanItemDefault[]> {
  const res = await api.get<{ data: PlanItemDefault[] }>(`/packaging/tiers/${planFamilyId}`)
  return res.data.data
}

/**
 * Bulk-set an entire tier row.
 * defaults: { [catalogItemId]: availability }
 */
export async function bulkSetTier(
  planFamilyId: number,
  defaults: Record<number, ItemAvailability>,
): Promise<BulkSetResult> {
  const res = await api.post<{ data: BulkSetResult }>(
    `/packaging/tiers/${planFamilyId}/bulk`,
    { defaults },
  )
  return res.data.data
}

// =============================================================================
// Item-level
// =============================================================================

/** All tier configurations for a single catalog item */
export async function getItemDefaults(catalogItemId: number): Promise<PlanItemDefault[]> {
  const res = await api.get<{ data: PlanItemDefault[] }>(`/packaging/items/${catalogItemId}`)
  return res.data.data
}
