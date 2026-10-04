// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
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

let currentOrder: Record<string, unknown>

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
    status: 'confirmed',
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

function CreateDeliveryOrderProbe() {
  const [searchParams] = useSearchParams()
  return <div data-testid="probe-delivery-order-create">sales_order_id={searchParams.get('sales_order_id')}</div>
}

function ListProbe({ testId }: { testId: string }) {
  const [searchParams] = useSearchParams()
  return <div data-testid={testId}>sales_order_id={searchParams.get('sales_order_id')}</div>
}

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
          <Route path="/delivery-orders/create" element={<CreateDeliveryOrderProbe />} />
          <Route path="/delivery-orders" element={<ListProbe testId="probe-delivery-orders" />} />
          <Route path="/invoices" element={<ListProbe testId="probe-invoices" />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('SalesOrderDetailPage — Create Delivery Order action and fulfilment smart buttons (#375 / O6)', () => {
  it('offers Create Delivery Order on a confirmed order and opens the pre-filled DO create page for this order', async () => {
    currentOrder = makeOrder({ status: 'confirmed' })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    const action = await screen.findByRole('button', { name: /create delivery order/i })
    fireEvent.click(action)

    const probe = await screen.findByTestId('probe-delivery-order-create')
    expect(probe.textContent).toBe('sales_order_id=5')
  })

  it.each(['allocated', 'partially_delivered'])('offers Create Delivery Order on a %s order', async (status) => {
    currentOrder = makeOrder({ status, lines: [productLine({ quantity: 5, quantity_delivered: 2 })] })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(await screen.findByRole('button', { name: /create delivery order/i })).toBeTruthy()
  })

  it.each(['draft', 'cancelled'])('does not offer Create Delivery Order on a %s order', async (status) => {
    currentOrder = makeOrder({ status })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(screen.queryByRole('button', { name: /create delivery order/i })).toBeNull()
    // No fulfilment stage yet (draft) / no longer deliverable (cancelled):
    // the delivery-status smart button must not claim there is something to deliver.
    expect(screen.queryByText('To Deliver')).toBeNull()
  })

  it('shows the remaining delivery status and the invoice status on smart buttons', async () => {
    currentOrder = makeOrder({
      status: 'allocated',
      lines: [productLine({ quantity: 5, quantity_delivered: 2 })],
      delivery_orders: [
        { id: 31, delivery_order_number: 'DO-2026-0031', status: 'done' },
        { id: 32, delivery_order_number: 'DO-2026-0032', status: 'confirmed' },
      ],
      invoices: [{ id: 41, invoice_number: 'INV-2026-0041', status: 'posted' }],
    })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(await screen.findByText('To Deliver')).toBeTruthy()
    expect(await screen.findByText('2 Delivery Orders')).toBeTruthy()
    expect(await screen.findByText('Invoiced')).toBeTruthy()
    expect(await screen.findByText('1 Invoice')).toBeTruthy()

    const deliveryAction = screen.getByRole('button', { name: /create delivery order/i })
    expect(deliveryAction).toBeTruthy()
  })

  it('shows Delivered and Paid smart buttons once every line is delivered and every invoice is paid', async () => {
    currentOrder = makeOrder({
      status: 'fulfilled',
      lines: [productLine({ quantity: 5, quantity_delivered: 5 })],
      invoices: [{ id: 41, invoice_number: 'INV-2026-0041', status: 'paid' }],
    })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(await screen.findByText('Delivered')).toBeTruthy()
    expect(await screen.findByText('Paid')).toBeTruthy()
  })

  it('shows To Deliver and Not Invoiced before any delivery or invoice exists', async () => {
    currentOrder = makeOrder({ status: 'confirmed', lines: [productLine({ quantity: 5, quantity_delivered: 0 })] })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(await screen.findByText('To Deliver')).toBeTruthy()
    expect(await screen.findByText('0 Delivery Orders')).toBeTruthy()
    expect(await screen.findByText('Not Invoiced')).toBeTruthy()
    expect(await screen.findByText('0 Invoices')).toBeTruthy()
  })

  it('opens the sales-order-filtered delivery order list from the delivery smart button', async () => {
    currentOrder = makeOrder({ status: 'allocated', lines: [productLine({ quantity: 5, quantity_delivered: 2 })] })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    fireEvent.click(await screen.findByRole('button', { name: /to deliver/i }))

    const probe = await screen.findByTestId('probe-delivery-orders')
    expect(probe.textContent).toBe('sales_order_id=5')
  })

  it('opens the sales-order-filtered invoice list from the invoice smart button', async () => {
    currentOrder = makeOrder({ status: 'allocated', lines: [productLine({ quantity: 5, quantity_delivered: 2 })] })
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)
    fireEvent.click(await screen.findByRole('button', { name: /not invoiced/i }))

    const probe = await screen.findByTestId('probe-invoices')
    expect(probe.textContent).toBe('sales_order_id=5')
  })
})
