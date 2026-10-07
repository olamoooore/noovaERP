// @vitest-environment jsdom
// #488 (PAY-UX2): the customer page lists payments holding unapplied credit.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'
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
const mockedPut = vi.mocked(api.put)

const CUSTOMER = {
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
  useAuthStore.setState({ role: 'Accountant', permissions: ['accounting.view', 'accounting.write'] })
  mockedGet.mockReset()
  mockedPut.mockReset()
  mockedGet.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/customers/1') return { data: CUSTOMER }
    if (url === '/payments' && config?.params?.unapplied === 1 && config?.params?.customer_id === 1) {
      return {
        data: {
          data: [
            { id: 70, payment_number: 'PAY-2026-0070', payment_date: '2026-02-10', amount: '90.00', unapplied_amount: '25.00', customer_id: 1, payment_status: 'applied' },
          ],
          total: 1,
        },
      }
    }
    if (url.startsWith('/customers/1/')) return { data: {} }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

describe('CustomerDetailsPage — unapplied payments (#488)', () => {
  it('lists the payments still holding unapplied credit on the invoices tab', async () => {
    renderPage()
    fireEvent.mouseDown(await screen.findByRole('tab', { name: /invoices/i }))

    const card = await screen.findByTestId('unapplied-credits')
    await within(card).findByText('PAY-2026-0070')
    expect(within(card).getByTestId('unapplied-credits-total').textContent).toMatch(/25\.00/)
  })
})
