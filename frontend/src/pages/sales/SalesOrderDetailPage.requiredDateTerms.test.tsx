// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SalesOrderDetailPage } from './SalesOrderDetailPage'

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
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
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

function productLine(overrides: Record<string, unknown> = {}) {
  return {
    id: 11,
    line_type: 'product',
    product_id: 7,
    product: { id: 7, name: 'Widget', sku: 'W-1' },
    description: 'Widget',
    quantity: 10,
    unit_price: 20,
    tax_rate: 0,
    tax_amount: 0,
    discount_amount: 0,
    line_total: 200,
    quantity_allocated: 4,
    quantity_fulfilled: 0,
    quantity_delivered: 0,
    quantity_invoiced: 0,
    quantity_cancelled: 0,
    backorder_quantity: 0,
    is_backordered: false,
    ...overrides,
  }
}

function makeOrder(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    sales_order_number: 'SO-2026-0005',
    customer_id: 1,
    customer: { id: 1, name: 'Klinik Ali' },
    order_date: '2026-01-10',
    status: 'confirmed',
    subtotal: 200,
    tax_amount: 0,
    discount_amount: 0,
    total: 200,
    created_at: '2026-01-10T00:00:00Z',
    lines: [productLine()],
    invoices: [],
    delivery_orders: [],
    ...overrides,
  }
}

let currentOrder: Record<string, unknown>

beforeEach(() => {
  currentOrder = makeOrder()
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: currentOrder }
    if (url.startsWith('/tags/')) return { data: { data: [] } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/sales/orders/5']}>
        <Routes>
          <Route path="/sales/orders/:id" element={<SalesOrderDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('SalesOrderDetailPage — required date and terms read real fields (#401 / SU3)', () => {
  it('shows the order required_date (and the commitment date) in Order Info', async () => {
    currentOrder = makeOrder({
      // Serialised exactly as the API returns a date-cast column.
      required_date: '2026-09-30T00:00:00.000000Z',
      commitment_date: '2026-09-20T00:00:00.000000Z',
    })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(screen.getByText('Required Date:')).toBeTruthy()
    expect(screen.getByText('Sep 30, 2026')).toBeTruthy()
    expect(screen.getByText('Commitment Date:')).toBeTruthy()
    expect(screen.getByText('Sep 20, 2026')).toBeTruthy()
  })

  it('shows a dash when the order has no required date', async () => {
    currentOrder = makeOrder({ required_date: null })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    const label = screen.getByText('Required Date:')
    expect(label.nextElementSibling?.textContent).toBe('-')
  })

  it('does not render a Terms card — sales orders carry no terms field', async () => {
    // Even if a stray key appeared in the payload, the page must not read a
    // field the Sales Order model does not have.
    currentOrder = makeOrder({ notes: 'Deliver to back door', terms: 'Net 30' })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(screen.getByText('Deliver to back door')).toBeTruthy()
    expect(screen.queryByText('Terms')).toBeNull()
    expect(screen.queryByText('Net 30')).toBeNull()
  })
})
