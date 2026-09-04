import api from './api';

export interface Currency {
  code: string;
  name: string;
  symbol?: string;
  decimal_places: number;
  is_active: boolean;
}

export interface CreateCurrencyRequest {
  code: string;
  name: string;
  symbol?: string;
  decimal_places?: number;
}

export type UpdateCurrencyRequest = Partial<CreateCurrencyRequest> & {
  is_active?: boolean;
};

const currenciesApi = {
  getCurrencies: async (params?: { is_active?: boolean; search?: string }) => {
    const response = await api.get('/accounting/currencies', { params });
    return response.data;
  },

  getActiveCurrencies: async () => {
    const response = await api.get('/accounting/currencies/active');
    return response.data;
  },

  getCurrency: async (code: string) => {
    const response = await api.get(`/accounting/currencies/${code}`);
    return response.data;
  },

  createCurrency: async (data: CreateCurrencyRequest) => {
    const response = await api.post('/accounting/currencies', data);
    return response.data.currency;
  },

  updateCurrency: async (code: string, data: UpdateCurrencyRequest) => {
    const response = await api.put(`/accounting/currencies/${code}`, data);
    return response.data.currency;
  },

  deleteCurrency: async (code: string) => {
    const response = await api.delete(`/accounting/currencies/${code}`);
    return response.data;
  },
};

export default currenciesApi;
