import api from '@/lib/api';

const QUEUE_CAMPAIGN_KEY_STORAGE = 'ace_webshop_queue_campaign_key';
const QUEUE_TOKEN_STORAGE = 'ace_webshop_queue_token';
const QUEUE_REQUIRED_STORAGE = 'ace_webshop_queue_required';

export interface CheckoutQueueContext {
  queue_required: boolean;
  campaign_key: string;
  queue_token: string;
}

export interface QueueJoinResponse {
  queue_token: string;
  campaign_key: string;
  status: 'active' | 'waiting';
  position: number;
  estimated_wait_seconds: number;
  expires_at: string;
}

export interface QueueStatusResponse {
  queue_token: string;
  campaign_key: string;
  status: 'active' | 'waiting';
  position: number;
  estimated_wait_seconds: number;
  expires_at: string;
}

const truthy = new Set(['1', 'true', 'yes']);

function readQueryParam(params: URLSearchParams, ...keys: string[]): string | null {
  for (const key of keys) {
    const value = params.get(key);
    if (value && value.trim() !== '') {
      return value.trim();
    }
  }

  return null;
}

function shouldRequireQueue(raw: string | null): boolean {
  if (!raw) {
    return false;
  }

  return truthy.has(raw.toLowerCase());
}

export function resolveCheckoutQueueContext(): CheckoutQueueContext | null {
  const params = new URLSearchParams(window.location.search);

  const campaignKey =
    readQueryParam(params, 'campaign_key', 'campaignKey', 'queue_campaign')
    || localStorage.getItem(QUEUE_CAMPAIGN_KEY_STORAGE)
    || '';

  const queueToken =
    readQueryParam(params, 'queue_token', 'queueToken')
    || localStorage.getItem(QUEUE_TOKEN_STORAGE)
    || '';

  const requiredFromQuery = shouldRequireQueue(readQueryParam(params, 'queue_required', 'queueRequired'));
  const requiredFromStorage = shouldRequireQueue(localStorage.getItem(QUEUE_REQUIRED_STORAGE));

  const queueRequired = requiredFromQuery || requiredFromStorage || (!!campaignKey && !!queueToken);

  if (!queueRequired) {
    return null;
  }

  if (!campaignKey || !queueToken) {
    return null;
  }

  return {
    queue_required: true,
    campaign_key: campaignKey,
    queue_token: queueToken,
  };
}

export function persistCheckoutQueueContext(context: CheckoutQueueContext | null): void {
  if (!context) {
    return;
  }

  localStorage.setItem(QUEUE_CAMPAIGN_KEY_STORAGE, context.campaign_key);
  localStorage.setItem(QUEUE_TOKEN_STORAGE, context.queue_token);
  localStorage.setItem(QUEUE_REQUIRED_STORAGE, String(context.queue_required));
}

export function clearCheckoutQueueContext(): void {
  localStorage.removeItem(QUEUE_CAMPAIGN_KEY_STORAGE);
  localStorage.removeItem(QUEUE_TOKEN_STORAGE);
  localStorage.removeItem(QUEUE_REQUIRED_STORAGE);
}

export async function joinCheckoutQueue(
  campaignKey: string,
  options?: { max_active?: number; slot_ttl_seconds?: number },
): Promise<QueueJoinResponse> {
  const response = await api.post('/web-shop/queue/join', {
    campaign_key: campaignKey,
    max_active: options?.max_active,
    slot_ttl_seconds: options?.slot_ttl_seconds,
  });

  const data = response?.data?.data as QueueJoinResponse;
  persistCheckoutQueueContext({
    queue_required: true,
    campaign_key: data.campaign_key,
    queue_token: data.queue_token,
  });

  return data;
}

export async function getCheckoutQueueStatus(
  campaignKey: string,
  queueToken: string,
): Promise<QueueStatusResponse> {
  const response = await api.post('/web-shop/queue/status', {
    campaign_key: campaignKey,
    queue_token: queueToken,
  });

  return response?.data?.data as QueueStatusResponse;
}

export async function heartbeatCheckoutQueue(
  campaignKey: string,
  queueToken: string,
): Promise<QueueStatusResponse> {
  const response = await api.post('/web-shop/queue/heartbeat', {
    campaign_key: campaignKey,
    queue_token: queueToken,
  });

  return response?.data?.data as QueueStatusResponse;
}

export async function leaveCheckoutQueue(
  campaignKey: string,
  queueToken: string,
): Promise<void> {
  await api.post('/web-shop/queue/leave', {
    campaign_key: campaignKey,
    queue_token: queueToken,
  });

  const stored = resolveCheckoutQueueContext();
  if (stored && stored.campaign_key === campaignKey && stored.queue_token === queueToken) {
    clearCheckoutQueueContext();
  }
}
