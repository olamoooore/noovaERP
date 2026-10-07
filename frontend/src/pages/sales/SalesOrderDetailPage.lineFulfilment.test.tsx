// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, within, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SalesOrderDetailPage } from './SalesOrderDetailPage'
import { QuotationDetailPage } from './QuotationDetailPage'
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

// Issue #411 (DX1): the per-line chain ordered → reserved → delivered →
// invoiced → paid comes from GET /sales/orders/{id}/fulfilment; each segment
// lists the delivery orders / invoices / payments behind it on hover.
const fulfilment = {
  sales_order_id: 5,
  sales_order_number: 'SO-2026-0005',
  lines: [
    {
      sales_order_line_id: 11,
      product_name: 'Widget',
      delivery_tracked: true,
      stuck_at: 'deliver',
      ordered: { quantity: 10, amount: 1000 },
      reserved: { quantity: 10, amount: 1000 },
      delivered: {
        quantity: 6,
        amount: 600,
        delivery_orders: [{ id: 31, delivery_order_number: 'DO-0031', status: 'done', quantity: 6 }],
      },
      invoiced: {
        quantity: 6,
        amount: 600,
        invoices: [
          { id: 41, invoice_number: 'INV-0041', status: 'sent', quantity: 6, amount: 600, paid_ratio: 0.5, paid_amount: 300 },
        ],
      },
      paid: {
        quantity: 3,
        amount: 300,
        payments: [{ id: 51, payment_number: 'PAY-0051', payment_date: '2026-10-01', invoice_id: 41, applied_amount: 300 }],
      },
    },
  ],
}

const order = {
  id: 5,
  sales_order_number: 'SO-2026-0005',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  order_date: '2026-01-10',
  status: 'confirmed',
  subtotal: 1000,
  tax_amount: 0,
  discount_amount: 0,
  total: 1000,
  created_at: '2026-01-10T00:00:00Z',
  lines: [
    {
      id: 11,
      line_type: 'product',
      product_id: 7,
      product: { id: 7, name: 'Widget', sku: 'W-1' },
      description: 'Widget line',
      quantity: 10,
      unit_price: 100,
      tax_rate: 0,
      tax_amount: 0,
      discount_amount: 0,
      line_total: 1000,
      quantity_allocated: 10,
      quantity_delivered: 6,
      quantity_invoiced: 6,
      quantity_cancelled: 0,
    },
  ],
  invoices: [],
  delivery_orders: [],
}

const quotation = {
  id: 9,
  quotation_number: 'QT-2026-0009',
  status: 'converted',
  sales_order_id: 5,
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  quotation_date: '2026-09-01',
  valid_until: '2026-10-01',
  subtotal: 1000,
  tax_amount: 0,
  discount_amount: 0,
  total: 1000,
  currency_code: 'USD',
  lines: [
    { id: 91, line_type: 'product', product_id: 7, product: { id: 7, name: 'Widget', sku: 'W-1' }, description: 'Widget', quantity: 10, unit_price: 100, tax_rate: 0, line_total: 1000 },
  ],
}

beforeEach(() => {
  useAuthStore.setState({ role: 'Admin', user: { id: 1, name: 'Admin', email: 'a@b.c' }, token: 't' } as never)
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: order }
    if (url === '/sales/orders/5/fulfilment') return { data: fulfilment }
    if (url === '/quotations/9') return { data: quotation }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderAt(path: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/sales/orders/:id" element={<SalesOrderDetailPage />} />
          <Route path="/quotations/:id" element={<QuotationDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function expectChain(scope: HTMLElement) {
  const chain = within(scope).getByRole('group', { name: 'Fulfilment chain' })
  expect(within(chain).getByLabelText('Ordered 10')).toBeTruthy()
  expect(within(chain).getByLabelText('Reserved 10 of 10')).toBeTruthy()
  expect(within(chain).getByLabelText('Delivered 6 of 10')).toBeTruthy()
  expect(within(chain).getByLabelText('Invoiced 6 of 10')).toBeTruthy()
  expect(within(chain).getByLabelText('Paid 3 of 10')).toBeTruthy()
  expect(within(scope).getByText('Stuck at delivery')).toBeTruthy()

  fireEvent.mouseEnter(within(chain).getByLabelText('Delivered 6 of 10'))
  expect(within(scope).getByRole('tooltip').textContent).toContain('DO-0031')

  fireEvent.mouseEnter(within(chain).getByLabelText('Invoiced 6 of 10'))
  expect(within(scope).getByRole('tooltip').textContent).toContain('INV-0041')

  fireEvent.mouseEnter(within(chain).getByLabelText('Paid 3 of 10'))
  expect(within(scope).getByRole('tooltip').textContent).toContain('PAY-0051')

  fireEvent.mouseLeave(within(chain).getByLabelText('Paid 3 of 10'))
  expect(within(scope).queryByRole('tooltip')).toBeNull()
}

describe('Line fulfilment chain bar (#411 / DX1)', () => {
  it('shows a segmented ordered → paid bar per order line with the documents behind each stage on hover', async () => {
    renderAt('/sales/orders/5')
    await screen.findByText(/Sales Order SO-2026-0005/)

    const row = (await screen.findByRole('group', { name: 'Fulfilment chain' })).closest('tr') as HTMLElement
    expect(within(row).getByText('Widget')).toBeTruthy()
    expectChain(row)
    expect(apiGet).toHaveBeenCalledWith('/sales/orders/5/fulfilment')
  })

  it('shows the converted order’s line chain on the quotation detail page', async () => {
    renderAt('/quotations/9')
    await screen.findAllByText(/QT-2026-0009/)

    const card = (await screen.findByRole('group', { name: 'Fulfilment chain' })).closest('[data-testid="order-fulfilment"]') as HTMLElement
    expect(card).toBeTruthy()
    expect(within(card).getByText('SO-2026-0005')).toBeTruthy()
    expectChain(card)
  })
})
