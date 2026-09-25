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

export interface ExchangeRate {
  id: number;
  from_currency: string;
  to_currency: string;
  rate: number;
  effective_date: string;
  source?: string;
}

export interface CreateExchangeRateRequest {
  from_currency: string;
  to_currency: string;
  rate: number;
  effective_date: string;
  source?: string;
}

export interface ConvertCurrencyRequest {
  amount: number;
  from_currency: string;
  to_currency: string;
  as_of_date?: string;
}

export interface ConversionResult {
  original_amount: number;
  original_currency: string;
  converted_amount: number;
  converted_currency: string;
  exchange_rate: number;
  effective_date: string;
}

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

  getExchangeRates: async (params?: {
    from_currency?: string;
    to_currency?: string;
    per_page?: number;
  }) => {
    const response = await api.get('/accounting/exchange-rates', { params });
    return response.data;
  },

  createExchangeRate: async (data: CreateExchangeRateRequest) => {
    const response = await api.post('/accounting/exchange-rates', data);
    return response.data.rate;
  },

  updateExchangeRate: async (id: number, data: Partial<CreateExchangeRateRequest>) => {
    const response = await api.put(`/accounting/exchange-rates/${id}`, data);
    return response.data.rate;
  },

  deleteExchangeRate: async (id: number) => {
    const response = await api.delete(`/accounting/exchange-rates/${id}`);
    return response.data;
  },

  syncExchangeRates: async (data: { base_currency: string; target_currencies: string[] }) => {
    const response = await api.post('/accounting/exchange-rates/sync', data);
    return response.data;
  },

  convertCurrency: async (data: ConvertCurrencyRequest): Promise<ConversionResult> => {
    const response = await api.post('/accounting/exchange-rates/convert', data);
    return response.data;
  },
};

export default currenciesApi;
