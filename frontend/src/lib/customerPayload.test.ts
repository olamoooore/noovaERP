import { describe, expect, it } from 'vitest'
import { buildCustomerPayload, normalizeCreditLimit, normalizePaymentTerms } from './customerPayload'

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
