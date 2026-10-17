import { describe, expect, it } from 'vitest'
import {
  formatInvoiceAddress,
  formatLegacyInvoiceAddress,
  getInvoiceCustomerAddress,
  invoiceAddressFormToSnapshot,
  invoiceAddressToForm,
} from './invoiceAddresses'

describe('invoice address helpers', () => {
  it('normalizes address values and writes snapshots in display order', () => {
    const address = { label: 'HQ', street: '1 Main St', city: 'Kuala Lumpur', country: 'MY' }
    expect(invoiceAddressToForm(address)).toMatchObject({ label: 'HQ', street: '1 Main St', city: 'Kuala Lumpur' })
    expect(formatInvoiceAddress(address)).toBe('HQ\n1 Main St\nKuala Lumpur\nMY')
    expect(invoiceAddressFormToSnapshot({ ...invoiceAddressToForm(address), state: 'WP', zip: '50000' }))
      .toBe('HQ\n1 Main St\nKuala Lumpur\nWP\n50000\nMY')
  })

  it('prefers backend-resolved addresses and falls back to legacy address data', () => {
    expect(getInvoiceCustomerAddress({
      billing_address: 'old value',
      resolved_addresses: { billing: { street: 'Resolved street' } },
    }, 'billing')).toBe('Resolved street')

    expect(getInvoiceCustomerAddress({
      billing_address: JSON.stringify({ name: 'Acme', street: 'Old Road', postal_code: '12345' }),
    }, 'billing')).toBe('Acme, Old Road, 12345')
    expect(formatLegacyInvoiceAddress('Plain text')).toBe('Plain text')
  })
})
