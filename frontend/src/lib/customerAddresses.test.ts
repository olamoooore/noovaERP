import { describe, expect, it } from 'vitest'
import { resolvedCustomerAddress } from './customerAddresses'

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
