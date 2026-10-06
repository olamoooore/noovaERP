// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SalesOrderDetailPage } from './SalesOrderDetailPage'
import { formatCurrency } from '@/lib/utils'

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

const manyInvoices = [
  { id: 41, invoice_number: 'INV-41', status: 'paid', total: 100, balance: 0, currency_code: 'USD' },
  { id: 42, invoice_number: 'INV-42', status: 'partial', total: 100, balance: 60, currency_code: 'USD' },
  { id: 43, invoice_number: 'INV-43', status: 'draft', total: 40, balance: 40, currency_code: 'USD' },
  { id: 44, invoice_number: 'INV-44', status: 'cancelled', total: 10, balance: 0, currency_code: 'USD' },
]
const manyDeliveries = [
  { id: 31, delivery_order_number: 'DO-31', status: 'done' },
  { id: 32, delivery_order_number: 'DO-32', status: 'confirmed' },
  { id: 33, delivery_order_number: 'DO-33', status: 'cancelled' },
]

describe('SalesOrderDetailPage — linked-document smart buttons (#403 / SU5)', () => {
  it('summarises invoices and deliveries with counts and state instead of one chip per document', async () => {
    currentOrder = makeOrder({ status: 'allocated', currency_code: 'USD', invoices: manyInvoices, delivery_orders: manyDeliveries })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    const invoiceButton = screen.getByTestId('smart-button-invoices')
    // Cancelled invoices are not counted as live invoices.
    expect(within(invoiceButton).getByText('3 Invoices')).toBeTruthy()
    const invoiceSummary = within(invoiceButton).getByTestId('smart-button-summary').textContent ?? ''
    expect(invoiceSummary).toContain('1 unpaid')
    expect(invoiceSummary).toContain('1 draft')
    expect(invoiceSummary.replace(/\s+/g, ' ')).toContain(formatCurrency(60, 'USD').replace(/\s+/g, ' ') + ' due')

    const deliveryButton = screen.getByTestId('smart-button-deliveries')
    expect(within(deliveryButton).getByText('2 Delivery Orders')).toBeTruthy()
    expect(within(deliveryButton).getByTestId('smart-button-summary').textContent).toContain('1 pending')

    // No per-document chip row that overflows with many invoices.
    for (const number of ['INV-41', 'INV-42', 'INV-43', 'DO-31', 'DO-32']) {
      expect(screen.queryByText(number)).toBeNull()
    }
    expect(screen.getByTestId('sales-order-smart-buttons').className).toContain('flex-wrap')
  })

  it('keeps the linked invoices reachable on a cancelled order', async () => {
    currentOrder = makeOrder({ status: 'cancelled', invoices: [manyInvoices[0]], delivery_orders: [] })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    const invoiceButton = screen.getByTestId('smart-button-invoices')
    expect(within(invoiceButton).getByText('1 Invoice')).toBeTruthy()
    expect(screen.queryByTestId('smart-button-deliveries')).toBeNull()
  })
})
