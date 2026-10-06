// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
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
const { mockNavigate } = vi.hoisted(() => ({ mockNavigate: vi.fn() }))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, useNavigate: () => mockNavigate }
})

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
let creditPreview: Record<string, unknown>
let recurrences: Record<string, unknown>[]

beforeEach(() => {
  useAuthStore.setState({
    role: 'Admin',
    user: { id: 1, name: 'Admin', email: 'admin@test.local' },
    token: 'test-token',
    permissions: [],
  })
  currentOrder = makeOrder()
  creditPreview = {
    approved: true,
    reason: 'Within credit limit',
    creditLimit: 1000,
    currentBalance: 0,
    availableCredit: 1000,
    orderTotal: 100,
  }
  recurrences = []
  mockNavigate.mockReset()
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: currentOrder }
    if (url === '/sales/orders/5/credit-preview') return { data: creditPreview }
    if (url === '/sales/recurrences') return { data: { data: recurrences } }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { message: 'ok' } })
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

function asViewer() {
  useAuthStore.setState({
    role: 'Viewer',
    user: { id: 2, name: 'Viewer', email: 'viewer@test.local' },
    token: 'test-token',
    permissions: ['sales.view'],
  })
}

const invoicedOrder = () =>
  makeOrder({
    status: 'invoiced',
    invoice_status: 'fully_invoiced',
    invoices: [
      { id: 21, invoice_number: 'INV-0021', status: 'posted', total: 60, balance: 60 },
      { id: 22, invoice_number: 'INV-0022', status: 'posted', total: 40, balance: 40 },
    ],
  })

describe('SalesOrderDetailPage — credit note from order (#376 / #154)', () => {
  it('creates a credit note for the chosen invoice through POST /sales/orders/{id}/credit-note', async () => {
    currentOrder = invoicedOrder()
    apiPost.mockResolvedValue({ data: { message: 'Credit note draft created', credit_note: { id: 77, credit_note_number: 'CN-0077' } } })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    fireEvent.click(screen.getByRole('button', { name: /credit note/i }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/invoice to credit/i), { target: { value: '22' } })
    fireEvent.change(within(dialog).getByLabelText(/reason/i), { target: { value: 'Damaged goods' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /create credit note/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/sales/orders/5/credit-note', { invoice_id: 22, reason: 'Damaged goods' }),
    )
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/accounting/customers/credit-notes/77'))
  })

  it('offers no credit note before the order is invoiced', async () => {
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(screen.queryByRole('button', { name: /credit note/i })).toBeNull()
  })

  it('hides the credit note action from a user without sales.write', async () => {
    asViewer()
    currentOrder = invoicedOrder()
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    expect(screen.queryByRole('button', { name: /credit note/i })).toBeNull()
  })
})

describe('SalesOrderDetailPage — bill by delivered quantity (#376)', () => {
  it('turns bill-by-delivered on through POST /sales/orders/{id}/bill-by-delivered', async () => {
    apiPost.mockResolvedValue({ data: { message: 'Bill-by-delivered setting updated', bill_by_delivered_qty: true } })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    const toggle = await screen.findByRole('switch', { name: /bill by delivered quantity/i })
    expect(toggle.getAttribute('aria-checked')).toBe('false')
    fireEvent.click(toggle)

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/sales/orders/5/bill-by-delivered', { bill_by_delivered_qty: true }),
    )
  })

  it('locks the setting once the order has been invoiced', async () => {
    currentOrder = invoicedOrder()
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    const toggle = await screen.findByRole('switch', { name: /bill by delivered quantity/i })
    expect((toggle as HTMLButtonElement).disabled).toBe(true)
    fireEvent.click(toggle)
    expect(apiPost).not.toHaveBeenCalledWith('/sales/orders/5/bill-by-delivered', expect.anything())
  })

  it('is read-only for a user without sales.write', async () => {
    asViewer()
    currentOrder = makeOrder({ bill_by_delivered_qty: true })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    const toggle = await screen.findByRole('switch', { name: /bill by delivered quantity/i })
    expect(toggle.getAttribute('aria-checked')).toBe('true')
    expect((toggle as HTMLButtonElement).disabled).toBe(true)
  })
})

describe('SalesOrderDetailPage — recurring orders (#376 / #151)', () => {
  it('creates a recurrence through POST /sales/orders/{salesOrder}/recurrence', async () => {
    apiPost.mockResolvedValue({ status: 201, data: { message: 'Recurrence created successfully', data: { id: 9 } } })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    fireEvent.click(await screen.findByRole('button', { name: /make recurring/i }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/interval/i), { target: { value: 'quarterly' } })
    fireEvent.change(within(dialog).getByLabelText(/start date/i), { target: { value: '2026-11-01' } })
    fireEvent.change(within(dialog).getByLabelText(/number of cycles/i), { target: { value: '4' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /create schedule/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/sales/orders/5/recurrence', {
        interval: 'quarterly',
        start_date: '2026-11-01',
        end_date: null,
        max_cycles: 4,
      }),
    )
  })

  it("lists this order's schedules and pauses one through POST /sales/recurrences/{id}/pause", async () => {
    recurrences = [
      {
        id: 9,
        sales_order_id: 5,
        interval: 'monthly',
        status: 'active',
        start_date: '2026-11-01',
        next_run_date: '2026-12-01',
        cycles_generated: 1,
        max_cycles: null,
      },
    ]
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    await screen.findByTestId('sales-order-recurrence-9')
    expect(apiGet).toHaveBeenCalledWith('/sales/recurrences', { params: { sales_order_id: '5' } })
    fireEvent.click(within(screen.getByTestId('sales-order-recurrence-9')).getByRole('button', { name: /pause/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/sales/recurrences/9/pause'))
  })

  it('resumes and cancels a paused schedule through their routes', async () => {
    recurrences = [
      { id: 9, sales_order_id: 5, interval: 'monthly', status: 'paused', start_date: '2026-11-01', next_run_date: '2026-12-01', cycles_generated: 1 },
    ]
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    const row = await screen.findByTestId('sales-order-recurrence-9')
    fireEvent.click(within(row).getByRole('button', { name: /resume/i }))
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/sales/recurrences/9/resume'))

    fireEvent.click(within(row).getByRole('button', { name: /^cancel schedule$/i }))
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/sales/recurrences/9/cancel'))
  })

  it('hides recurrence writes from a user without sales.write', async () => {
    asViewer()
    recurrences = [
      { id: 9, sales_order_id: 5, interval: 'monthly', status: 'active', start_date: '2026-11-01', next_run_date: '2026-12-01', cycles_generated: 0 },
    ]
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    const row = await screen.findByTestId('sales-order-recurrence-9')
    expect(screen.queryByRole('button', { name: /make recurring/i })).toBeNull()
    expect(within(row).queryByRole('button', { name: /pause/i })).toBeNull()
  })
})

describe('SalesOrderDetailPage — credit preview before Confirm (#376)', () => {
  it('shows the credit position and holds the confirm when the order is over the credit limit', async () => {
    currentOrder = makeOrder({ status: 'draft' })
    creditPreview = {
      approved: false,
      reason: 'Credit limit exceeded',
      creditLimit: 500,
      currentBalance: 450,
      availableCredit: 50,
      orderTotal: 100,
    }
    apiPost.mockResolvedValue({ data: { message: 'Order confirmed' } })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/credit limit exceeded/i)).toBeTruthy()
    expect(within(dialog).getByTestId('credit-gate-available').textContent).toMatch(/50/)
    expect(apiPost).not.toHaveBeenCalledWith('/sales/orders/5/confirm')

    fireEvent.click(within(dialog).getByRole('button', { name: /confirm anyway/i }))
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/sales/orders/5/confirm'))
  })

  it('re-reads the credit preview at Confirm and confirms straight away when within limit', async () => {
    currentOrder = makeOrder({ status: 'draft' })
    apiPost.mockResolvedValue({ data: { message: 'Order confirmed' } })
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    await screen.findByText(/credit ok/i)
    const previewCalls = () => apiGet.mock.calls.filter(([url]) => url === '/sales/orders/5/credit-preview').length
    const before = previewCalls()

    fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/sales/orders/5/confirm'))
    expect(previewCalls()).toBeGreaterThan(before)
  })
})
