// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
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

const { apiGet, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPut: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: apiPut,
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const draftOrder = {
  id: 5,
  sales_order_number: 'SO-2026-0005',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  order_date: '2026-09-01',
  commitment_date: '2026-09-20',
  expected_date: '2026-09-25',
  shipping_policy: 'when_all_ready',
  is_late: true,
  status: 'draft',
  subtotal: 100,
  tax_amount: 0,
  discount_amount: 0,
  total: 100,
  created_at: '2026-09-01T00:00:00Z',
  lines: [
    {
      id: 11,
      line_type: 'product',
      product_id: 7,
      product: { id: 7, name: 'Widget', sku: 'W-1' },
      description: 'Widget',
      quantity: 5,
      quantity_delivered: 0,
      quantity_cancelled: 0,
      unit_price: 20,
      tax_rate: 0,
      tax_amount: 0,
      discount_amount: 0,
      line_total: 100,
    },
  ],
  invoices: [],
  delivery_orders: [],
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: draftOrder }
    if (url.startsWith('/tags/')) return { data: { data: [] } }
    return { data: { data: [] } }
  })

  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: {} }))
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

describe('SalesOrderDetailPage — promise date, expected date and shipping policy (#408)', () => {
  it('shows the promise fields and the late badge, and saves the editable promise date/policy', async () => {
    renderPage()

    await screen.findByText(/Sales Order SO-2026-0005/)

    expect(screen.getByText('Commitment Date:')).toBeTruthy()
    expect(screen.getByText('Sep 20, 2026')).toBeTruthy()
    expect(screen.getByText('Expected Date:')).toBeTruthy()
    expect(screen.getByText('Sep 25, 2026')).toBeTruthy()
    expect(screen.getByText('Shipping Policy:')).toBeTruthy()
    expect(screen.getByText('When all products are ready')).toBeTruthy()
    expect(screen.getByText('Late')).toBeTruthy()

    // A draft order exposes the edit dialog, pre-filled with the current state.
    fireEvent.click(screen.getByRole('button', { name: /promise date/i }))

    const commitmentInput = (await screen.findByLabelText('Commitment Date')) as HTMLInputElement
    const policySelect = (await screen.findByLabelText('Shipping Policy')) as HTMLSelectElement
    expect(commitmentInput.value).toBe('2026-09-20')
    expect(policySelect.value).toBe('when_all_ready')

    fireEvent.change(commitmentInput, { target: { value: '2026-10-12' } })
    fireEvent.change(policySelect, { target: { value: 'asap' } })
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/sales/orders/5', {
        commitment_date: '2026-10-12',
        shipping_policy: 'asap',
      })
    })
  })
})
