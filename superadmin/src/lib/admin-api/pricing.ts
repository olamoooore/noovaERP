import { adminApi as api } from './client'
import type {
  CatalogPrice,
  CatalogPriceRule,
  TenantQuoteSnapshot,
  BillingInterval,
  PriceRuleType,
} from '@/types/admin'

// ── Prices ────────────────────────────────────────────────────────────────────

export async function listPrices(catalogItemId?: number): Promise<CatalogPrice[]> {
  const params = catalogItemId ? { catalog_item_id: catalogItemId } : {}
  const { data } = await api.get('/pricing/prices', { params })
  return data.data
}

export async function upsertPrice(payload: {
  catalog_item_id: number
  billing_interval: BillingInterval
  currency: string
  unit_amount: number
}): Promise<CatalogPrice> {
  const { data } = await api.put('/pricing/prices', payload)
  return data.data
}

export async function deletePrice(priceId: number): Promise<void> {
  await api.delete(`/pricing/prices/${priceId}`)
}

// ── Rules ─────────────────────────────────────────────────────────────────────

export async function listRules(catalogItemId?: number): Promise<CatalogPriceRule[]> {
  const params = catalogItemId ? { catalog_item_id: catalogItemId } : {}
  const { data } = await api.get('/pricing/rules', { params })
  return data.data
}

export async function createRule(payload: {
  catalog_item_id?: number | null
  name: string
  rule_type: PriceRuleType
  condition?: Record<string, unknown>
  modifier_value: number
  priority?: number
  is_exclusive?: boolean
  is_active?: boolean
  effective_from?: string
  effective_to?: string | null
}): Promise<CatalogPriceRule> {
  const { data } = await api.post('/pricing/rules', payload)
  return data.data
}

export async function updateRule(
  ruleId: number,
  payload: Partial<{
    name: string
    rule_type: PriceRuleType
    condition: Record<string, unknown>
    modifier_value: number
    priority: number
    is_exclusive: boolean
    is_active: boolean
    effective_from: string
    effective_to: string | null
  }>,
): Promise<CatalogPriceRule> {
  const { data } = await api.put(`/pricing/rules/${ruleId}`, payload)
  return data.data
}

export async function deleteRule(ruleId: number): Promise<void> {
  await api.delete(`/pricing/rules/${ruleId}`)
}

// ── Quotes ────────────────────────────────────────────────────────────────────

export async function generateQuote(
  tenantId: string,
  payload: {
    billing_interval?: BillingInterval
    currency?: string
    plan_family_id?: number | null
  } = {},
): Promise<TenantQuoteSnapshot> {
  const { data } = await api.post(`/pricing/quotes/${tenantId}`, payload)
  return data.data
}

export async function getLatestQuote(tenantId: string): Promise<TenantQuoteSnapshot | null> {
  const { data } = await api.get(`/pricing/quotes/${tenantId}/latest`)
  return data.data
}

export async function getQuoteHistory(
  tenantId: string,
  perPage = 10,
): Promise<{ data: TenantQuoteSnapshot[]; meta: { total: number; per_page: number; last_page: number } }> {
  const { data } = await api.get(`/pricing/quotes/${tenantId}`, { params: { per_page: perPage } })
  return data
}
