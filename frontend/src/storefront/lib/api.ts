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
  short_description: string | null;
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
  manufacturer: string | null;
  is_merchandise: boolean;
  is_bundle: boolean;
  bundle_type: 'fixed' | 'dynamic' | 'customizable' | null;
  bundle_components: Array<{
    id: number;
    quantity: number;
    component_product: {
      id: number;
      name: string;
      price: number;
      image: string | null;
    } | null;
  }>;
}

export interface ApiLinkedEventProduct {
  id: number;
  name: string;
  price: number;
  is_bundle: boolean;
  bundle_type: 'fixed' | 'dynamic' | 'customizable' | null;
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
  event_id?: number;
  event_relation_type?: 'merchandise' | 'bundle';
  is_bundle?: boolean;
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

export interface ShippingRate {
  id: string;
  provider_code: string;
  provider_name: string;
  service_code: string;
  service_name: string;
  price: number;
  currency: string;
  transit_days: number | null;
  estimated_delivery: string | null;
}

export interface CheckoutPayload {
  email: string;
  phone?: string;
  shipping_address: {
    first_name: string;
    last_name: string;
    address: string;
    city: string;
    state?: string;
    postal_code: string;
    country: string;
  };
  shipping_method?: {
    id: string;
    provider_code: string;
    provider_name?: string;
    service_code: string;
    service_name?: string;
    price: number;
  };
  items: {
    product_id: number;
    quantity: number;
  }[];
  gateway_id?: number;
  notes?: string;
  loyalty_points_to_redeem?: number;
  queue_required?: boolean;
  campaign_key?: string;
  queue_token?: string;
}

// ---- Public Events API ----

export interface ApiEvent {
  id: number;
  title: string;
  description: string;
  start_date: string;
  end_date: string;
  event_date: string;
  venue: string;
  price: number;
  image_url: string;
  total_capacity: number;
  available_capacity: number;
  spots_left: number;
  total_spots: number;
  status: string;
  is_sold_out: boolean;
  ticket_product_id?: number | null;
  merchandise_products?: ApiLinkedEventProduct[];
  bundle_products?: ApiLinkedEventProduct[];
}

export interface PrepareEventCheckoutResponse {
  event_id: number;
  ticket_product_id: number;
  checkout_path: string;
  product: {
    id: number;
    name: string;
    price: number;
    image: string | null;
  };
}

export interface BookEventPayload {
  name: string;
  email: string;
  phone: string;
}

export interface BookEventResponse {
  booking_id: number;
  booking_reference: string;
  queue_number: string;
  event_title: string;
  email: string;
  payment_required?: boolean;
  gateway_name?: string | null;
  payment_intent?: {
    transaction_id?: string | null;
    payment_url?: string | null;
    client_secret?: string | null;
    publishable_key?: string | null;
  };
}

export async function fetchPublicEvents(): Promise<ApiEvent[]> {
  const { data } = await storefrontApi.get('/website-public/events');
  // API returns { data: [...], message: '' }
  return Array.isArray(data.data) ? data.data : Array.isArray(data) ? data : [];
}

export async function fetchPublicEvent(id: string | number): Promise<ApiEvent> {
  const { data } = await storefrontApi.get(`/website-public/events/${id}`);
  return data.data ?? data;
}

export async function preparePublicEventCheckout(id: string | number): Promise<PrepareEventCheckoutResponse> {
  const { data } = await storefrontApi.post(`/website-public/events/${id}/prepare-checkout`);
  return data.data ?? data;
}

export async function bookPublicEvent(eventId: string | number, payload: BookEventPayload): Promise<BookEventResponse> {
  const { data } = await storefrontApi.post(`/website-public/events/${eventId}/book`, payload);
  return data.data ?? data;
}

export async function confirmPublicEventPayment(payload: {
  booking_reference: string;
  payment_intent_id: string;
}): Promise<BookEventResponse> {
  const { data } = await storefrontApi.post('/website-public/events/bookings/confirm-payment', payload);
  return data.data ?? data;
}

export interface CheckoutResponse {
  success: boolean;
  order_id: number;
  order_number: string;
  total?: number;
  currency?: string;
  gateway_name?: string;
  payment_intent?: {
    client_secret?: string;
    publishable_key?: string;
    transaction_id?: string;
    payment_url?: string;
  };
  guest_token?: string;
  customer_id?: number;
  message?: string;
  error?: string;
}

export async function getShippingRates(params: {
  destination_country: string;
  destination_postal_code: string;
  weight: number;
  length?: number;
  width?: number;
  height?: number;
  items_count?: number;
}): Promise<{ success: boolean; rates: ShippingRate[]; has_rates: boolean }> {
  const { data } = await storefrontApi.get('/web-shop/shipping-rates', { params });
  return {
    success: data.success ?? true,
    rates: data.rates ?? [],
    has_rates: data.has_rates ?? false,
  };
}

export async function guestCheckout(payload: CheckoutPayload): Promise<CheckoutResponse> {
  const { data } = await storefrontApi.post('/web-shop/checkout/guest', payload);
  return data;
}

export async function getPaymentGateways(): Promise<{ id: number; name: string; gateway: string; display_name: string; is_test_mode: boolean }[]> {
  const { data } = await storefrontApi.get('/web-shop/payment-gateways');
  // Backend returns { gateways: [...], has_gateways: bool }
  const gateways = data.gateways || data || [];
  return gateways.map((gw: any) => ({
    id: gw.id,
    name: gw.display_name || gw.name,
    gateway: gw.name || gw.gateway, // backend uses `name` for the gateway type (stripe/hitpay/paypal)
    display_name: gw.display_name || gw.name,
    is_test_mode: gw.is_test_mode || false,
  }));
}

export async function confirmPayment(payload: {
  order_id: number;
  payment_intent_id: string;
  guest_token?: string;
}): Promise<{ success: boolean; message: string }> {
  const { data } = await storefrontApi.post('/web-shop/checkout/confirm-payment', payload);
  return data;
}

// ---- Customer Auth API ----

export interface CustomerLoginPayload {
  email: string;
  password: string;
}

export interface CustomerRegisterPayload {
  first_name: string;
  last_name: string;
  email: string;
  password: string;
  password_confirmation: string;
  phone?: string;
}

export interface CustomerProfile {
  id: number;
  name: string;
  first_name?: string;
  last_name?: string;
  email: string;
  phone: string | null;
  loyalty_points?: number;
  created_at: string;
}

export interface AuthResponse {
  success: boolean;
  message?: string;
  customer?: CustomerProfile;
  token?: string;
}

export interface CustomerOrder {
  id: number;
  order_number: string;
  status: string;
  payment_status: string;
  total: number;
  currency: string;
  created_at: string;
  items_count?: number;
  items?: OrderItem[];
  shipping_address?: {
    first_name?: string;
    last_name?: string;
    address?: string;
    city?: string;
    postal_code?: string;
    country?: string;
  };
  subtotal?: number;
  shipping_cost?: number;
  shipping_method?: {
    id?: string | null;
    provider_code?: string | null;
    provider_name?: string | null;
    service_code?: string | null;
    service_name?: string | null;
    label?: string | null;
    price?: number;
  } | null;
  tax_amount?: number;
  discount_amount?: number;
  loyalty_discount?: number;
  confirmed_at?: string;
  paid_at?: string;
  shipped_at?: string;
  delivered_at?: string;
  event_bookings?: Array<{
    id: number;
    booking_reference: string;
    state: string;
    event?: {
      id: number;
      title: string;
      venue?: string | null;
      event_date?: string | null;
    } | null;
    ticket_count: number;
    tickets: Array<{
      id: number;
      ticket_code: string;
      status: string;
      issued_at?: string | null;
      attendee_name?: string | null;
    }>;
  }>;
}

export interface CustomerTicket {
  id: number;
  booking_reference: string;
  status: string;
  total_amount: number;
  currency: string;
  buyer_name?: string | null;
  buyer_email?: string | null;
  event_id?: number | null;
  event_title?: string | null;
  event_date?: string | null;
  event_venue?: string | null;
  ticket_count: number;
  tickets: Array<{
    id: number;
    ticket_code: string;
    status: string;
    issued_at?: string | null;
    attendee_name?: string | null;
  }>;
  created_at?: string;
}

export interface OrderItem {
  id: number;
  product_id: number;
  product_name: string;
  product_sku: string | null;
  product_image: string | null;
  unit_price: number;
  quantity: number;
  tax_amount: number;
  line_total: number;
}

export async function customerLogin(payload: CustomerLoginPayload): Promise<AuthResponse> {
  const { data } = await storefrontApi.post('/web-shop/auth/login', payload);
  return data;
}

export async function customerRegister(payload: CustomerRegisterPayload): Promise<AuthResponse> {
  const { data } = await storefrontApi.post('/web-shop/auth/register', payload);
  return data;
}

export async function customerLogout(token: string): Promise<{ success: boolean }> {
  const { data } = await storefrontApi.post('/web-shop/auth/logout', {}, {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data;
}

export async function getCustomerProfile(token: string): Promise<CustomerProfile> {
  const { data } = await storefrontApi.get('/web-shop/customer/profile', {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data.customer || data;
}

export async function updateCustomerProfile(token: string, payload: Partial<CustomerProfile>): Promise<CustomerProfile> {
  const { data } = await storefrontApi.put('/web-shop/customer/profile', payload, {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data.customer || data;
}

export async function getCustomerOrders(token: string): Promise<CustomerOrder[]> {
  const { data } = await storefrontApi.get('/web-shop/orders', {
    headers: { Authorization: `Bearer ${token}` }
  });
  // Backend returns { orders: [...], pagination: {...} }
  return data.orders || data || [];
}

export async function getCustomerOrder(token: string, orderId: number): Promise<CustomerOrder> {
  const { data } = await storefrontApi.get(`/web-shop/orders/${orderId}`, {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data.order || data;
}

export async function getCustomerTickets(token: string): Promise<CustomerTicket[]> {
  const { data } = await storefrontApi.get('/web-shop/tickets', {
    headers: { Authorization: `Bearer ${token}` }
  });

  return data.tickets || data || [];
}

export async function cancelOrder(token: string, orderId: number): Promise<{ success: boolean; message: string; order: CustomerOrder }> {
  const { data } = await storefrontApi.post(`/web-shop/orders/${orderId}/cancel`, {}, {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data;
}

export async function retryOrderPayment(token: string, orderId: number, gatewayId?: number): Promise<CheckoutResponse> {
  const { data } = await storefrontApi.post(`/web-shop/orders/${orderId}/retry-payment`, {
    gateway_id: gatewayId,
  }, {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data;
}

// ---- Address API ----

export interface CustomerAddress {
  id: number;
  type: 'shipping' | 'billing';
  name: string;
  company?: string | null;
  address: string;
  city: string;
  state?: string | null;
  postal_code: string;
  country: string;
  phone?: string | null;
  is_default: boolean;
}

export async function getCustomerAddresses(token: string): Promise<CustomerAddress[]> {
  const { data } = await storefrontApi.get('/web-shop/customer/addresses', {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data.addresses || data || [];
}

export async function addCustomerAddress(token: string, payload: Omit<CustomerAddress, 'id'>): Promise<CustomerAddress> {
  const { data } = await storefrontApi.post('/web-shop/customer/addresses', payload, {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data.address || data;
}

export async function updateCustomerAddress(token: string, id: number, payload: Omit<CustomerAddress, 'id'>): Promise<CustomerAddress> {
  const { data } = await storefrontApi.put(`/web-shop/customer/addresses/${id}`, payload, {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data.address || data;
}

export async function deleteCustomerAddress(token: string, id: number): Promise<void> {
  await storefrontApi.delete(`/web-shop/customer/addresses/${id}`, {
    headers: { Authorization: `Bearer ${token}` }
  });
}

export async function setDefaultAddress(token: string, id: number): Promise<void> {
  await storefrontApi.post(`/web-shop/customer/addresses/${id}/default`, {}, {
    headers: { Authorization: `Bearer ${token}` }
  });
}

// ---- Loyalty API ----

export interface LoyaltyBalance {
  enrolled: boolean;
  points: {
    current: number;
    lifetime: number;
    earned: number;
    redeemed: number;
  };
  tier: {
    id: number;
    name: string;
    color: string;
    multiplier: number;
    icon: string | null;
  } | null;
  next_tier: {
    name: string;
    points_needed: number;
  } | null;
  progress: {
    current: number;
    needed: number;
    percent: number;
  } | null;
  program: {
    min_redemption_points: number;
    max_redemption_percent: number;
    currency_per_point: number;
  } | null;
}

export interface LoyaltyTransaction {
  id: number;
  type: 'earn' | 'redeem' | 'expire' | 'adjust';
  points: number;
  balance_after: number;
  description: string;
  reference_type: string | null;
  reference_id: number | null;
  created_at: string;
  expires_at: string | null;
}

export interface LoyaltyCalculateResponse {
  can_redeem: boolean;
  points: number;
  discount_amount: number;
  max_points: number;
  new_balance: number;
  reason?: string;
}

export async function getLoyaltyBalance(token: string): Promise<LoyaltyBalance> {
  const { data } = await storefrontApi.get('/web-shop/loyalty/balance', {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data;
}

export async function getLoyaltyHistory(token: string, page: number = 1): Promise<{
  transactions: LoyaltyTransaction[];
  pagination: { current_page: number; total: number };
}> {
  const { data } = await storefrontApi.get('/web-shop/loyalty/history', {
    params: { page, per_page: 20 },
    headers: { Authorization: `Bearer ${token}` }
  });
  return data;
}

export async function calculateLoyaltyDiscount(
  token: string,
  points: number,
  orderAmount: number
): Promise<LoyaltyCalculateResponse> {
  const { data } = await storefrontApi.post('/web-shop/loyalty/calculate', {
    points,
    order_amount: orderAmount,
  }, {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data;
}

// ---- Invoice API ----

export interface WebInvoice {
  id: number;
  invoice_number: string;
  invoice_date: string;
  due_date: string;
  status: string;
  subtotal: number;
  tax_amount: number;
  discount_amount: number;
  total: number;
  paid_amount: number;
  balance: number;
  notes: string | null;
  customer?: {
    name: string;
    email?: string;
    phone?: string;
  };
  lines?: {
    id: number;
    description: string;
    quantity: number;
    unit_price: number;
    line_total: number;
  }[];
  created_at: string;
}

export async function getOrderInvoice(token: string, orderId: number): Promise<WebInvoice> {
  const { data } = await storefrontApi.get(`/web-shop/orders/${orderId}/invoice`, {
    headers: { Authorization: `Bearer ${token}` }
  });
  return data.invoice || data;
}
