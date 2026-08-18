import api from './api';

export interface AccountingFinding {
  id: string;
  tenant_id: string;
  entity_id: string;
  finding_type: string;
  severity: 'low' | 'medium' | 'high' | 'critical';
  status: 'open' | 'acknowledged' | 'investigating' | 'resolved' | 'dismissed' | 'escalated';
  source_type?: string;
  source_id?: string;
  account_code?: string;
  reference_number?: string;
  title: string;
  description: string;
  recommendation?: string;
  amount?: number;
  currency?: string;
  confidence?: number;
  metadata?: Record<string, any>;
  assigned_to?: string;
  resolved_at?: string;
  resolution_notes?: string;
  created_at: string;
  updated_at: string;
}

export interface FindingSummary {
  total: number;
  by_status: Record<string, number>;
  by_severity: Record<string, number>;
}

const accountingFindingsApi = {
  getFindings: async (entityId: string, params?: {
    status?: string;
    severity?: string;
    type?: string;
    limit?: number;
  }): Promise<AccountingFinding[]> => {
    const response = await api.get(`/accounting/findings/${entityId}`, { params });
    return response.data.data;
  },

  getSummary: async (entityId: string): Promise<FindingSummary> => {
    const response = await api.get(`/accounting/findings/${entityId}/summary`);
    return response.data.data;
  },

  createFinding: async (data: {
    entity_id: string;
    finding_type: string;
    severity?: string;
    source_type?: string;
    source_id?: string;
    account_code?: string;
    reference_number?: string;
    title: string;
    description: string;
    recommendation?: string;
    amount?: number;
    currency?: string;
    confidence?: number;
    metadata?: Record<string, any>;
  }): Promise<AccountingFinding> => {
    const response = await api.post('/accounting/findings', data);
    return response.data.data;
  },

  getFinding: async (id: string): Promise<AccountingFinding> => {
    const response = await api.get(`/accounting/findings/detail/${id}`);
    return response.data.data;
  },

  acknowledge: async (id: string): Promise<AccountingFinding> => {
    const response = await api.post(`/accounting/findings/${id}/acknowledge`);
    return response.data.data;
  },

  investigate: async (id: string): Promise<AccountingFinding> => {
    const response = await api.post(`/accounting/findings/${id}/investigate`);
    return response.data.data;
  },

  resolve: async (id: string, notes?: string): Promise<AccountingFinding> => {
    const response = await api.post(`/accounting/findings/${id}/resolve`, { notes });
    return response.data.data;
  },

  dismiss: async (id: string, reason?: string): Promise<AccountingFinding> => {
    const response = await api.post(`/accounting/findings/${id}/dismiss`, { reason });
    return response.data.data;
  },

  escalate: async (id: string, reason?: string): Promise<AccountingFinding> => {
    const response = await api.post(`/accounting/findings/${id}/escalate`, { reason });
    return response.data.data;
  },
};

export default accountingFindingsApi;
