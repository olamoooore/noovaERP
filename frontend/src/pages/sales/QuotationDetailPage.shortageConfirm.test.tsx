// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useSearchParams } from 'react-router-dom'
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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const quotation = {
  id: 5,
  quotation_number: 'QT-2026-0005',
  status: 'sent',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  quotation_date: '2026-01-10',
  valid_until: '2026-02-10',
  subtotal: 100,
  tax_amount: 0,
  discount_amount: 0,
  total: 100,
  currency_code: 'USD',
  lines: [
    {
      id: 11,
      line_type: 'product',
      product_id: 7,
      product: { id: 7, name: 'Widget', sku: 'W-1' },
      description: 'Widget',
      quantity: 5,
      unit_price: 20,
      tax_rate: 0,
      line_total: 100,
    },
  ],
}

beforeEach(() => {
  useAuthStore.setState({ role: 'Admin', user: { id: 1, name: 'Admin', email: 'a@b.c' }, token: 't' })

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/quotations/5') return { data: quotation }
    if (url === '/warehouses') {
      return { data: { data: [{ id: 3, name: 'Main', is_default: true }] } }
    }
    if (url.startsWith('/tags')) return { data: { data: [] } }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/sales/delivery-orders/stock-check') {
      return {
        data: {
          warehouse_id: 3,
          lines: [
            {
              product_id: 7,
              requested: 5,
              on_hand: 2,
              reserved: 0,
              available: 2,
              incoming: [],
              incoming_quantity: 0,
              shortage: 3,
              sufficient: false,
            },
          ],
          summary: { line_count: 1, lines_short: 1 },
          incoming_note: 'No open purchase orders or inbound transfers found for the requested products in this warehouse.',
        },
      }
    }
    if (url === '/quotations/5/confirm') {
      return { data: { message: 'Quotation confirmed successfully', data: { ...quotation, status: 'confirmed', sales_order_id: 77 } } }
    }
    return { data: {} }
  })
})

afterEach(cleanup)

function CreateDeliveryOrderProbe() {
  const [searchParams] = useSearchParams()
  return <div data-testid="probe-delivery-order-create">sales_order_id={searchParams.get('sales_order_id')}</div>
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotations/5']}>
        <Routes>
          <Route path="/quotations/:id" element={<QuotationDetailPage />} />
          <Route path="/delivery-orders/create" element={<CreateDeliveryOrderProbe />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('QuotationDetailPage — quotation → SO shortage summary (#687 UX1)', () => {
  it('shows the shortage summary and confirms only after the explicit choice', async () => {
    renderPage()

    await screen.findAllByText(/QT-2026-0005/)
    fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }))

    await waitFor(() => expect(screen.getAllByText(/1 line short/).length).toBeGreaterThan(0))
    expect(screen.getByRole('button', { name: /backorder & confirm/i })).toBeTruthy()
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText('Widget')).toBeTruthy()

    // Preflight used the default warehouse the released SO will reserve from.
    expect(apiPost).toHaveBeenCalledWith('/sales/delivery-orders/stock-check', {
      warehouse_id: 3,
      lines: [{ product_id: 7, quantity: 5 }],
    })
    expect(apiPost.mock.calls.map((call) => call[0])).not.toContain('/quotations/5/confirm')

    fireEvent.click(screen.getByRole('button', { name: /backorder & confirm/i }))
    await waitFor(() =>
      expect(apiPost.mock.calls.map((call) => call[0])).toContain('/quotations/5/confirm'),
    )
  })

  it('confirms and opens the delivery order create page for the released sales order when delivering partially', async () => {
    renderPage()

    await screen.findAllByText(/QT-2026-0005/)
    fireEvent.click(screen.getByRole('button', { name: /^confirm$/i }))
    await waitFor(() => expect(screen.getAllByText(/1 line short/).length).toBeGreaterThan(0))

    fireEvent.click(screen.getByRole('button', { name: /deliver partially/i }))

    await waitFor(() =>
      expect(apiPost.mock.calls.map((call) => call[0])).toContain('/quotations/5/confirm'),
    )
    const probe = await screen.findByTestId('probe-delivery-order-create')
    expect(probe.textContent).toBe('sales_order_id=77')
  })
})
