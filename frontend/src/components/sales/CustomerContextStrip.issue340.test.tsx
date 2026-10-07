// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CustomerContextStrip } from './CustomerContextStrip'
import { QuotationDocumentForm } from './QuotationDocumentForm'
import { SalesOrderDocumentForm } from './SalesOrderDocumentForm'
import { useAuthStore } from '@/store/authStore'

/**
 * Issue #340: the customer AR context strip (outstanding, overdue, credit,
 * #326 credit verdict, last invoice) shows on the quotation and sales-order
 * forms too, not only on the invoice form — and the last invoice is a link.
 */

// jsdom polyfills Radix needs at render time
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
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

vi.mock('@/lib/pricingApi', () => ({
  default: {
    resolveBatch: vi.fn(async () => ({ lines: [] })),
    sellableCurrencies: vi.fn(async () => []),
    listPriceLists: vi.fn(async () => ({ data: [] })),
  },
}))

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 30 }

const context = {
  customer_id: 1,
  currency_code: 'MYR',
  outstanding: 500,
  current: 200,
  overdue: 300,
  overdue_count: 2,
  oldest_overdue_days: 45,
  credit_limit: 400,
  credit_utilization_pct: 125,
  credit_available: -100,
  last_invoice_date: '2026-09-30',
  last_invoice_id: 9,
  last_invoice_number: 'INV-0009',
  credit_check: {
    approved: false,
    reason: 'Credit limit exceeded',
    credit_limit: 400,
    current_balance: 500,
    available_credit: -100,
    document_total: 0,
    policy: 'block',
    blocked: true,
  },
}

beforeEach(() => {
  useAuthStore.setState({
    user: { id: 1, name: 'Me', email: 'me@example.com' },
    role: 'Owner',
    permissions: [],
    token: 'test-token',
  } as any)
  window.localStorage.clear()
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/customers/1/context') return { data: context }
    if (url === '/quotations/5') {
      return { data: { id: 5, quotation_number: 'QT-0005', customer_id: 1, customer, status: 'draft', quotation_date: '2026-09-01', items: [], lines: [] } }
    }
    if (url === '/sales/orders/5') {
      return { data: { id: 5, sales_order_number: 'SO-0005', customer_id: 1, customer, status: 'draft', order_date: '2026-09-01', currency_code: 'MYR', lines: [] } }
    }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderWithProviders(node: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{node}</MemoryRouter>
    </QueryClientProvider>,
  )
}

async function expectStripShown() {
  const overdue = await screen.findByText(/2 overdue/)
  expect(overdue.closest('span')?.className).toContain('bg-red-50')
  expect((await screen.findByTestId('credit-check-chip')).textContent).toContain('posting blocked')
  await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/customers/1/context'))
}

describe('CustomerContextStrip on quotation and sales order forms (#340)', () => {
  it('shows the strip with colour states and credit chip on the quotation form', async () => {
    renderWithProviders(<QuotationDocumentForm mode="edit" documentId="5" />)
    await expectStripShown()
  })

  it('shows the strip with colour states and credit chip on the sales order form', async () => {
    renderWithProviders(<SalesOrderDocumentForm mode="edit" documentId="5" />)
    await expectStripShown()
  })

  it('links the last invoice number to the invoice', async () => {
    renderWithProviders(<CustomerContextStrip customerId={1} />)

    const link = await screen.findByRole('link', { name: /INV-0009/ })
    expect(link.getAttribute('href')).toBe('/invoices/9')
  })

  it('falls back to the last invoice date without a number', async () => {
    apiGet.mockImplementation(async () => ({
      data: { ...context, last_invoice_id: null, last_invoice_number: null },
    }))
    renderWithProviders(<CustomerContextStrip customerId={1} />)

    expect(await screen.findByText(/Last invoice/)).toBeTruthy()
    expect(screen.queryByRole('link')).toBeNull()
  })
})
