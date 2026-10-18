import { describe, expect, it } from 'vitest'
import { resolvedAddressText, resolvedCustomerAddress } from './customerAddresses'

/**
 * ADR-003B R4: screens pre-fill bill-to/ship-to from the backend resolver's
 * `resolved_addresses`, and keep their previous logic only when the response
 * does not carry it.
 */
describe('resolvedCustomerAddress', () => {
  const billing = { label: 'HQ', street: '1 Finance Road', city: 'Kuala Lumpur', country: 'MY' }

  it('returns the resolved address for the requested type', () => {
    const customer = { resolved_addresses: { billing, shipping: null } }

    expect(resolvedCustomerAddress(customer, 'billing')).toEqual(billing)
  })

  it('returns null when the resolver found no address', () => {
    const customer = { resolved_addresses: { billing, shipping: null } }

    expect(resolvedCustomerAddress(customer, 'shipping')).toBeNull()
  })

  it('returns undefined when the response has no resolved_addresses', () => {
    expect(resolvedCustomerAddress({}, 'billing')).toBeUndefined()
    expect(resolvedCustomerAddress(null, 'billing')).toBeUndefined()
  })
})

describe('resolvedAddressText', () => {
  it('writes one address part per line', () => {
    expect(resolvedAddressText({ street: '22 Warehouse Way', city: 'Shah Alam', state: 'Selangor', zip: '40000', country: 'MY' }))
      .toBe('22 Warehouse Way\nShah Alam Selangor 40000\nMY')
  })

  it('is empty when there is no address', () => {
    expect(resolvedAddressText(null)).toBe('')
  })
})
