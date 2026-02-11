import axios from 'axios';

const baseURL = import.meta.env.VITE_API_BASE_URL || '/api/v1';

// Public storefront API (no auth required)
export const storefrontApi = axios.create({
  baseURL,
  headers: {
    'Content-Type': 'application/json',
  },
});

// ---- Types matching backend response ----

export interface ApiProduct {
  id: number;
  name: string;
  secondary_name: string | null;
  slug: string;
  sku: string | null;
  description: string | null;
  price: number;
  image: string | null;
  gallery: string[];
  category: { id: number; name: string } | null;
  brand: { id: number; name: string } | null;
  tags: string[];
  is_new_arrival: boolean;
  is_preorder: boolean;
  in_stock: boolean;
  stock_quantity: number;
  unit: string | null;
}

export interface ApiCategory {
  id: number;
  name: string;
  slug: string;
  product_count: number;
}

export interface ApiTag {
  name: string;
  slug: string;
  product_count: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

// ---- API Functions ----

export async function fetchProducts(params?: {
  category_id?: number;
  brand_id?: number;
  min_price?: number;
  max_price?: number;
  search?: string;
  tag?: string;
  sort_by?: string;
  sort_dir?: string;
  per_page?: number;
  page?: number;
}): Promise<PaginatedResponse<ApiProduct>> {
  const { data } = await storefrontApi.get('/website-public/products', { params });
  return data;
}

export async function fetchProduct(id: number): Promise<ApiProduct> {
  const { data } = await storefrontApi.get(`/website-public/products/${id}`);
  return data;
}

export async function fetchCategories(): Promise<ApiCategory[]> {
  const { data } = await storefrontApi.get('/website-public/categories');
  return data;
}

export async function fetchTags(): Promise<ApiTag[]> {
  const { data } = await storefrontApi.get('/website-public/tags');
  return data;
}

// ---- Checkout API ----

export interface CheckoutPayload {
  customer_name: string;
  customer_email: string;
  customer_phone?: string;
  shipping_address?: string;
  shipping_city?: string;
  shipping_state?: string;
  shipping_zip?: string;
  shipping_country?: string;
  payment_gateway: string;
  items: {
    product_id: number;
    quantity: number;
    price: number;
  }[];
  notes?: string;
  currency?: string;
}

export interface CheckoutResponse {
  success: boolean;
  order_id: number;
  order_number: string;
  payment_url?: string;
  client_secret?: string;
  message?: string;
}

export async function guestCheckout(payload: CheckoutPayload): Promise<CheckoutResponse> {
  const { data } = await storefrontApi.post('/web-shop/checkout/guest', payload);
  return data;
}

export async function getPaymentGateways(): Promise<{ id: number; name: string; gateway: string; is_active: boolean }[]> {
  const { data } = await storefrontApi.get('/web-shop/payment-gateways');
  return data;
}

export async function confirmPayment(payload: { order_id: number; payment_reference: string; gateway: string }): Promise<{ success: boolean; message: string }> {
  const { data } = await storefrontApi.post('/web-shop/checkout/confirm-payment', payload);
  return data;
}
