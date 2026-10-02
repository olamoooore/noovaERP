/**
 * #429 (CU1): customer form numeric fields.
 *
 * `0` is a real value, not "empty": a credit limit of 0 means cash-only and
 * payment terms of 0 mean COD. The old truthiness checks (`data.credit_limit ?
 * ... : null`) sent 0 as null — unlimited credit under #144 — and reset COD
 * terms to 30 days on every save. Only an intentionally cleared (empty) field
 * falls back: null credit limit = unlimited, empty terms = the 30-day default.
 */

export function normalizeCreditLimit(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const parsed = Number(value)

  return Number.isFinite(parsed) ? parsed : null
}

export function normalizePaymentTerms(value: string | number | null | undefined): number {
  if (value === null || value === undefined || value === '') {
    return 30
  }

  const parsed = parseInt(String(value), 10)

  return Number.isFinite(parsed) ? parsed : 30
}

export interface CustomerPayloadFields {
  credit_limit?: string | number | null
  payment_terms?: string | number | null
}

export function buildCustomerPayload<T extends CustomerPayloadFields>(data: T) {
  return {
    ...data,
    credit_limit: normalizeCreditLimit(data.credit_limit),
    payment_terms: normalizePaymentTerms(data.payment_terms),
  }
}
