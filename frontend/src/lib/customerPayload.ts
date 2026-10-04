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

export interface CustomerBillingAddressFields {
  billing_street?: string
  billing_street2?: string
  billing_city?: string
  billing_state?: string
  billing_zip?: string
  billing_country?: string
}

export interface CustomerBillingAddressRecord {
  type: 'billing'
  street: string
  street2: string
  city: string
  state: string
  zip: string
  country: string
  is_default: boolean
}

export interface CustomerPayloadFields extends CustomerBillingAddressFields {
  credit_limit?: string | number | null
  payment_terms?: string | number | null
}

const BILLING_ADDRESS_FIELDS = [
  'billing_street',
  'billing_street2',
  'billing_city',
  'billing_state',
  'billing_zip',
  'billing_country',
] as const

export function buildBillingAddressRecord(data: CustomerBillingAddressFields): CustomerBillingAddressRecord | null {
  const record: CustomerBillingAddressRecord = {
    type: 'billing',
    street: (data.billing_street ?? '').trim(),
    street2: (data.billing_street2 ?? '').trim(),
    city: (data.billing_city ?? '').trim(),
    state: (data.billing_state ?? '').trim(),
    zip: (data.billing_zip ?? '').trim(),
    country: (data.billing_country ?? '').trim().toUpperCase(),
    is_default: true,
  }

  const hasValue = [record.street, record.street2, record.city, record.state, record.zip, record.country]
    .some(Boolean)

  return hasValue ? record : null
}

export function buildCustomerPayload<T extends CustomerPayloadFields>(data: T) {
  const payload = { ...data } as Record<string, unknown>

  for (const field of BILLING_ADDRESS_FIELDS) {
    delete payload[field]
  }

  const billingAddress = buildBillingAddressRecord(data)
  if (billingAddress) {
    payload.addresses = [billingAddress]
  }

  payload.credit_limit = normalizeCreditLimit(data.credit_limit)
  payload.payment_terms = normalizePaymentTerms(data.payment_terms)

  return payload
}
