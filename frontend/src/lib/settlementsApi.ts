import api from './api';

export interface Settlement {
  id: number;
  tenant_id: string;
  settlement_state: 'UNRECOGNISED' | 'UNALLOCATED' | 'PARTIALLY_ALLOCATED' | 'FULLY_ALLOCATED' | 'DISPUTED' | 'REVIEW_REQUIRED' | 'REVERSED';
  source_record_type?: string;
  source_record_id?: number;
  total_amount: number;
  allocated_amount: number;
  unapplied_amount: number;
  currency_code: string;
  customer_id?: number;
  vendor_id?: number;
  description?: string;
  allocations?: Allocation[];
  created_at: string;
  updated_at: string;
}

export interface Allocation {
  id: number;
  settlement_id: number;
  source_record_type: string;
  source_record_id: number;
  allocated_amount: number;
  currency_code: string;
  created_at: string;
}

export interface UnappliedCash {
  id: number;
  tenant_id: string;
  customer_id?: number;
  vendor_id?: number;
  amount: number;
  currency_code: string;
  source_type?: string;
  source_id?: number;
  description?: string;
  created_at: string;
}

const settlementsApi = {
  getSettlements: async (params?: {
    state?: string;
    source_type?: string;
    page?: number;
    per_page?: number;
  }): Promise<{ data: Settlement[]; current_page: number; last_page: number; total: number }> => {
    const response = await api.get('/accounting/settlements', { params });
    return response.data.data;
  },

  getSettlement: async (id: number): Promise<Settlement> => {
    const response = await api.get(`/accounting/settlements/${id}`);
    return response.data.data;
  },

  allocate: async (data: {
    settlement_id: number;
    allocations: {
      source_record_type: string;
      source_record_id: number;
      amount: number;
    }[];
  }): Promise<{ settlement: Settlement; total_allocated: number; remaining_unapplied: number }> => {
    const response = await api.post('/accounting/settlements/allocate', data);
    return response.data;
  },

  reverse: async (id: number, reason?: string): Promise<{ settlement: Settlement; reversed_amount: number }> => {
    const response = await api.post(`/accounting/settlements/${id}/reverse`, { reason });
    return response.data;
  },

  getUnappliedCash: async (params?: {
    customer_id?: number;
    page?: number;
  }): Promise<{ data: UnappliedCash[]; current_page: number; last_page: number; total: number }> => {
    const response = await api.get('/accounting/settlements/unapplied-cash/list', { params });
    return response.data.data;
  },
};

export default settlementsApi;
