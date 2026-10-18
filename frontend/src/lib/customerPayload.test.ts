import { describe, expect, it } from 'vitest'
import {
  buildCustomerPayload,
  customerFormToUpdatePayload,
  emptyCustomerFormValues,
  normalizeCreditLimit,
  normalizePaymentTerms,
} from './customerPayload'

/**
 * #429 (CU1): a credit limit of 0 is cash-only (not unlimited), and payment
 * terms of 0 are COD (not 30 days). The old truthiness checks turned both into
 * the wrong defaults.
 */
describe('normalizeCreditLimit', () => {
  it('keeps zero as zero', () => {
    expect(normalizeCreditLimit('0')).toBe(0)
    expect(normalizeCreditLimit(0)).toBe(0)
  })

  it('parses positive limits', () => {
    expect(normalizeCreditLimit('500')).toBe(500)
    expect(normalizeCreditLimit('1500.75')).toBe(1500.75)
  })

  it('treats an intentionally cleared field as unlimited (null)', () => {
    expect(normalizeCreditLimit('')).toBeNull()
    expect(normalizeCreditLimit(null)).toBeNull()
    expect(normalizeCreditLimit(undefined)).toBeNull()
  })
})

describe('normalizePaymentTerms', () => {
  it('keeps zero (COD) as zero', () => {
    expect(normalizePaymentTerms('0')).toBe(0)
    expect(normalizePaymentTerms(0)).toBe(0)
  })

  it('parses other terms', () => {
    expect(normalizePaymentTerms('15')).toBe(15)
    expect(normalizePaymentTerms('60')).toBe(60)
  })

  it('falls back to 30 only for a cleared field', () => {
    expect(normalizePaymentTerms('')).toBe(30)
    expect(normalizePaymentTerms(null)).toBe(30)
  })
})

describe('buildCustomerPayload', () => {
  it('sends 0/0 when the form was filled with 0/0', () => {
    const payload = buildCustomerPayload({
      name: 'Klinik Ali',
      credit_limit: '0',
      payment_terms: '0',
    })

    expect(payload).toEqual({ name: 'Klinik Ali', credit_limit: 0, payment_terms: 0 })
  })

  it('keeps numeric 0 as 0 — the falsy value the old ternary turned into null/30', () => {
    const payload = buildCustomerPayload({
      name: 'COD Customer',
      credit_limit: 0,
      payment_terms: 0,
    })

    expect(payload).toEqual({ name: 'COD Customer', credit_limit: 0, payment_terms: 0 })
  })

  it('sends null only for a cleared credit limit and keeps other fields', () => {
    const payload = buildCustomerPayload({
      name: 'Cash Customer',
      credit_limit: '',
      payment_terms: '30',
      email: 'a@b.c',
    })

    expect(payload).toEqual({
      name: 'Cash Customer',
      credit_limit: null,
      payment_terms: 30,
      email: 'a@b.c',
    })
  })
})

/**
 * #436 (CU8): the create dialog captures a structured billing address with an
 * ISO country code and sends it as addresses[]; the flat billing_* form fields
 * must not leak into the payload.
 */
describe('buildCustomerPayload — structured billing address', () => {
  it('maps the flat billing fields to an addresses[] billing record and normalises the country', () => {
    const payload = buildCustomerPayload({
      name: 'Klinik Sihat',
      billing_street: ' 12 Jalan Ampang ',
      billing_street2: 'Suite 4-1',
      billing_city: 'Kuala Lumpur',
      billing_state: '14',
      billing_zip: '50450',
      billing_country: 'my',
      credit_limit: '',
      payment_terms: '30',
    })

    expect(payload.addresses).toEqual([
      {
        type: 'billing',
        street: '12 Jalan Ampang',
        street2: 'Suite 4-1',
        city: 'Kuala Lumpur',
        state: '14',
        zip: '50450',
        country: 'MY',
        is_default: true,
      },
    ])
    expect('billing_street' in payload).toBe(false)
    expect('billing_country' in payload).toBe(false)
  })

  it('omits addresses when every billing field is empty', () => {
    const payload = buildCustomerPayload({
      name: 'Walk-in',
      billing_street: '',
      billing_city: '   ',
      billing_country: '',
    })

    expect(payload.addresses).toBeUndefined()
  })
})

/**
 * ADR-003B W1: the edit payload must carry the structured billing fields so
 * PUT /customers/{id} can update the default billing address the form loads
 * from; the legacy text column is still sent for screens that read it.
 */
describe('customerFormToUpdatePayload — billing address', () => {
  it('sends the structured billing record alongside the legacy text', () => {
    const payload = customerFormToUpdatePayload(emptyCustomerFormValues({
      name: 'Acme Clinic',
      billing_street: ' 9 New Avenue ',
      billing_city: 'Petaling Jaya',
      billing_state: 'Selangor',
      billing_zip: '46000',
      billing_country: 'my',
    }))

    expect(payload.billing_address_record).toEqual({
      street: '9 New Avenue',
      street2: '',
      city: 'Petaling Jaya',
      state: 'Selangor',
      zip: '46000',
      country: 'MY',
    })
    expect(payload.billing_address).toBe('9 New Avenue\nPetaling Jaya\nSelangor\n46000\nMY')
    expect('addresses' in payload).toBe(false)
  })

  it('sends no billing record when every billing field is empty', () => {
    const payload = customerFormToUpdatePayload(emptyCustomerFormValues({ name: 'Acme Clinic' }))

    expect('billing_address_record' in payload).toBe(false)
    expect('billing_address' in payload).toBe(false)
  })
})
