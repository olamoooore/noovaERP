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
  // Issue #324: default master payment term ('' / null = none).
  payment_term_id?: string | number | null
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
  if ('payment_term_id' in data) {
    const termId = Number(data.payment_term_id)
    payload.payment_term_id = data.payment_term_id !== '' && data.payment_term_id != null && Number.isFinite(termId) && termId > 0
      ? termId
      : null
  }

  return payload
}

/**
 * #437 (CU9): the one customer form values shape.
 *
 * Every customer create/edit surface (list quick-create, detail-page edit,
 * in-document quick-create) renders the same `CustomerForm` over these values,
 * so field handling cannot drift again. Numeric fields stay strings while the
 * user types; the payload builders below do the normalisation.
 */
export interface CustomerFormValues {
  code: string
  type: 'individual' | 'company'
  name: string
  company: string
  tax_number: string
  company_registration_number: string
  email: string
  phone: string
  mobile: string
  website: string
  billing_street: string
  billing_street2: string
  billing_city: string
  billing_state: string
  billing_zip: string
  billing_country: string
  shipping_address: string
  credit_limit: string
  payment_terms: string
  // Issue #324: default master payment term id ('' = none).
  payment_term_id: string
  preferred_contact: string
  date_of_birth: string
  gender: string
  marketing_opt_in: boolean
  notes: string
  category_ids: number[]
}

export const PREFERRED_CONTACT_METHODS = ['email', 'phone', 'sms', 'whatsapp'] as const
export const CUSTOMER_GENDERS = ['male', 'female', 'other'] as const

export function emptyCustomerFormValues(overrides: Partial<CustomerFormValues> = {}): CustomerFormValues {
  return {
    code: '',
    type: 'individual',
    name: '',
    company: '',
    tax_number: '',
    company_registration_number: '',
    email: '',
    phone: '',
    mobile: '',
    website: '',
    billing_street: '',
    billing_street2: '',
    billing_city: '',
    billing_state: '',
    billing_zip: '',
    billing_country: '',
    shipping_address: '',
    credit_limit: '',
    payment_terms: '30',
    payment_term_id: '',
    preferred_contact: 'email',
    date_of_birth: '',
    gender: '',
    marketing_opt_in: false,
    notes: '',
    category_ids: [],
    ...overrides,
  }
}

export interface CustomerFormAddressSource {
  type?: string | null
  street?: string | null
  street2?: string | null
  city?: string | null
  state?: string | null
  zip?: string | null
  country?: string | null
  is_default?: boolean | null
}

export interface CustomerFormSource {
  code?: string | null
  type?: 'individual' | 'company' | null
  name?: string | null
  company?: string | null
  tax_number?: string | null
  company_registration_number?: string | null
  email?: string | null
  phone?: string | null
  mobile?: string | null
  website?: string | null
  billing_address?: string | null
  shipping_address?: string | null
  credit_limit?: string | number | null
  payment_terms?: string | number | null
  payment_term_id?: string | number | null
  preferred_contact?: string | null
  date_of_birth?: string | null
  gender?: string | null
  marketing_opt_in?: boolean | null
  notes?: string | null
  categories?: { id: number }[] | null
  addresses?: CustomerFormAddressSource[] | null
}

const digitsOnly = (value: string | null | undefined) => (value ?? '').replace(/\D/g, '')

function splitLegacyAddress(address: string | null | undefined): Partial<CustomerBillingAddressFields> {
  if (!address) return {}

  const trimmed = address.trim()
  if (trimmed.startsWith('{')) {
    try {
      const parsed = JSON.parse(trimmed) as Record<string, unknown>
      const pick = (...keys: string[]) => {
        for (const key of keys) {
          const value = parsed[key]
          if (typeof value === 'string' && value !== '') return value
        }
        return ''
      }
      return {
        billing_street: pick('address', 'street'),
        billing_street2: pick('street2'),
        billing_city: pick('city'),
        billing_state: pick('state'),
        billing_zip: pick('zip', 'postal_code'),
        billing_country: pick('country'),
      }
    } catch {
      // Fall through and treat it as plain text.
    }
  }

  return { billing_street: address }
}

export function customerToFormValues(customer: CustomerFormSource = {}): CustomerFormValues {
  const addresses = customer.addresses ?? []
  const billing = addresses.find((address) => address.type === 'billing' && address.is_default)
    ?? addresses.find((address) => address.type === 'billing')
  const legacy = billing ? {} : splitLegacyAddress(customer.billing_address)

  const preferredContact = (customer.preferred_contact ?? '').toLowerCase()
  const gender = (customer.gender ?? '').toLowerCase()

  return emptyCustomerFormValues({
    code: customer.code ?? '',
    type: customer.type ?? 'individual',
    name: customer.name ?? '',
    company: customer.company ?? '',
    tax_number: customer.tax_number ?? '',
    company_registration_number: customer.company_registration_number ?? '',
    email: customer.email ?? '',
    phone: digitsOnly(customer.phone),
    mobile: digitsOnly(customer.mobile),
    website: customer.website ?? '',
    billing_street: billing?.street ?? legacy.billing_street ?? '',
    billing_street2: billing?.street2 ?? legacy.billing_street2 ?? '',
    billing_city: billing?.city ?? legacy.billing_city ?? '',
    billing_state: billing?.state ?? legacy.billing_state ?? '',
    billing_zip: billing?.zip ?? legacy.billing_zip ?? '',
    billing_country: billing?.country ?? legacy.billing_country ?? '',
    shipping_address: customer.shipping_address ?? '',
    credit_limit: customer.credit_limit === null || customer.credit_limit === undefined ? '' : String(customer.credit_limit),
    payment_terms: customer.payment_terms === null || customer.payment_terms === undefined ? '30' : String(customer.payment_terms),
    payment_term_id: customer.payment_term_id === null || customer.payment_term_id === undefined ? '' : String(customer.payment_term_id),
    preferred_contact: (PREFERRED_CONTACT_METHODS as readonly string[]).includes(preferredContact) ? preferredContact : 'email',
    date_of_birth: (customer.date_of_birth ?? '').slice(0, 10),
    gender: (CUSTOMER_GENDERS as readonly string[]).includes(gender) ? gender : '',
    marketing_opt_in: Boolean(customer.marketing_opt_in),
    notes: customer.notes ?? '',
    category_ids: (customer.categories ?? []).map((category) => category.id),
  })
}

/**
 * Create payload: the endpoint accepts `addresses[]`, so the structured
 * billing fields travel as a billing address record (#436 CU8).
 */
export function customerFormToCreatePayload(values: CustomerFormValues): Record<string, unknown> {
  return buildCustomerPayload(values)
}

/**
 * Update payload: `PUT /customers/{id}` does not accept `addresses[]`. The
 * structured billing fields travel as `billing_address_record`, which updates
 * the default billing address the form loads from (ADR-003B W1); they are also
 * flattened into the legacy `billing_address` column for screens that still
 * read it. The zero/normalisation rules stay identical to create (#429 CU1).
 */
export function customerFormToUpdatePayload(values: CustomerFormValues): Record<string, unknown> {
  const payload = buildCustomerPayload(values)
  const addresses = payload.addresses as CustomerBillingAddressRecord[] | undefined
  delete payload.addresses
  delete payload.billing_address

  if (addresses && addresses.length > 0) {
    const billing = addresses[0]
    payload.billing_address_record = {
      street: billing.street,
      street2: billing.street2,
      city: billing.city,
      state: billing.state,
      zip: billing.zip,
      country: billing.country,
    }
    payload.billing_address = [
      billing.street,
      billing.street2,
      billing.city,
      billing.state,
      billing.zip,
      billing.country,
    ].filter(Boolean).join('\n')
  }

  return payload
}
