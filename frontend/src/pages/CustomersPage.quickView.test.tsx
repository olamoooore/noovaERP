// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import api from '@/lib/api'
import { CustomersPage } from './CustomersPage'

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

const CUSTOMER = {
  id: 7,
  code: 'CUST-007',
  type: 'company',
  name: 'Klinik Sihat',
  company: 'Klinik Sihat Sdn Bhd',
  email: 'ops@klinik-sihat.my',
  phone: '60123456789',
  mobile: '',
  website: '',
  billing_address: '12 Jalan Ampang\nKuala Lumpur',
  shipping_address: '',
  credit_limit: 5000,
  payment_terms: 30,
  total_outstanding: 1200,
  total_purchases: 9800,
  total_orders: 4,
  average_order_value: 2450,
  loyalty_points: 0,
  is_active: true,
  customer_since: '2025-01-01',
  categories: [],
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <CustomersPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mockedGet.mockReset()
  mockedGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/customers/statistics')) return { data: {} }
    if (url.startsWith('/customer-categories')) return { data: { categories: [] } }
    if (url.startsWith('/customers/7/invoices')) return { data: { data: [], current_page: 1, last_page: 1, total: 0 } }
    if (url.startsWith('/customers/7')) return { data: CUSTOMER }
    if (url.startsWith('/customers')) return { data: { data: [CUSTOMER], last_page: 1, total: 1 } }
    throw new Error(`unexpected GET ${url}`)
  })
})

afterEach(cleanup)

describe('CustomersPage quick view (#437 CU9)', () => {
  it('opens the list dialog as a read-only preview with no inline edit form', async () => {
    renderPage()

    const quickView = await screen.findAllByTitle('Quick View')
    fireEvent.click(quickView[0])

    await waitFor(() => expect(screen.getByText('Customer Details')).toBeTruthy())
    // Wait for the fetched detail to render (its code badge) before checking
    // that the preview really has no inline edit affordance.
    await waitFor(() => expect(screen.getByText('CUST-007')).toBeTruthy())

    expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull()
    expect(screen.getByTitle('Full Details')).toBeTruthy()
    expect(document.querySelector('[data-testid="customer-form"]')).toBeNull()
    expect(screen.queryByLabelText('Customer Code')).toBeNull()
  })

  it('creates through the one shared CustomerForm component', async () => {
    renderPage()

    fireEvent.click(screen.getByRole('button', { name: /add customer/i }))

    await waitFor(() => expect(screen.getByText('Add New Customer')).toBeTruthy())
    expect(screen.getByTestId('customer-form')).toBeTruthy()
    expect(screen.getByLabelText('Customer Code')).toBeTruthy()
  })
})
