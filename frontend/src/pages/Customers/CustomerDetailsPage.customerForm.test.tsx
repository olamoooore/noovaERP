// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
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
  mockedGet.mockReset()
  mockedPut.mockReset()
  mockedPut.mockResolvedValue({ data: CUSTOMER })
  mockedGet.mockImplementation(async (url: string) => {
    if (url === '/customers/1') return { data: CUSTOMER }
    if (url.startsWith('/customers/1/')) return { data: {} }
    throw new Error(`unexpected GET ${url}`)
  })
})

afterEach(cleanup)

describe('CustomerDetailsPage edit dialog (#437 CU9)', () => {
  it('edits through the same shared CustomerForm the list uses', async () => {
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))

    await waitFor(() => expect(screen.getByTestId('customer-form')).toBeTruthy())
    expect(screen.getByLabelText('Customer Code')).toBeTruthy()
    expect(screen.getByLabelText(/Name \*/)).toBeTruthy()
  })

  it('keeps cash-only 0/0 and persists the structured billing address on save', async () => {
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
    await waitFor(() => expect(screen.getByTestId('customer-form')).toBeTruthy())

    fireEvent.mouseDown(screen.getByRole('tab', { name: /financial/i }))
    await waitFor(() => expect(screen.getByLabelText('Credit Limit')).toBeTruthy())

    fireEvent.change(screen.getByLabelText('Credit Limit'), { target: { value: '0' } })
    fireEvent.change(screen.getByLabelText('Payment Terms (days)'), { target: { value: '0' } })
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(mockedPut).toHaveBeenCalled())

    const [url, body] = mockedPut.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/customers/1')
    expect(body.credit_limit).toBe(0)
    expect(body.payment_terms).toBe(0)
    expect(body.billing_address).toContain('12 Jalan Ampang')
    expect(body.billing_address).toContain('50450')
    // PUT /customers/{id} only persists the legacy billing_address column.
    expect('addresses' in body).toBe(false)
  })
})
