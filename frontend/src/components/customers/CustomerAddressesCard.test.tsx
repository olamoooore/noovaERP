// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { CustomerAddressesCard, type CustomerAddressRow } from './CustomerAddressesCard'

const ADDRESSES: CustomerAddressRow[] = [
  { id: 1, type: 'billing', label: 'Head Office', street: '1 Finance Road', city: 'Kuala Lumpur', country: 'MY', is_default: true },
  { id: 2, type: 'shipping', label: 'Warehouse', street: '22 Warehouse Way', city: 'Shah Alam', country: 'MY', is_default: true },
]
const SITES = [{ id: 'site-1', address_type: 'site', label: 'Main Clinic', line_1: '7 Clinic Lane', city: 'Ipoh', country: 'MY' }]

function renderCard(overrides: Partial<React.ComponentProps<typeof CustomerAddressesCard>> = {}) {
  const props = {
    addresses: ADDRESSES,
    sites: SITES,
    peopleBySite: { 'site-1': ['Dr Tan', 'Nurse Lim'] },
    legacyBilling: null,
    legacyShipping: null,
    canAddSites: true,
    onAdd: vi.fn(),
    onEdit: vi.fn(),
    onDelete: vi.fn(),
    ...overrides,
  }
  render(<CustomerAddressesCard {...props} />)
  return props
}

/**
 * ADR-003B W5: the customer's addresses in one card, grouped Billing /
 * Delivery / Sites, with the people who work at each site.
 */
describe('CustomerAddressesCard', () => {
  afterEach(cleanup)

  it('groups addresses into Billing, Delivery and Sites', () => {
    renderCard()

    expect(within(screen.getByRole('region', { name: 'Billing' })).getByText('Head Office')).toBeTruthy()
    expect(within(screen.getByRole('region', { name: 'Delivery' })).getByText('Warehouse')).toBeTruthy()
    const sites = screen.getByRole('region', { name: 'Sites' })
    expect(within(sites).getByText('Main Clinic')).toBeTruthy()
    expect(within(sites).getByText('Dr Tan, Nurse Lim')).toBeTruthy()
    expect(screen.queryByRole('region', { name: 'Other' })).toBeNull()
  })

  it('edits and deletes customer addresses but offers no edit on sites', () => {
    const props = renderCard()

    fireEvent.click(within(screen.getByRole('region', { name: 'Billing' })).getByRole('button', { name: 'Edit address' }))
    expect(props.onEdit).toHaveBeenCalledWith(ADDRESSES[0])
    fireEvent.click(within(screen.getByRole('region', { name: 'Delivery' })).getByRole('button', { name: 'Delete address' }))
    expect(props.onDelete).toHaveBeenCalledWith(ADDRESSES[1])
    expect(within(screen.getByRole('region', { name: 'Sites' })).queryByRole('button')).toBeNull()
  })

  it('shows older free-text addresses read-only', () => {
    renderCard({ legacyBilling: '12 Old Street\nKuala Lumpur' })

    const older = screen.getByRole('region', { name: 'Older addresses' })
    expect(older.textContent).toContain('12 Old Street')
    expect(within(older).queryByRole('button')).toBeNull()
  })

  it('shows empty states for each group', () => {
    renderCard({ addresses: [], sites: [] })

    expect(screen.getByText('No billing address yet.')).toBeTruthy()
    expect(screen.getByText('No delivery address yet.')).toBeTruthy()
    expect(screen.getByText(/No sites yet/)).toBeTruthy()
  })
})
