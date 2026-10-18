// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SalesOrderDetailPage } from './SalesOrderDetailPage'
import { useAuthStore } from '@/store/authStore'

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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

function productLine() {
  return {
    id: 11,
    line_type: 'product',
    product_id: 7,
    product: { id: 7, name: 'Widget', sku: 'W-1' },
    description: 'Widget',
    quantity: 10,
    unit_price: 10,
    tax_rate: 0,
    tax_amount: 0,
    discount_amount: 0,
    line_total: 100,
    quantity_allocated: 0,
    quantity_fulfilled: 0,
    quantity_delivered: 0,
    quantity_invoiced: 0,
    quantity_cancelled: 0,
    backorder_quantity: 0,
    is_backordered: false,
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
    is_locked: false,
    subtotal: 100,
    tax_amount: 0,
    discount_amount: 0,
    total: 100,
    created_at: '2026-01-10T00:00:00Z',
    lines: [productLine()],
    invoices: [],
    delivery_orders: [],
    ...overrides,
  }
}

let currentOrder: Record<string, unknown>

beforeEach(() => {
  useAuthStore.setState({
    role: 'Admin',
    user: { id: 1, name: 'Admin', email: 'admin@test.local' },
    token: 'test-token',
    permissions: [],
  })
  currentOrder = makeOrder()
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: currentOrder }
    if (url.startsWith('/tags/')) return { data: { data: [] } }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { message: 'Order locked' } })
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

describe('SalesOrderDetailPage — lock/unlock control (#407)', () => {
  it('locks a confirmed order through the real endpoint', async () => {
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    fireEvent.click(screen.getByRole('button', { name: /^lock$/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/sales/orders/5/lock'))
  })

  it('shows the lock badge and unlocks through the real endpoint', async () => {
    currentOrder = makeOrder({ is_locked: true, locked_at: '2026-10-05T10:00:00Z' })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    expect(screen.getByTestId('sales-order-lock-badge').textContent).toContain('Locked')

    fireEvent.click(screen.getByRole('button', { name: /unlock/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/sales/orders/5/unlock'))
  })

  it('hides the action from a user without sales.write', async () => {
    useAuthStore.setState({
      role: 'Viewer',
      user: { id: 2, name: 'Viewer', email: 'viewer@test.local' },
      token: 'test-token',
      permissions: ['sales.view'],
    })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    expect(screen.queryByRole('button', { name: /^lock$/i })).toBeNull()
    expect(screen.queryByRole('button', { name: /unlock/i })).toBeNull()
  })
})
