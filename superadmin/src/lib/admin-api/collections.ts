import { adminApi } from './client'

export interface CollectionInvoice {
  id: number
  tenant_id: string
  invoice_number: string
  status: string
  total: number
  due_date: string | null
  paid_at: string | null
  collection_stage: string | null
  collection_stage_updated_at: string | null
  collection_notes: string | null
  subscription?: {
    id: number
    plan_version?: {
      version_label: string
      family?: { name: string }
    }
  }
}

export async function getAtRiskInvoices(): Promise<CollectionInvoice[]> {
  const res = await adminApi.get('/collections')
  return res.data.data
}

export async function advanceCollectionStage(invoiceId: number, note?: string): Promise<CollectionInvoice> {
  const res = await adminApi.post(`/collections/${invoiceId}/advance`, { note })
  return res.data.data
}

export async function clearCollectionStage(invoiceId: number, note?: string): Promise<CollectionInvoice> {
  const res = await adminApi.post(`/collections/${invoiceId}/clear`, { note })
  return res.data.data
}
