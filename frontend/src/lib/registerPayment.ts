import { useAuthStore } from '@/store/authStore';

// #491 (PAY-UX5): when the in-place "Register payment" action is offered.

/** Invoice statuses a customer payment can be registered against. */
export const PAYABLE_INVOICE_STATUSES = ['posted', 'sent', 'partial', 'overdue'] as const;

/**
 * Recording a (draft) payment is an accounting write — the same permission
 * the credit-note draft action uses (#524).
 */
export const REGISTER_PAYMENT_PERMISSION = 'accounting.write';

/** True when the invoice is in a payable state and still has an open balance. */
export const isInvoicePayable = (invoice: { status?: string | null; balance?: number | string | null }): boolean =>
  (PAYABLE_INVOICE_STATUSES as readonly string[]).includes(String(invoice.status ?? ''))
  && Number(invoice.balance ?? 0) > 0.005;

/** The register-payment action is offered only when payable AND permitted. */
export const useCanRegisterPayment = (): boolean =>
  useAuthStore((state) => state.hasPermission(REGISTER_PAYMENT_PERMISSION));
