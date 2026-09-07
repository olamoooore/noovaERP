import api from './api';

export interface FixedAsset {
  id: number;
  asset_number: string;
  name: string;
  category: string;
  purchase_date: string;
  purchase_cost: number;
  depreciation_method: 'straight_line' | 'declining_balance' | 'units_of_production';
  useful_life_years?: number;
  useful_life_units?: number;
  salvage_value?: number;
  depreciation_start_date: string;
  asset_account_id: number;
  depreciation_account_id: number;
  accumulated_depreciation_account_id: number;
  location?: string;
  department_id?: number;
  responsible_person?: string;
  serial_number?: string;
  status: 'active' | 'disposed' | 'inactive';
  accumulated_depreciation: number;
  book_value: number;
  is_active: boolean;
  description?: string;
}

export interface CreateAssetRequest {
  asset_number: string;
  name: string;
  category: string;
  purchase_date: string;
  purchase_cost: number;
  depreciation_method: 'straight_line' | 'declining_balance' | 'units_of_production';
  useful_life_years?: number;
  useful_life_units?: number;
  salvage_value?: number;
  depreciation_start_date: string;
  asset_account_id: number;
  depreciation_account_id: number;
  accumulated_depreciation_account_id: number;
  location?: string;
  department_id?: number;
  responsible_person?: string;
  serial_number?: string;
  description?: string;
}

export interface DisposeAssetRequest {
  disposal_date: string;
  disposal_amount: number;
  disposal_method: 'sold' | 'scrapped' | 'donated';
  notes?: string;
}

const fixedAssetsApi = {
  getAssets: async (params?: { status?: string; category?: string; search?: string }) => {
    const response = await api.get('/accounting/assets', { params });
    return response.data.data || response.data;
  },

  getAsset: async (id: number) => {
    const response = await api.get(`/accounting/assets/${id}`);
    return response.data;
  },

  createAsset: async (data: CreateAssetRequest) => {
    const response = await api.post('/accounting/assets', data);
    return response.data.asset;
  },

  importAssets: async (file: File) => {
    const formData = new FormData();
    formData.append('file', file);
    const response = await api.post('/accounting/assets/import', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data;
  },

  updateAsset: async (id: number, data: Partial<CreateAssetRequest>) => {
    const response = await api.put(`/accounting/assets/${id}`, data);
    return response.data.asset;
  },

  deleteAsset: async (id: number) => {
    const response = await api.delete(`/accounting/assets/${id}`);
    return response.data;
  },

  disposeAsset: async (id: number, data: DisposeAssetRequest) => {
    const response = await api.post(`/accounting/assets/${id}/dispose`, data);
    return response.data;
  },

  getDepreciationSchedule: async (id: number) => {
    const response = await api.get(`/accounting/assets/${id}/depreciation`);
    return response.data;
  },

  calculateDepreciation: async (data: { period_date: string; asset_ids?: number[] }) => {
    const response = await api.post('/accounting/assets/calculate-depreciation', data);
    return response.data;
  },
};

export default fixedAssetsApi;
