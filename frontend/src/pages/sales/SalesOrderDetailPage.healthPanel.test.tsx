// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, within } from '@testing-library/react'
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

// Issue #412 (DX2): GET /sales/orders/{id}/health — a read-only dry run with
// pass / warn / fail per check, stock per line and (cost-gated) margin.
const health = {
  sales_order_id: 5,
  status: 'draft',
  ready: false,
  passed: 2,
  total: 5,
  checks: [
    { key: 'lines', label: 'Has line items', status: 'pass', detail: '1 line(s) on the order.' },
    { key: 'stock', label: 'Stock availability', status: 'warn', detail: '1 line(s) short: Widget.' },
    { key: 'credit', label: 'Credit limit', status: 'fail', detail: 'Order total 5000 would exceed available credit 4000 — confirming will be refused.' },
    { key: 'approval', label: 'Approval', status: 'warn', detail: 'Confirming will send the order for approval. Order total 5,000.00 USD is at or above the approval threshold 1,000.00.' },
    { key: 'margin', label: 'Margin', status: 'pass', detail: 'Margin 600.00 (60.0%) on revenue 1,000.00.' },
  ],
  lines: [
    {
      line_id: 11,
      product_id: 7,
      product_name: 'Widget',
      quantity: 5,
      open_quantity: 5,
      tracked: true,
      available: 3,
      shortage: 2,
      incoming_quantity: 10,
      backorder_date: '2026-10-21',
      promise_at_risk: true,
      status: 'warn',
      detail: 'Short by 2 (3 available) — backorder until 2026-10-21 (PO-0001).',
      margin_percent: 60,
    },
  ],
  credit: { approved: false, credit_limit: 10000, available_credit: 4000, exposure: 6000, document_total: 5000 },
  approval: { required: true, state: 'not_requested', threshold: 1000 },
  can_see_cost: true,
  margin: { total: { revenue: 1000, cost: 400, margin: 600, margin_percent: 60 } },
}

const order = {
  id: 5,
  sales_order_number: 'SO-2026-0005',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  order_date: '2026-10-01',
  status: 'draft',
  subtotal: 5000,
  tax_amount: 0,
  discount_amount: 0,
  total: 5000,
  created_at: '2026-10-01T00:00:00Z',
  lines: [
    {
      id: 11,
      line_type: 'product',
      product_id: 7,
      product: { id: 7, name: 'Widget', sku: 'W-1' },
      description: 'Widget line',
      quantity: 5,
      unit_price: 1000,
      tax_rate: 0,
      tax_amount: 0,
      discount_amount: 0,
      line_total: 5000,
      quantity_allocated: 0,
      quantity_delivered: 0,
      quantity_invoiced: 0,
      quantity_cancelled: 0,
    },
  ],
  invoices: [],
  delivery_orders: [],
}

let currentOrder: Record<string, unknown> = order

beforeEach(() => {
  currentOrder = order
  useAuthStore.setState({ role: 'Admin', user: { id: 1, name: 'Admin', email: 'a@b.c' }, token: 't' } as never)
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: currentOrder }
    if (url === '/sales/orders/5/health') return { data: health }
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
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Order health panel before Confirm (#412 / DX2)', () => {
  it('shows pass / warn / fail per check, stock per line with the backorder date, and margin on a draft order', async () => {
    renderAt('/sales/orders/5')
    await screen.findByText(/Sales Order SO-2026-0005/)

    const panel = await screen.findByRole('region', { name: 'Order health' })
    expect(apiGet).toHaveBeenCalledWith('/sales/orders/5/health')

    expect(within(panel).getByText('2/5 checks pass')).toBeTruthy()
    expect(within(panel).getByTestId('health-check-credit').getAttribute('data-status')).toBe('fail')
    expect(within(panel).getByTestId('health-check-stock').getAttribute('data-status')).toBe('warn')
    expect(within(panel).getByTestId('health-check-approval').getAttribute('data-status')).toBe('warn')
    expect(within(panel).getByTestId('health-check-lines').getAttribute('data-status')).toBe('pass')
    expect(within(panel).getByText(/confirming will be refused/)).toBeTruthy()
    expect(within(panel).getByText(/approval threshold 1,000.00/)).toBeTruthy()

    const stockRow = within(panel).getByTestId('health-line-11')
    expect(within(stockRow).getByText('Widget')).toBeTruthy()
    expect(within(stockRow).getByText(/Backorder until 2026-10-21/)).toBeTruthy()
    expect(within(stockRow).getByText('Promise at risk')).toBeTruthy()
    expect(within(stockRow).getByText('60.0%')).toBeTruthy()
  })

  it('is not shown once the order is confirmed', async () => {
    currentOrder = { ...order, status: 'confirmed' }
    renderAt('/sales/orders/5')
    await screen.findByText(/Sales Order SO-2026-0005/)

    expect(screen.queryByRole('region', { name: 'Order health' })).toBeNull()
    expect(apiGet).not.toHaveBeenCalledWith('/sales/orders/5/health')
  })
})
