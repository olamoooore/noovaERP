import { adminApi as api } from './client'
import type {
  CatalogAnalyticsOverview,
  EntitlementHeatmapRow,
  AddonAdoptionRow,
  RevenueByItemRow,
  PlanDistributionRow,
  PlanChangeActivity,
  PricingRuleImpactRow,
} from '@/types/admin'

const BASE = '/analytics/catalog'

export async function getCatalogOverview(): Promise<CatalogAnalyticsOverview> {
  const { data } = await api.get(`${BASE}/overview`)
  return data.data
}

export async function getEntitlementHeatmap(): Promise<EntitlementHeatmapRow[]> {
  const { data } = await api.get(`${BASE}/entitlement-heatmap`)
  return data.data
}

export async function getAddonAdoption(): Promise<AddonAdoptionRow[]> {
  const { data } = await api.get(`${BASE}/addon-adoption`)
  return data.data
}

export async function getRevenueByItem(): Promise<RevenueByItemRow[]> {
  const { data } = await api.get(`${BASE}/revenue`)
  return data.data
}

export async function getPlanDistribution(): Promise<PlanDistributionRow[]> {
  const { data } = await api.get(`${BASE}/plan-distribution`)
  return data.data
}

export async function getPlanChangeActivity(): Promise<PlanChangeActivity> {
  const { data } = await api.get(`${BASE}/plan-changes`)
  return data.data
}

export async function getPricingRuleImpact(): Promise<PricingRuleImpactRow[]> {
  const { data } = await api.get(`${BASE}/pricing-rules`)
  return data.data
}
