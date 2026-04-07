import { adminApi } from './client'
import type { SubscriptionInvoice } from '@/types/admin'

export interface GetInvoicesParams {
  status?: string
  tenant_id?: string
  from?: string
  to?: string
  search?: string
  page?: number
  per_page?: number
}

export type InvoiceListMeta = {
  total: number
  page: number
  per_page: number
  last_page: number
  open_total: number
  paid_this_month: number
}

export type InvoiceListResponse = {
  data: SubscriptionInvoice[]
  meta: InvoiceListMeta
}

export function getInvoices(params: GetInvoicesParams = {}): Promise<InvoiceListResponse> {
  return adminApi.get<InvoiceListResponse>('/billing/invoices', { params }).then(r => r.data)
}

export function getInvoice(id: number): Promise<{ data: SubscriptionInvoice }> {
  return adminApi.get<{ data: SubscriptionInvoice }>(`/billing/invoices/${id}`).then(r => r.data)
}

export function markInvoicePaid(id: number): Promise<{ data: SubscriptionInvoice }> {
  return adminApi.patch<{ data: SubscriptionInvoice }>(`/billing/invoices/${id}/mark-paid`).then(r => r.data)
}

export function voidInvoice(id: number, reason?: string): Promise<{ data: SubscriptionInvoice }> {
  return adminApi.patch<{ data: SubscriptionInvoice }>(`/billing/invoices/${id}/void`, { reason }).then(r => r.data)
}

export function generateInvoice(
  subscriptionId: number,
  billingInterval: 'monthly' | 'yearly',
): Promise<{ data: SubscriptionInvoice }> {
  return adminApi.post<{ data: SubscriptionInvoice }>('/billing/invoices/generate', {
    subscription_id: subscriptionId,
    billing_interval: billingInterval,
  }).then(r => r.data)
}
