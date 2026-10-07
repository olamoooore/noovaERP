import type { CustomerCreditNote } from '@/types/creditNote';

/**
 * Shared rules for applying a posted credit note's remaining credit to other
 * open invoices (#495 invoice-side "Outstanding credit notes" card and the
 * #528 credit-note-side "Apply to invoices" action). The backend
 * (PaymentAllocationService::allocateCreditNote) is authoritative; these only
 * keep the UI from offering what it would refuse.
 */

/** Credit note reads are gated by accounting.view (#515). */
export const CREDIT_NOTE_VIEW_PERMISSION = 'accounting.view';
/** Applying credit is an accounting write (route: accounting.write). */
export const CREDIT_NOTE_APPLY_PERMISSION = 'accounting.write';
export const CREDIT_NOTES_API_BASE = '/accounting/customers/credit-notes';

export const CREDIT_EPSILON = 0.005;

export const round2 = (value: number) => Math.round(value * 100) / 100;

export const toNumber = (value: unknown) => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

export const remainingCreditOf = (note: Pick<CustomerCreditNote, 'outcome'>) =>
  round2(toNumber(note.outcome?.remaining_credit));

/** Equal (case-insensitive), or either side unknown — the backend decides then. */
export const sameOrUnknown = (a?: string | number | null, b?: string | number | null) =>
  a == null || b == null || a === '' || b === '' || String(a).toUpperCase() === String(b).toUpperCase();

/** Invoice lifecycles PaymentAllocationService accepts as open receivables. */
export const APPLICABLE_INVOICE_LIFECYCLES = ['posted', 'credited'];
