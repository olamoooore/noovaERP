import { adminApi } from './client'

export interface MrrData {
  mrr_cents: number
  arr_cents: number
  subscriber_count: number
}

export interface RevenueData30d {
  revenue_cents: number
  invoice_count: number
}

export interface ArpuData {
  arpu_cents: number
  subscriber_count: number
}

export interface GrowthData {
  this_month_cents: number
  last_month_cents: number
  delta_cents: number
  growth_percent: number | null
}

export interface ChurnData {
  canceled_count: number
  active_base: number
  churn_rate_percent: number
}

export interface SubscriptionStats {
  active: number
  trialing: number
  past_due: number
  canceled: number
  paying_ratio: number
}

export interface FullRevenueData {
  mrr: MrrData
  revenue_last_30d: RevenueData30d
  arpu: ArpuData
  growth: GrowthData
  churn: ChurnData
  subscription_stats: SubscriptionStats
}

export interface PlanRevenue {
  plan_name: string
  mrr_cents: number
  subscriber_count: number
}

export async function getRevenueAnalytics(): Promise<FullRevenueData> {
  const res = await adminApi.get('/analytics/revenue')
  return res.data.data
}

export async function getPlanRevenue(): Promise<PlanRevenue[]> {
  const res = await adminApi.get('/analytics/plans')
  return res.data.data
}

export interface BatchStatus {
  status: 'running' | 'done' | 'failed' | 'queued'
  total?: number
  processed?: number
  success_count?: number
  failure_count?: number
  started_at?: string
  completed_at?: string | null
  results?: Array<{
    tenant_id: string
    db_connected?: boolean
    storage_mb?: number
    status: string
    error?: string
  }>
  error?: string
}

export async function startBulkHealthCheck(tenantIds?: string[]): Promise<{
  batch_id: string
  total: number
  status: string
}> {
  const res = await adminApi.post('/operations/bulk-health', { tenant_ids: tenantIds })
  return res.data.data
}

export async function getBatchStatus(batchId: string): Promise<BatchStatus> {
  const res = await adminApi.get(`/operations/batch/${batchId}`)
  return res.data.data
}
