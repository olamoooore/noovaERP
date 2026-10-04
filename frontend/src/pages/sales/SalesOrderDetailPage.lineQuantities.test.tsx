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
    quantity: 5,
    unit_price: 20,
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
    ...overrides,
  }
}

function textLine(overrides: Record<string, unknown> = {}) {
  return {
    id: 21,
    line_type: 'note',
    product_id: null,
    description: 'Note text',
    quantity: 0,
    unit_price: 0,
    tax_rate: 0,
    tax_amount: 0,
    discount_amount: 0,
    line_total: 0,
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

function productRow() {
  const table = screen.getByRole('table')
  return within(table).getAllByText('Widget')[0].closest('tr') as HTMLTableRowElement
}

describe('SalesOrderDetailPage — per-line fulfilment quantities (#400 / SU2)', () => {
  it('shows ordered, reserved, delivered, invoiced, remaining and discount values, a backorder flag and a delivery progress bar per line', async () => {
    currentOrder = makeOrder({
      lines: [
        productLine({
          quantity: 10,
          quantity_allocated: 4,
          quantity_fulfilled: 6,
          quantity_delivered: 6,
          quantity_invoiced: 2,
          quantity_cancelled: 1,
          backorder_quantity: 4,
          is_backordered: true,
          discount_amount: 12.5,
        }),
      ],
    })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    const table = screen.getByRole('table')
    for (const header of [
      'Ordered',
      'Reserved',
      'Delivered Qty',
      'Invoiced Qty',
      'Remaining to Deliver',
      'Remaining to Invoice',
      'Discount',
    ]) {
      expect(within(table).getByRole('columnheader', { name: header })).toBeTruthy()
    }

    const row = productRow()
    expect(within(row).getByText('10')).toBeTruthy() // ordered
    expect(within(row).getByText('4')).toBeTruthy() // reserved
    expect(within(row).getByText('6')).toBeTruthy() // delivered
    expect(within(row).getByText('2')).toBeTruthy() // invoiced
    expect(within(row).getByText('3')).toBeTruthy() // remaining to deliver (10 - 6 - 1)
    expect(within(row).getByText('7')).toBeTruthy() // remaining to invoice (10 - 2 - 1)
    expect(within(row).getByText('$12.50')).toBeTruthy() // line discount
    expect(within(row).getByText('Cancelled 1')).toBeTruthy()

    // #379: the line is flagged as backordered with the outstanding quantity.
    expect(within(row).getByText('Backorder 4')).toBeTruthy()

    // DX1 foundation: fulfilment progress bar on the line.
    // DX1 foundation: fulfilment progress bar on the line showing delivered share.
    const progress = within(row).getByRole('progressbar')
    expect(progress.getAttribute('aria-label')).toBe('Delivered 67%')
    expect((progress.firstElementChild as HTMLElement).style.transform).toBe('translateX(-33%)')
  })

  it('renders note and section lines as text rows and only flags genuinely backordered lines', async () => {
    currentOrder = makeOrder({
      lines: [
        productLine({ id: 11, quantity: 5, quantity_delivered: 5 }),
        textLine({ id: 21, line_type: 'section', description: 'Accessories' }),
        textLine({ id: 22, line_type: 'note', description: 'Deliver before noon' }),
      ],
    })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    const sectionCell = screen.getByText('Accessories').closest('td') as HTMLTableCellElement
    expect(sectionCell.getAttribute('colspan')).toBe('16')

    const noteCell = screen.getByText('Deliver before noon').closest('td') as HTMLTableCellElement
    expect(noteCell.getAttribute('colspan')).toBe('16')

    const row = productRow()
    expect(within(row).queryByText(/^Backorder/)).toBeNull()
  })
})
