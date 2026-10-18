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

function makeCreditPreview(overrides: Record<string, unknown> = {}) {
  return {
    approved: true,
    reason: 'Within credit limit',
    creditLimit: 10000,
    currentBalance: 2000,
    availableCredit: 8000,
    orderTotal: 200,
    ...overrides,
  }
}

let currentOrder: Record<string, unknown>
let currentCreditPreview: Record<string, unknown>

beforeEach(() => {
  currentOrder = makeOrder()
  currentCreditPreview = makeCreditPreview()
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: currentOrder }
    if (url === '/sales/orders/5/credit-preview') return { data: currentCreditPreview }
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

describe('SalesOrderDetailPage — status badge + delivery/invoice chips (#399 / SU1)', () => {
  it.each([
    ['draft', 'Draft'],
    ['confirmed', 'Confirmed'],
    ['allocated', 'Allocated'],
    ['fulfilled', 'Fulfilled'],
    ['invoiced', 'Invoiced'],
    ['cancelled', 'Cancelled'],
  ])('labels a %s order as "%s" (never the raw status)', async (status, label) => {
    currentOrder = makeOrder({ status })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(await screen.findByText(label)).toBeTruthy()
    // The grey fallback with the raw lowercase status must never appear.
    expect(screen.queryByText(status)).toBeNull()
    expect((await screen.findByTestId('sales-order-status-badge')).textContent).toBe(label)
  })

  it('uses a distinct colour for every real lifecycle status', async () => {
    const classes = new Set<string>()
    for (const status of ['draft', 'confirmed', 'allocated', 'fulfilled', 'invoiced', 'cancelled']) {
      currentOrder = makeOrder({ status })
      const { unmount } = renderPage()
      const badge = await screen.findByTestId('sales-order-status-badge')
      classes.add(badge.className)
      unmount()
    }
    expect(classes.size).toBe(6)
  })

  it('shows separate delivery and invoice status chips', async () => {
    currentOrder = makeOrder({
      status: 'allocated',
      invoice_status: 'partially_invoiced',
      lines: [productLine({ quantity: 10, quantity_delivered: 4, quantity_invoiced: 4 })],
    })
    renderPage()

    expect((await screen.findByTestId('sales-order-delivery-status')).textContent).toBe('Delivery: Partially delivered')
    expect((await screen.findByTestId('sales-order-invoice-status')).textContent).toBe('Invoicing: Partially invoiced')
  })

  it('shows nothing-yet and fully-done delivery/invoice chips', async () => {
    currentOrder = makeOrder({
      status: 'confirmed',
      invoice_status: 'to_invoice',
      lines: [productLine({ quantity: 10, quantity_delivered: 0 })],
    })
    const { unmount } = renderPage()
    expect((await screen.findByTestId('sales-order-delivery-status')).textContent).toBe('Delivery: To deliver')
    expect((await screen.findByTestId('sales-order-invoice-status')).textContent).toBe('Invoicing: To invoice')
    unmount()

    currentOrder = makeOrder({
      status: 'invoiced',
      invoice_status: 'fully_invoiced',
      lines: [productLine({ quantity: 10, quantity_delivered: 10, quantity_invoiced: 10 })],
    })
    renderPage()
    expect((await screen.findByTestId('sales-order-delivery-status')).textContent).toBe('Delivery: Fully delivered')
    expect((await screen.findByTestId('sales-order-invoice-status')).textContent).toBe('Invoicing: Fully invoiced')
  })

  it('shows no delivery/invoice chips on a draft order', async () => {
    currentOrder = makeOrder({ status: 'draft', invoice_status: 'to_invoice' })
    renderPage()

    await screen.findByTestId('sales-order-status-badge')
    expect(screen.queryByTestId('sales-order-delivery-status')).toBeNull()
    expect(screen.queryByTestId('sales-order-invoice-status')).toBeNull()
  })
})
