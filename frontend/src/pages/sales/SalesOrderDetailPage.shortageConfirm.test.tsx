// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useSearchParams } from 'react-router-dom'
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

function productLine(overrides: Record<string, unknown> = {}) {
  return {
    id: 11,
    line_type: 'product',
    product_id: 7,
    product: { id: 7, name: 'Widget', sku: 'W-1' },
    description: 'Widget',
    quantity: 5,
    unit_price: 20,
    tax_rate: 0,
    tax_amount: 0,
    discount_amount: 0,
    line_total: 100,
    quantity_delivered: 0,
    quantity_cancelled: 0,
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
    status: 'draft',
    warehouse_id: 3,
    subtotal: 100,
    tax_amount: 0,
    discount_amount: 0,
    total: 100,
    created_at: '2026-01-10T00:00:00Z',
    lines: [
      productLine(),
      productLine({
        id: 12,
        product_id: 8,
        product: { id: 8, name: 'Gadget', sku: 'G-1' },
        description: 'Gadget',
        quantity: 4,
        line_total: 80,
      }),
    ],
    invoices: [],
    delivery_orders: [],
    ...overrides,
  }
}

const BULK_SUFFICIENT = {
  data: {
    warehouse_id: 3,
    lines: [
      { product_id: 7, requested: 5, on_hand: 10, reserved: 2, available: 8, incoming: [], shortage: 0, sufficient: true },
      { product_id: 8, requested: 4, on_hand: 8, reserved: 0, available: 8, incoming: [], shortage: 0, sufficient: true },
    ],
    summary: { line_count: 2, lines_short: 0 },
    incoming_note: null,
  },
}

const BULK_ONE_SHORT = {
  data: {
    warehouse_id: 3,
    lines: [
      { product_id: 7, requested: 5, on_hand: 10, reserved: 2, available: 8, incoming: [], shortage: 0, sufficient: true },
      {
        product_id: 8,
        requested: 4,
        on_hand: 1,
        reserved: 0,
        available: 1,
        incoming: [],
        incoming_quantity: 0,
        shortage: 3,
        sufficient: false,
      },
    ],
    summary: { line_count: 2, lines_short: 1 },
    incoming_note: 'No open purchase orders or inbound transfers found for the requested products in this warehouse.',
  },
}

let currentOrder: Record<string, unknown>
let bulkResponse: unknown

beforeEach(() => {
  currentOrder = makeOrder()
  bulkResponse = BULK_ONE_SHORT

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: currentOrder }
    if (url.startsWith('/tags/')) return { data: { data: [] } }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/sales/delivery-orders/stock-check') return bulkResponse
    if (url === '/sales/orders/5/confirm') {
      return { data: { message: 'Order confirmed', order: { ...currentOrder, status: 'confirmed' } } }
    }
    return { data: {} }
  })
})

afterEach(cleanup)

function CreateDeliveryOrderProbe() {
  const [searchParams] = useSearchParams()
  return <div data-testid="probe-delivery-order-create">sales_order_id={searchParams.get('sales_order_id')}</div>
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/sales/orders/5']}>
        <Routes>
          <Route path="/sales/orders/:id" element={<SalesOrderDetailPage />} />
          <Route path="/delivery-orders/create" element={<CreateDeliveryOrderProbe />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function openShortageDialog() {
  renderPage()
  await screen.findByText(/Sales Order SO-2026-0005/)
  fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }))
  // The count appears in both the dialog title and its description.
  await waitFor(() => expect(screen.getAllByText(/1 line short/).length).toBeGreaterThan(0))
}

describe('SalesOrderDetailPage — available-to-promise shortage summary on confirm (#687 UX1)', () => {
  it('shows the shortage summary with the three fulfilment options before confirming', async () => {
    await openShortageDialog()

    expect(screen.getByText(/deliver partially \/ backorder \/ change warehouse/i)).toBeTruthy()
    expect(screen.getByRole('button', { name: /change warehouse/i })).toBeTruthy()
    expect(screen.getByRole('button', { name: /deliver partially/i })).toBeTruthy()
    expect(screen.getByRole('button', { name: /backorder & confirm/i })).toBeTruthy()

    // The short line is named with the requested/available figures.
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText('Gadget')).toBeTruthy()
    expect(within(dialog).getByText(/requested 4/i)).toBeTruthy()
    expect(within(dialog).getByText(/available 1/i)).toBeTruthy()

    // The preflight used the order's own lines and commitment warehouse
    // through the shared #465 bulk stock check.
    expect(apiPost).toHaveBeenCalledWith('/sales/delivery-orders/stock-check', {
      warehouse_id: 3,
      lines: [
        { product_id: 7, quantity: 5 },
        { product_id: 8, quantity: 4 },
      ],
    })
  })

  it('does not confirm when the user chooses to change the warehouse', async () => {
    await openShortageDialog()

    fireEvent.click(screen.getByRole('button', { name: /change warehouse/i }))

    await waitFor(() =>
      expect(screen.queryByRole('button', { name: /backorder & confirm/i })).toBeNull(),
    )
    expect(apiPost.mock.calls.map((call) => call[0])).not.toContain('/sales/orders/5/confirm')
  })

  it('confirms only after the explicit backorder choice', async () => {
    await openShortageDialog()

    expect(apiPost.mock.calls.map((call) => call[0])).not.toContain('/sales/orders/5/confirm')

    fireEvent.click(screen.getByRole('button', { name: /backorder & confirm/i }))

    await waitFor(() =>
      expect(apiPost.mock.calls.map((call) => call[0])).toContain('/sales/orders/5/confirm'),
    )
  })

  it('confirms and opens the delivery order create page when delivering partially', async () => {
    await openShortageDialog()

    fireEvent.click(screen.getByRole('button', { name: /deliver partially/i }))

    await waitFor(() =>
      expect(apiPost.mock.calls.map((call) => call[0])).toContain('/sales/orders/5/confirm'),
    )
    const probe = await screen.findByTestId('probe-delivery-order-create')
    expect(probe.textContent).toBe('sales_order_id=5')
  })

  it('confirms immediately without the summary when every line is available', async () => {
    bulkResponse = BULK_SUFFICIENT

    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }))

    await waitFor(() =>
      expect(apiPost.mock.calls.map((call) => call[0])).toContain('/sales/orders/5/confirm'),
    )
    expect(screen.queryByText(/1 line short/)).toBeNull()
  })

  it('surfaces a preflight failure and still confirms (advisory check, not a new block)', async () => {
    apiPost.mockImplementation(async (url: string) => {
      if (url === '/sales/delivery-orders/stock-check') throw new Error('availability service unavailable')
      if (url === '/sales/orders/5/confirm') {
        return { data: { message: 'Order confirmed', order: { ...currentOrder, status: 'confirmed' } } }
      }
      return { data: {} }
    })

    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }))

    await waitFor(() =>
      expect(apiPost.mock.calls.map((call) => call[0])).toContain('/sales/orders/5/confirm'),
    )
  })
})
