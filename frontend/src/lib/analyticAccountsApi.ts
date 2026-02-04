import api from './api';

export interface AnalyticAccount {
  id: number;
  code: string;
  name: string;
  type: 'department' | 'project' | 'location' | 'product_line';
  parent_id?: number;
  manager_id?: number;
  budget_amount?: number;
  description?: string;
  is_active: boolean;
  parent?: AnalyticAccount;
  children?: AnalyticAccount[];
}

export interface CreateAnalyticAccountRequest {
  code: string;
  name: string;
  type: 'department' | 'project' | 'location' | 'product_line';
  parent_id?: number;
  manager_id?: number;
  budget_amount?: number;
  description?: string;
}

const analyticAccountsApi = {
  getAccounts: async (params?: { type?: string; is_active?: boolean; search?: string }) => {
    const response = await api.get('/accounting/analytic-accounts', { params });
    return response.data;
  },

  getAccountTree: async (type?: string) => {
    const response = await api.get('/accounting/analytic-accounts/tree', {
      params: type ? { type } : undefined,
    });
    return response.data;
  },

  getAccount: async (id: number) => {
    const response = await api.get(`/accounting/analytic-accounts/${id}`);
    return response.data;
  },

  createAccount: async (data: CreateAnalyticAccountRequest) => {
    const response = await api.post('/accounting/analytic-accounts', data);
    return response.data.account;
  },

  updateAccount: async (id: number, data: Partial<CreateAnalyticAccountRequest>) => {
    const response = await api.put(`/accounting/analytic-accounts/${id}`, data);
    return response.data.account;
  },

  deleteAccount: async (id: number) => {
    const response = await api.delete(`/accounting/analytic-accounts/${id}`);
    return response.data;
  },

  getBalance: async (
    id: number,
    params: {
      dimension: 'department' | 'project' | 'location';
      start_date?: string;
      end_date?: string;
      include_children?: boolean;
    }
  ) => {
    const response = await api.get(`/accounting/analytic-accounts/${id}/balance`, { params });
    return response.data;
  },

  getProfitLoss: async (
    id: number,
    params: {
      dimension: 'department' | 'project' | 'location';
      start_date?: string;
      end_date?: string;
    }
  ) => {
    const response = await api.get(`/accounting/analytic-accounts/${id}/profit-loss`, { params });
    return response.data;
  },

  getReportByDimension: async (params: {
    dimension: 'department' | 'project' | 'location';
    start_date?: string;
    end_date?: string;
  }) => {
    const response = await api.get('/accounting/analytic-accounts/report-by-dimension', { params });
    return response.data;
  },
};

export default analyticAccountsApi;
