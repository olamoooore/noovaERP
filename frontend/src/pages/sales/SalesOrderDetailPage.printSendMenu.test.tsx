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

describe('SalesOrderDetailPage — shared print and send menu (#618)', () => {
  it('offers Preview, Download PDF, Print and Choose template, and no send actions with no send path', async () => {
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)

    await openMenu()

    expect(screen.getByText('Preview')).toBeTruthy()
    expect(screen.getByText('Download PDF')).toBeTruthy()
    expect(screen.getByText('Print')).toBeTruthy()
    expect(screen.getByText('Choose template')).toBeTruthy()
    // Sales orders have no email/WhatsApp send endpoint — the actions must be
    // hidden rather than faked.
    expect(screen.queryByText('Email')).toBeNull()
    expect(screen.queryByText('WhatsApp')).toBeNull()

    expect(await screen.findByText('Using: Sales Order Standard (entity rule)')).toBeTruthy()
  })

  it('previews at the sales order PDF endpoint with the resolved template', async () => {
    const createObjectURL = vi.fn(() => 'blob:mock')
    Object.defineProperty(window.URL, 'createObjectURL', { value: createObjectURL, configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)

    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    await openMenu()
    await screen.findByText('Using: Sales Order Standard (entity rule)')

    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/sales/orders/5/pdf',
        expect.objectContaining({
          params: { template: 'tpl-so' },
          responseType: 'blob',
        }),
      )
    })
    expect(open).toHaveBeenCalled()
  })

  // #405 (SU7): the order confirmation and pro-forma invoice documents (#615
  // data sources + routes) are printable from the same menu.
  it('offers the order confirmation and pro-forma invoice as documents in the same menu', async () => {
    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    await openMenu()

    expect(screen.getByText('Sales order')).toBeTruthy()
    expect(screen.getByText('Order confirmation')).toBeTruthy()
    expect(screen.getByText('Pro-forma invoice')).toBeTruthy()
  })

  it('previews the pro-forma invoice at its own endpoint with its own resolved template', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    vi.spyOn(window, 'open').mockReturnValue(null)

    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    await openMenu()

    fireEvent.click(screen.getByText('Pro-forma invoice'))
    expect(await screen.findByText('Using: Pro Forma Standard (system default)')).toBeTruthy()

    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/sales/orders/5/proforma/pdf',
        expect.objectContaining({ params: { template: 'tpl-proforma' }, responseType: 'blob' }),
      )
    })
  })

  it('previews the order confirmation at its own endpoint with its own resolved template', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    vi.spyOn(window, 'open').mockReturnValue(null)

    renderPage()
    await screen.findByText(/Sales Order SO-2026-0005/)
    await openMenu()

    fireEvent.click(screen.getByText('Order confirmation'))
    expect(await screen.findByText('Using: Order Confirmation Standard (system default)')).toBeTruthy()

    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/sales/orders/5/confirmation/pdf',
        expect.objectContaining({ params: { template: 'tpl-confirm' }, responseType: 'blob' }),
      )
    })
  })
})
