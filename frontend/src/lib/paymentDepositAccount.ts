// #485 — "Deposit to" defaulting + per-user memory for payment forms.
//
// Payment forms must let the operator choose WHICH bank/cash account
// received the money (the backend posts the debit to that account's GL;
// without a choice every receipt lands on the default `bank` mapping).
// The picker is defaulted per payment method and remembered per user.

export interface DepositAccountOption {
  id: number;
  account_name: string;
  bank_name?: string | null;
  gl_account_id?: number;
  gl_account_code?: string | null;
  gl_account_name?: string | null;
  is_active?: boolean;
}

export type DepositMemory = Record<string, number>;

const memoryKey = (userId: number | string): string => `noovaerp:payment-deposit:${userId}`;

// localStorage is unavailable in some environments (node vitest, SSR) — fall
// back to an in-process store so behaviour stays testable and consistent.
const memoryFallback = new Map<string, DepositMemory>();

function hasLocalStorage(): boolean {
  try {
    return typeof localStorage !== 'undefined' && localStorage !== null;
  } catch {
    return false;
  }
}

export function readDepositMemory(userId: number | string): DepositMemory {
  if (!userId) return {};
  const key = memoryKey(userId);
  if (hasLocalStorage()) {
    try {
      const raw = localStorage.getItem(key);
      const parsed = raw ? (JSON.parse(raw) as DepositMemory) : null;
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  }
  return memoryFallback.get(key) ?? {};
}

export function rememberDeposit(
  userId: number | string | null | undefined,
  method: string,
  accountId: number,
): void {
  if (!userId || !method || !Number.isFinite(accountId)) return;
  const key = memoryKey(userId);
  const memory: DepositMemory = { ...readDepositMemory(userId), [method]: accountId };
  if (hasLocalStorage()) {
    try {
      localStorage.setItem(key, JSON.stringify(memory));
      return;
    } catch {
      // quota/privacy errors fall through to the in-process store
    }
  }
  memoryFallback.set(key, memory);
}

/** Clear all remembered deposit accounts (used by tests/teardown). */
export function clearDepositMemory(userId: number | string): void {
  const key = memoryKey(userId);
  if (hasLocalStorage()) {
    try {
      localStorage.removeItem(key);
    } catch {
      /* ignore */
    }
  }
  memoryFallback.delete(key);
}

/**
 * Default deposit account for a method:
 *   1. the user's remembered choice for THIS method, if still offered;
 *   2. otherwise the account whose GL code matches the platform convention —
 *      1010xx cash on hand for cash, 1020xx bank for every other method;
 *   3. otherwise the first offered account;
 *   4. null when no accounts are configured (backend falls back to the
 *      default `bank` mapping — same behaviour as today).
 */
export function defaultDepositAccountId(
  method: string,
  options: DepositAccountOption[],
  memory: DepositMemory = {},
): number | null {
  if (!options.length) return null;

  const remembered = memory[method];
  if (remembered != null && options.some((option) => option.id === remembered)) {
    return remembered;
  }

  const preferredPrefix = method === 'cash' ? '1010' : '1020';
  const preferred = options.find((option) =>
    String(option.gl_account_code ?? '').startsWith(preferredPrefix),
  );

  return (preferred ?? options[0])?.id ?? null;
}

// #508 (PY6): the payment METHOD is remembered per user too, so a clerk
// receipting a stack of cheques does not re-pick "Check" on every payment.
const methodMemoryKey = (userId: number | string): string => `noovaerp:payment-method:${userId}`;
const methodFallback = new Map<string, string>();

export function readRememberedMethod(userId: number | string | null | undefined): string | null {
  if (!userId) return null;
  const key = methodMemoryKey(userId);
  if (hasLocalStorage()) {
    try {
      const raw = localStorage.getItem(key);
      if (raw) return raw;
    } catch {
      /* fall through to the in-process store */
    }
  }
  return methodFallback.get(key) ?? null;
}

export function rememberMethod(userId: number | string | null | undefined, method: string): void {
  if (!userId || !method) return;
  const key = methodMemoryKey(userId);
  methodFallback.set(key, method);
  if (hasLocalStorage()) {
    try {
      localStorage.setItem(key, method);
    } catch {
      // quota/privacy errors: the in-process store above still holds it
    }
  }
}

/** Clear the remembered method (used by tests/teardown). */
export function clearMethodMemory(userId: number | string): void {
  const key = methodMemoryKey(userId);
  if (hasLocalStorage()) {
    try {
      localStorage.removeItem(key);
    } catch {
      /* ignore */
    }
  }
  methodFallback.delete(key);
}
