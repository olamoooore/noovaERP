// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import api from '@/lib/api'
import { CustomerDetailsPage } from './CustomerDetailsPage'

// jsdom polyfills Radix needs at render time (same as the other smoke suites).
if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void }
}
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}

vi.mock('@/lib/api', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

const mockedGet = vi.mocked(api.get)

const BASE_CUSTOMER = {
  id: 1,
  code: 'CUST-001',
  type: 'company',
  name: 'Klinik Sihat',
  display_name: 'Klinik Sihat',
  company: 'Klinik Sihat Sdn Bhd',
  email: 'ops@klinik-sihat.my',
  phone: '60123456789',
  mobile: '',
  tax_number: '1234567890',
  company_registration_number: '',
  website: '',
  billing_address: null,
  shipping_address: '',
  credit_limit: 0,
  payment_terms: 0,
  total_outstanding: 0,
  total_purchases: 9800,
  total_orders: 4,
  average_order_value: 2450,
  loyalty_points: 0,
  is_active: true,
  marketing_opt_in: false,
  preferred_contact: 'email',
  customer_since: '2025-01-01',
  last_interaction_at: '2026-01-01',
  notes: '',
  date_of_birth: '',
  gender: '',
  categories: [],
  salesperson: undefined,
  price_list: undefined,
  addresses: [
    {
      id: 5,
      type: 'billing',
      label: '',
      street: '12 Jalan Ampang',
      street2: '',
      city: 'Kuala Lumpur',
      state: '14',
      zip: '50450',
      country: 'MY',
      is_default: true,
    },
  ],
  customer_contacts: [],
  loyalty_balances: [],
  invoices: [],
  pos_orders: [],
  web_orders: [],
}

const ORG = 'org-uuid'
const CUSTOMER = {
  ...BASE_CUSTOMER,
  core_contact_uuid: ORG,
  billing_party: { billing_address: 'Hospital Group Finance\nLevel 9, KL' },
  customer_contacts: [{ id: 3, name: 'Dr Tan', core_contact_uuid: 'person-1', is_primary: true, email: '', phone: '', mobile: '' }],
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/customers/1']}>
        <Routes>
          <Route path="/customers/:id" element={<CustomerDetailsPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mockedGet.mockReset()
  mockedGet.mockImplementation(async (url: string) => {
    if (url === '/customers/1') return { data: CUSTOMER }
    if (url === `/contacts/${ORG}/addresses`) {
      return { data: { data: [{ id: 'site-1', address_type: 'site', label: 'Main Clinic', line_1: '7 Clinic Lane', city: 'Ipoh' }] } }
    }
    if (url === `/contacts/${ORG}/relationships`) {
      return { data: { data: [{ id: 'rel-1', relationship_type: 'org_person', related_contact_id: 'person-1', site_address_id: 'site-1' }] } }
    }
    if (url.startsWith('/customers/1/')) return { data: {} }
    throw new Error(`unexpected GET ${url}`)
  })
})

afterEach(cleanup)

/**
 * ADR-003B W5: the customer page shows its addresses grouped (Billing /
 * Delivery / Sites from both stores), a billing party when one is recorded,
 * and a People tab instead of Contacts.
 */
describe('CustomerDetailsPage — addresses layout', () => {
  it('shows billing addresses and Contact Master sites with the people who work there', async () => {
    renderPage()

    const card = await screen.findByTestId('customer-addresses-card')
    expect(within(card).getByRole('region', { name: 'Billing' }).textContent).toContain('12 Jalan Ampang')
    const site = await within(card).findByTestId('customer-site')
    expect(site.textContent).toContain('Main Clinic')
    expect(site.textContent).toContain('Dr Tan')
  })

  it('shows the billing party when one is recorded', async () => {
    renderPage()

    const party = await screen.findByTestId('customer-billing-party')
    expect(party.textContent).toContain('Hospital Group Finance')
  })

  it('names the people tab People', async () => {
    renderPage()

    expect(await screen.findByRole('tab', { name: /People/ })).toBeTruthy()
    expect(screen.queryByRole('tab', { name: /^Contacts$/ })).toBeNull()
  })
})
