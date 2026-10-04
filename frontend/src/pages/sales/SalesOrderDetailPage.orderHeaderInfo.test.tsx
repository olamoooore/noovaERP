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

describe('SalesOrderDetailPage — order header information (#402 / SU4)', () => {
  it('shows warehouse, currency @ rate, channel/source, approval, credit and reservation chips', async () => {
    currentOrder = makeOrder({
      warehouse_id: 3,
      warehouse: { id: 3, name: 'Main Warehouse', code: 'MW' },
      currency_code: 'MYR',
      exchange_rate: '2.500000',
      base_currency_code: 'USD',
      channel: 'pos',
      source_document_type: 'PosOrder',
      has_backorder: false,
      latest_approval_request: {
        id: 91,
        status: 'pending',
        request_number: 'APR-2026-0091',
        requested_at: '2026-01-09T10:00:00Z',
      },
      lines: [productLine({ quantity: 10, quantity_allocated: 4 })],
    })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    expect(screen.getByText('Main Warehouse')).toBeTruthy()
    expect(screen.getByText('MYR @ 2.5')).toBeTruthy()
    expect(screen.getByText('POS · POS order')).toBeTruthy()
    expect(screen.getByText('Approval pending')).toBeTruthy()
    expect(await screen.findByText('Credit OK')).toBeTruthy()
    expect(screen.getByText('Reserved 4 of 10')).toBeTruthy()
  })

  it('flags backorders and an exceeded credit limit instead of claiming the order is reserved within credit', async () => {
    currentCreditPreview = makeCreditPreview({
      approved: false,
      reason: 'Order total 200 would exceed available credit -50',
      availableCredit: -50,
    })
    currentOrder = makeOrder({
      has_backorder: true,
      lines: [
        productLine({
          quantity: 10,
          quantity_allocated: 0,
          backorder_quantity: 10,
          is_backordered: true,
        }),
      ],
    })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    expect(screen.getByText('Backordered')).toBeTruthy()
    expect(screen.queryByText(/^Reserved /)).toBeNull()
    expect(await screen.findByText('Over credit limit')).toBeTruthy()
  })

  it('renders the billing and shipping address snapshots', async () => {
    currentOrder = makeOrder({
      billing_address_snapshot: {
        address: '12 Jalan Ampang',
        city: 'Kuala Lumpur',
        state: 'WP',
        zip: '50450',
        country: 'Malaysia',
      },
      shipping_address_snapshot: {
        address: '88 Jalan Gurney',
        city: 'Kuala Lumpur',
        state: 'WP',
        zip: '51200',
        country: 'Malaysia',
      },
    })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    expect(screen.getByText('Billing Address')).toBeTruthy()
    expect(screen.getByText('Shipping Address')).toBeTruthy()
    expect(screen.getByText('12 Jalan Ampang, Kuala Lumpur, WP, 50450, Malaysia')).toBeTruthy()
    expect(screen.getByText('88 Jalan Gurney, Kuala Lumpur, WP, 51200, Malaysia')).toBeTruthy()
  })

  it('does not render order-info chips for an order without warehouse, FX, channel, approval or reservations', async () => {
    currentCreditPreview = makeCreditPreview({
      approved: true,
      reason: 'No credit limit configured (unlimited)',
      creditLimit: 0,
      currentBalance: 0,
      availableCredit: null,
    })
    currentOrder = makeOrder({
      lines: [productLine({ quantity: 10, quantity_allocated: 0 })],
    })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    expect(screen.queryByText(/^Warehouse/)).toBeNull()
    expect(screen.queryByText(/MYR @/)).toBeNull()
    expect(screen.queryByText(/Approval/)).toBeNull()
    expect(screen.queryByText(/^Reserved /)).toBeNull()
    expect(screen.queryByText('Backordered')).toBeNull()
    // Unlimited credit is reported as such, never as a concrete "within limit".
    expect(await screen.findByText('Credit unlimited')).toBeTruthy()
  })
})
