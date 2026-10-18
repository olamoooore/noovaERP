// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { DeliveryOrderDetailPage } from './DeliveryOrderDetailPage'

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

const baseDeliveryOrder = {
  id: 5,
  delivery_order_number: 'DO-2026-0005',
  status: 'draft',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali', email: 'billing@klinik.example' },
  delivery_date: '2026-02-01',
  total: 100,
  currency_code: 'MYR',
  approval_status: 'not_required',
  lines: [],
  invoices: [],
}

function mockDeliveryOrder(overrides: Record<string, unknown>) {
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/delivery-orders/5') return { data: { ...baseDeliveryOrder, ...overrides } }
    return { data: { data: [] } }
  })
}

beforeEach(() => {
  apiGet.mockReset()
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/delivery-orders/5']}>
        <Routes>
          <Route path="/delivery-orders/:id" element={<DeliveryOrderDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DeliveryOrderDetailPage — Create Invoice visibility (#459)', () => {
  it('hides Create Invoice while the delivery order is not completed (can_invoice false)', async () => {
    mockDeliveryOrder({ status: 'draft', can_invoice: false })

    renderPage()
    await screen.findAllByText(/DO-2026-0005/)

    expect(screen.queryByText('Create Invoice')).toBeNull()
  })

  it('shows Create Invoice once the server says it is billable (done / policy enabled)', async () => {
    mockDeliveryOrder({ status: 'done', can_invoice: true })

    renderPage()
    await screen.findAllByText(/DO-2026-0005/)

    expect(await screen.findByText('Create Invoice')).toBeTruthy()
  })

  it('shows Create Invoice for a draft when the bill-before-delivery policy is on', async () => {
    mockDeliveryOrder({ status: 'draft', can_invoice: true, bill_before_delivery: true })

    renderPage()
    await screen.findAllByText(/DO-2026-0005/)

    expect(await screen.findByText('Create Invoice')).toBeTruthy()
  })
})
