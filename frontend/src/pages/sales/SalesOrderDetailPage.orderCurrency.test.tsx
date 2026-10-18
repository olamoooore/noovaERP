// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SalesOrderDetailPage } from './SalesOrderDetailPage'
import { formatCurrency } from '@/lib/utils'
import { useSettingsStore } from '@/store/settingsStore'

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
  // Tenant default currency differs from the order's document currency.
  useSettingsStore.setState({ currencyCode: 'MYR' })
  currentOrder = makeOrder({
    currency_code: 'USD',
    base_currency_code: 'MYR',
    exchange_rate: '4.500000',
    subtotal: 200,
    tax_amount: 16,
    total: 216,
    lines: [productLine({ unit_price: 20, tax_amount: 16, line_total: 216 })],
  })
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

// Intl output may contain non-breaking spaces; the DOM matcher normalises them.
const money = (value: number, code: string) => formatCurrency(value, code).replace(/\s+/g, ' ')

describe('SalesOrderDetailPage — amounts use the order currency (#406 / SU8)', () => {
  it('formats totals and line amounts in the document currency and shows the base equivalent', async () => {
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    // Totals card + line row are in USD, never the tenant default MYR.
    expect(screen.getByText(money(200, 'USD'))).toBeTruthy()
    expect(screen.getAllByText(money(216, 'USD')).length).toBeGreaterThanOrEqual(2)
    expect(screen.getByText(money(20, 'USD'))).toBeTruthy()
    expect(screen.queryByText(money(216, 'MYR'))).toBeNull()
    expect(screen.queryByText(money(20, 'MYR'))).toBeNull()

    // Base-currency equivalent at the order's snapshot rate (216 × 4.5).
    const base = screen.getByTestId('sales-order-base-total')
    expect(base.textContent?.replace(/\s+/g, ' ')).toContain('MYR')
    expect(base.textContent?.replace(/\s+/g, ' ')).toContain(money(972, 'MYR'))
  })

  it('omits the base equivalent when the order is in the base currency', async () => {
    currentOrder = makeOrder({ currency_code: 'MYR', base_currency_code: 'MYR', exchange_rate: 1 })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(screen.getAllByText(money(200, 'MYR')).length).toBeGreaterThan(0)
    expect(screen.queryByTestId('sales-order-base-total')).toBeNull()
  })
})
