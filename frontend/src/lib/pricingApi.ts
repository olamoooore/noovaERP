import api from './api';

/**
 * MRD pricelist-multicurrency-mrd FR-11 — typed client for price lists and
 * the deterministic pricing engine. Server remains the only price authority
 * (FR-4.1): these calls are for UI display + intent (price_mode), never the
 * final word on a saved document.
 */

export interface PriceListItemPayload {
  id?: number;
  sequence?: number;
  apply_on: 'product' | 'category' | 'all';
  product_id?: number | null;
  product_name?: string | null;
  category_id?: number | null;
  category_name?: string | null;
  min_quantity?: number;
  max_quantity?: number | null;
  date_start?: string | null;
  date_end?: string | null;
  compute_type: 'fixed' | 'percentage' | 'formula' | 'restricted';
  fixed_price?: number | null;
  percent?: number | null;
  base?: 'list_price' | 'cost' | 'other_pricelist' | null;
  other_price_list_id?: number | null;
  markup_percent?: number | null;
}

export interface PriceListApplicationPayload {
  id?: number;
  applies_to_type: 'all' | 'customer_group' | 'customer';
  applies_to_id?: number | null;
}

export interface PriceList {
  id: number;
  name: string;
  code: string | null;
  currency_code: string;
  description?: string | null;
  sequence: number;
  valid_from?: string | null;
  valid_until?: string | null;
  is_active: boolean;
  rounding_enabled: boolean;
  rounding_method: 'up' | 'down' | 'nearest';
  rounding_precision: number;
  discount_display: 'show_discount' | 'show_discounted_price';
  items_count?: number;
  items?: PriceListItemPayload[];
  applications?: PriceListApplicationPayload[];
}

export interface PricingQuote {
  product_id: number | null;
  unit_price: number;
  base_unit_price: number;
  currency: string;
  base_currency: string;
  exchange_rate: number | null;
  rate_date: string | null;
  source: 'pricelist' | 'catalog' | 'catalog_in_list' | 'manual' | string;
  list_price: number;
  pricing_trace: Record<string, any>;
}

export interface DocumentPricingResult {
  currency_code: string;
  base_currency_code: string;
  exchange_rate: number;
  rate_date: string | null;
  rate_source: string | null;
  price_list_id: number | null;
  price_list_name: string | null;
  price_list_currency: string | null;
  lines: PricingQuote[];
}

export interface ResolveLineInput {
  product_id: number;
  variant_id?: number | null;
  quantity: number;
}

export interface ResolveContext {
  customer_id?: number | null;
  date?: string | null;
  document_currency?: string | null;
  exchange_rate?: number | null;
  base_currency_code?: string | null;
  price_list_id?: number | null;
}

const pricingApi = {
  listPriceLists: async (params?: { active_only?: boolean; currency_code?: string; search?: string; per_page?: number }) => {
    const response = await api.get('/price-lists', { params });
    return response.data;
  },

  getPriceList: async (id: number) => {
    const response = await api.get(`/price-lists/${id}`);
    return response.data?.data ?? response.data;
  },

  createPriceList: async (payload: Partial<PriceList> & { items?: PriceListItemPayload[]; applications?: PriceListApplicationPayload[] }) => {
    const response = await api.post('/price-lists', payload);
    return response.data;
  },

  updatePriceList: async (id: number, payload: Partial<PriceList> & { items?: PriceListItemPayload[]; applications?: PriceListApplicationPayload[] }) => {
    const response = await api.put(`/price-lists/${id}`, payload);
    return response.data;
  },

  clonePriceList: async (id: number) => {
    const response = await api.post(`/price-lists/${id}/clone`);
    return response.data;
  },

  deactivatePriceList: async (id: number) => {
    const response = await api.post(`/price-lists/${id}/deactivate`);
    return response.data;
  },

  deletePriceList: async (id: number) => {
    const response = await api.delete(`/price-lists/${id}`);
    return response.data;
  },

  /**
   * FR-4.6 batch resolve — prices a whole document's lines in one call and
   * returns the resolved header currency + applied list + per-line quotes.
   */
  resolveBatch: async (context: ResolveContext, lines: ResolveLineInput[]): Promise<DocumentPricingResult> => {
    const response = await api.post('/pricing/resolve-batch', {
      ...context,
      lines,
    });
    return response.data?.data ?? response.data;
  },

  /** Single-line quote (FR-4.1) — used on product pick in the editors. */
  resolveLine: async (payload: ResolveLineInput & ResolveContext): Promise<PricingQuote> => {
    const response = await api.post('/pricing/resolve', payload);
    return response.data?.data ?? response.data;
  },

  /** Sellable currencies for document headers (FR-1.1). */
  sellableCurrencies: async (): Promise<Array<{ code: string; symbol?: string; name?: string }>> => {
    const response = await api.get('/accounting/currencies', { params: { sellable: 1 } });
    return response.data?.data ?? response.data;
  },
};

export default pricingApi;