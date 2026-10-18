// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SalesOrderDetailPage } from './SalesOrderDetailPage'
import { useAuthStore } from '@/store/authStore'

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

const order = {
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
  lines: [],
  invoices: [],
  delivery_orders: [],
}

const auditPayload = {
  data: [
    {
      id: 'audit-1',
      source: 'audit_log',
      occurred_at: '2026-01-11T09:00:00+00:00',
      user: { id: 3, name: 'Ali Admin' },
      kind: 'field',
      event: 'updated',
      field: 'unit_price',
      path: 'lines.0.unit_price',
      old_value: 100,
      new_value: 125,
      request_id: 'req-1',
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
  filters: { fields: ['unit_price'], users: [{ id: 3, name: 'Ali Admin' }] },
}

beforeEach(() => {
  localStorage.clear()
  useAuthStore.getState().setAuth(
    { id: 3, name: 'Ali Admin', email: 'ali@example.com' },
    'token',
    'tenant-1',
    'Admin',
    ['sales.view'],
  )
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: order }
    if (url === '/sales/orders/5/audit') return { data: auditPayload }
    if (url.startsWith('/tags/')) return { data: { data: [] } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

describe('SalesOrderDetailPage — Audit tab (#905 / PCU1)', () => {
  it('renders the Audit tab and loads the read-only audit feed for the order', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/sales/orders/5']}>
          <Routes>
            <Route path="/sales/orders/:id" element={<SalesOrderDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByText(/Sales Order SO-2026-0005/)

    const auditTabTrigger = await screen.findByRole('tab', { name: /audit/i })
    fireEvent.mouseDown(auditTabTrigger, { button: 0 })

    expect(await screen.findByTestId('audit-tab')).toBeTruthy()
    expect(await screen.findByText('unit_price')).toBeTruthy()
    expect(await screen.findByText('100')).toBeTruthy()
    expect(await screen.findByText('125')).toBeTruthy()
    expect(apiGet).toHaveBeenCalledWith(
      '/sales/orders/5/audit',
      expect.objectContaining({ params: expect.objectContaining({ per_page: 100 }) }),
    )
  })
})
