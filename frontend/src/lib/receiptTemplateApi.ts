import api from './api'
import type { ReceiptConfig } from '@/types/receipt-builder'

export interface ReceiptTemplate {
  id: number
  name: string
  description: string | null
  config: ReceiptConfig
  is_active: boolean
  created_at: string
  updated_at: string
}

export const receiptTemplateApi = {
  list: () =>
    api.get<{ success: boolean; data: ReceiptTemplate[] }>('/pos/receipt-templates'),

  get: (id: number) =>
    api.get<{ success: boolean; data: ReceiptTemplate }>(`/pos/receipt-templates/${id}`),

  create: (payload: { name: string; description?: string; config: ReceiptConfig; is_active?: boolean }) =>
    api.post<{ success: boolean; data: ReceiptTemplate; message: string }>('/pos/receipt-templates', payload),

  update: (id: number, payload: Partial<{ name: string; description: string; config: ReceiptConfig; is_active: boolean }>) =>
    api.put<{ success: boolean; data: ReceiptTemplate; message: string }>(`/pos/receipt-templates/${id}`, payload),

  destroy: (id: number) =>
    api.delete<{ success: boolean; message: string }>(`/pos/receipt-templates/${id}`),

  activate: (id: number) =>
    api.post<{ success: boolean; data: ReceiptTemplate; message: string }>(`/pos/receipt-templates/${id}/activate`),

  active: () =>
    api.get<{ success: boolean; data: ReceiptTemplate | null }>('/pos/receipt-templates/active'),
}
