import api from './api';

export interface LockDate {
  id: number;
  lock_type: 'all' | 'sales' | 'purchases' | 'inventory' | 'payroll' | 'journal_entries' | 'assets';
  lock_date: string;
  set_by: number;
  set_at: string;
  reason?: string;
  is_active: boolean;
}

export interface CreateLockDateRequest {
  lock_type: 'all' | 'sales' | 'purchases' | 'inventory' | 'payroll' | 'journal_entries' | 'assets';
  lock_date: string;
  reason?: string;
}

export interface LockDateOverride {
  id: number;
  user_id: number;
  transaction_type: string;
  transaction_id: number;
  transaction_date: string;
  override_reason: string;
  approved_by?: number;
  approved_at?: string;
  created_at: string;
}

const lockDatesApi = {
  getLockDates: async (params?: { lock_type?: string; is_active?: boolean }) => {
    const response = await api.get('/accounting/lock-dates', { params });
    return response.data;
  },

  getCurrentLockDates: async () => {
    const response = await api.get('/accounting/lock-dates/current');
    return response.data;
  },

  getLockDate: async (id: number) => {
    const response = await api.get(`/accounting/lock-dates/${id}`);
    return response.data;
  },

  createLockDate: async (data: CreateLockDateRequest) => {
    const response = await api.post('/accounting/lock-dates', data);
    return response.data.lock_date;
  },

  updateLockDate: async (id: number, data: Partial<CreateLockDateRequest>) => {
    const response = await api.put(`/accounting/lock-dates/${id}`, data);
    return response.data.lock_date;
  },

  deleteLockDate: async (id: number) => {
    const response = await api.delete(`/accounting/lock-dates/${id}`);
    return response.data;
  },

  validateDate: async (transaction_type: string, transaction_date: string) => {
    const response = await api.post('/accounting/lock-dates/validate', {
      transaction_type,
      transaction_date,
    });
    return response.data;
  },

  getOverrides: async (params?: { user_id?: number; transaction_type?: string }) => {
    const response = await api.get('/accounting/lock-dates/overrides', { params });
    return response.data.data || response.data;
  },

  requestOverride: async (data: {
    transaction_type: string;
    transaction_id: number;
    transaction_date: string;
    override_reason: string;
  }) => {
    const response = await api.post('/accounting/lock-dates/request-override', data);
    return response.data.override;
  },

  getStatistics: async () => {
    const response = await api.get('/accounting/lock-dates/statistics');
    return response.data;
  },
};

export default lockDatesApi;
