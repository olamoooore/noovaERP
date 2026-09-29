import { adminApi } from './client'

export type ProvenanceVerdict =
  | 'synthetic_suspected'
  | 'dormant'
  | 'review'
  | 'active'
  | 'no_tax_activity'
  | 'not_migrated'

export interface ProvenanceMetrics {
  rows: number
  max_burst_per_minute: number
  creators: number
  span_days: number
  days_since_last_activity: number | null
  distinct_periods: number
}

export interface TenantProvenance {
  tenant_id: string
  name: string
  verdict: ProvenanceVerdict
  reasons: string[]
  metrics: ProvenanceMetrics | null
}

export interface TaxProvenanceReportData {
  generated_at: string
  tenants: TenantProvenance[]
  summary: {
    tenants: number
    synthetic_suspected: number
    dormant: number
    active: number
    no_trail: number
  }
}

export async function getTaxProvenanceReport(): Promise<TaxProvenanceReportData> {
  const res = await adminApi.get<{ data: TaxProvenanceReportData }>('/tax-provenance')
  return res.data.data
}
