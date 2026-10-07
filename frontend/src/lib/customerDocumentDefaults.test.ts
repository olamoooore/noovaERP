import { describe, it, expect } from 'vitest'
import {
  buildContactAddress,
  buildContactBillingAddress,
  getCustomerContactForRole,
  pickContactMasterAddress,
  pickContactMasterBillingAddress,
} from './customerDocumentDefaults'

describe('customerDocumentDefaults — contact role + address resolution', () => {
  it('picks the billing-category contact for billing and shipping-category for shipping', () => {
    const customer = {
      customer_contacts: [
        { id: 1, name: 'Doc', contact_category: 'doctor' },
        { id: 2, name: 'Primary', is_primary: true },
        { id: 3, name: 'Bill', contact_category: 'billing' },
        { id: 4, name: 'Ship', contact_category: 'shipping' },
      ],
    }

    expect(getCustomerContactForRole(customer, 'billing')?.id).toBe(3)
    expect(getCustomerContactForRole(customer, 'shipping')?.id).toBe(4)
  })

  it('falls back to the primary contact, then the first one', () => {
    const noCategories = { customer_contacts: [{ id: 1, name: 'First' }, { id: 2, name: 'Second', is_primary: true }] }
    expect(getCustomerContactForRole(noCategories, 'shipping')?.id).toBe(2)
    expect(getCustomerContactForRole({ customer_contacts: [{ id: 9, name: 'Only' }] }, 'billing')?.id).toBe(9)
  })
})

describe('customerDocumentDefaults — contact billing address', () => {
  const masterAddresses = [
    {
      address_type: 'shipping',
      line_1: '99 Ship Rd',
      city: 'Klang',
      is_default_billing: false,
    },
    {
      address_type: 'office',
      label: 'HQ',
      line_1: '10 Contact Bill Rd',
      line_2: 'Suite 2',
      line_3: 'Tower B',
      city: 'Kuala Lumpur',
      state: 'WP',
      postal_code: '50000',
      country: 'Malaysia',
      is_default_billing: true,
    },
  ]

  it('prefers the Contact Master default billing address', () => {
    const picked = pickContactMasterBillingAddress(masterAddresses)
    expect(picked?.line_1).toBe('10 Contact Bill Rd')
    expect(pickContactMasterAddress(masterAddresses, 'billing')?.line_1).toBe('10 Contact Bill Rd')
  })

  it('prefers the default shipping address for the shipping role', () => {
    const addresses = [
      { address_type: 'shipping', line_1: '1 Ship A', is_default_shipping: false },
      { address_type: 'shipping', line_1: '2 Ship B', is_default_shipping: true },
    ]
    expect(pickContactMasterAddress(addresses, 'shipping')?.line_1).toBe('2 Ship B')
    expect(
      buildContactAddress({ id: 14, name: 'Shipping Sue' }, addresses, 'shipping')?.street,
    ).toBe('2 Ship B')
  })

  it('maps a Contact Master address onto the document address form', () => {
    const address = buildContactBillingAddress(
      { id: 13, name: 'Billing Bob', core_contact_uuid: 'uuid-1' },
      masterAddresses,
    )

    expect(address).toEqual({
      label: 'HQ',
      street: '10 Contact Bill Rd',
      street2: 'Suite 2 Tower B',
      city: 'Kuala Lumpur',
      state: 'WP',
      zip: '50000',
      country: 'Malaysia',
    })
  })

  it('falls back to the billing address type when no default flag is set', () => {
    const picked = pickContactMasterBillingAddress([
      { address_type: 'billing', line_1: '7 Fallback Rd' },
      { address_type: 'shipping', line_1: '8 Other Rd' },
    ])
    expect(picked?.line_1).toBe('7 Fallback Rd')
  })

  it('uses the customer-contact inline address when Contact Master has none', () => {
    const address = buildContactBillingAddress(
      { id: 13, name: 'Inline Ines', street: '1 Inline Rd', street2: 'B-2', city: 'Ipoh', zip: '30000' },
      [],
    )

    expect(address?.street).toBe('1 Inline Rd')
    expect(address?.city).toBe('Ipoh')
  })

  it('returns null when the contact carries no address at all', () => {
    expect(buildContactBillingAddress({ id: 13, name: 'No Address' }, [])).toBeNull()
    expect(buildContactBillingAddress(null, masterAddresses)).toBeNull()
  })
})
