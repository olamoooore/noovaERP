// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, within } from '@testing-library/react'
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
let chatterResponse: () => Promise<unknown>

const chatterBundle = {
  logs: [
    // Duplicates the status-history row below; the timeline must not show it twice.
    { id: 'l1', event: 'status_changed', description: 'Status changed to confirmed', created_at: '2026-01-11T09:00:00Z', actor: { id: 2, name: 'Jane Admin' } },
    { id: 'l2', event: 'updated', description: 'Customer reference updated', created_at: '2026-01-10T12:00:00Z', actor: { id: 3, name: 'Bob Clerk' } },
  ],
  messages: [
    { id: 'm1', body: 'Customer asked to hold shipment', created_at: '2026-01-12T08:00:00Z', actor: { id: 3, name: 'Bob Clerk' } },
  ],
  activities: [],
  attachments: [],
}

beforeEach(() => {
  currentOrder = makeOrder({
    status: 'cancelled',
    status_history: [
      { id: 1, from_status: 'draft', to_status: 'confirmed', actor_id: 2, actor: { id: 2, name: 'Jane Admin' }, source: 'api', reason: null, transitioned_at: '2026-01-11T09:00:00Z' },
      { id: 2, from_status: 'confirmed', to_status: 'cancelled', actor_id: 4, actor: { id: 4, name: 'Carl Manager' }, source: 'api', reason: 'Customer withdrew', transitioned_at: '2026-01-13T10:00:00Z' },
    ],
  })
  chatterResponse = async () => ({ data: { data: chatterBundle } })
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: currentOrder }
    if (url.startsWith('/chatter/')) return chatterResponse()
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

describe('SalesOrderDetailPage — inline status timeline (#404 / SU6)', () => {
  it('renders status transitions with actor, time and reason, merged with activity entries, newest first', async () => {
    renderPage()
    const timeline = await screen.findByTestId('sales-order-timeline')
    await within(timeline).findByText('Customer asked to hold shipment')

    const entries = within(timeline).getAllByTestId('timeline-entry').map((el) => el.textContent ?? '')
    expect(entries).toHaveLength(4)
    // Newest first: cancel (13th) → message (12th) → confirm (11th) → update (10th).
    expect(entries[0]).toContain('Confirmed → Cancelled')
    expect(entries[0]).toContain('Carl Manager')
    expect(entries[0]).toContain('Customer withdrew')
    expect(entries[1]).toContain('Customer asked to hold shipment')
    expect(entries[1]).toContain('Bob Clerk')
    expect(entries[2]).toContain('Draft → Confirmed')
    expect(entries[2]).toContain('Jane Admin')
    expect(entries[3]).toContain('Customer reference updated')
    // The chatter status_changed log duplicates a history row and is not repeated.
    expect(within(timeline).queryByText('Status changed to confirmed')).toBeNull()
  })

  it('still shows the status history when the activity feed fails, and says so', async () => {
    chatterResponse = async () => {
      throw new Error('boom')
    }
    renderPage()
    const timeline = await screen.findByTestId('sales-order-timeline')
    await within(timeline).findByText(/activity feed could not be loaded/i)
    expect(within(timeline).getAllByTestId('timeline-entry')).toHaveLength(2)
    expect(within(timeline).getByText('Customer withdrew')).toBeTruthy()
  })
})
