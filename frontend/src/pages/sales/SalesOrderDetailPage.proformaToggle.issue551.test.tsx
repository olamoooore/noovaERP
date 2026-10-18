// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
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

const RESOLUTION = {
  outcome: 'RESOLVED',
  template_id: 'tpl-so',
  template_name: 'Sales Order Standard',
  rule: { scope_type: 'entity', scope_value: '3' },
  rule_label: 'entity rule',
}

const CONFIRMATION_RESOLUTION = {
  outcome: 'RESOLVED',
  template_id: 'tpl-confirm',
  template_name: 'Order Confirmation Standard',
  rule: null,
  rule_label: 'system default',
}

const PROFORMA_RESOLUTION = {
  outcome: 'RESOLVED',
  template_id: 'tpl-proforma',
  template_name: 'Pro Forma Standard',
  rule: null,
  rule_label: 'system default',
}

function makeOrder(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    sales_order_number: 'SO-2026-0005',
    customer_id: 1,
    customer: { id: 1, name: 'Klinik Ali', email: 'billing@klinik.example' },
    order_date: '2026-01-10',
    status: 'confirmed',
    subtotal: 200,
    tax_amount: 0,
    discount_amount: 0,
    total: 200,
    created_at: '2026-01-10T00:00:00Z',
    lines: [],
    invoices: [],
    delivery_orders: [],
    ...overrides,
  }
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: makeOrder() }
    if (url === '/sales/orders/5/credit-preview') {
      return { data: { approved: true, reason: 'Within credit limit', availableCredit: 8000 } }
    }
    if (url.startsWith('/documents/sales_order/resolution')) {
      return { data: { data: RESOLUTION } }
    }
    if (url.startsWith('/documents/sales_order_confirmation/resolution')) {
      return { data: { data: CONFIRMATION_RESOLUTION } }
    }
    if (url.startsWith('/documents/sales_proforma_invoice/resolution')) {
      return { data: { data: PROFORMA_RESOLUTION } }
    }
    if (url.startsWith('/template-builder/templates')) {
      return { data: { data: [{ id: 'tpl-so', name: 'Sales Order Standard' }] } }
    }
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

async function openMenu() {
  fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
  await screen.findByText('Preview')
}

// #551: Sales Settings → Sales Orders "Pro-forma invoice" toggle.
describe('SalesOrderDetailPage — pro-forma invoice toggle (#551)', () => {
  it('hides the pro-forma invoice when the tenant turned it off', async () => {
    const base = apiGet.getMockImplementation()!
    apiGet.mockImplementation(async (url: string, ...rest: unknown[]) => {
      if (url === '/settings/sales/defaults') return { data: { data: { sales_order: { proforma_enabled: false } } } }
      return base(url, ...rest)
    })

    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    await openMenu()

    expect(screen.getByText('Order confirmation')).toBeTruthy()
    expect(screen.queryByText('Pro-forma invoice')).toBeNull()
  })

  it('keeps the pro-forma invoice when the toggle is on', async () => {
    const base = apiGet.getMockImplementation()!
    apiGet.mockImplementation(async (url: string, ...rest: unknown[]) => {
      if (url === '/settings/sales/defaults') return { data: { data: { sales_order: { proforma_enabled: true } } } }
      return base(url, ...rest)
    })

    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    await openMenu()

    expect(screen.getByText('Pro-forma invoice')).toBeTruthy()
  })
})
