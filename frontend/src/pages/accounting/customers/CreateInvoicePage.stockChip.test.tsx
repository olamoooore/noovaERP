// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import CreateInvoicePage from './CreateInvoicePage'

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

vi.mock('@/lib/pricingApi', () => ({
  default: {
    resolveBatch: vi.fn(async () => ({
      currency_code: 'USD',
      base_currency_code: 'USD',
      exchange_rate: 1,
      rate_date: '2026-01-01',
      price_list_name: 'BASE',
      price_list_currency: 'USD',
      lines: [],
    })),
    sellableCurrencies: vi.fn(async () => []),
    listPriceLists: vi.fn(async () => ({ data: [] })),
  },
}))

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 30 }

const invoice = {
  id: 7,
  customer_id: 1,
  invoice_number: 'INV-7',
  invoice_date: '2026-01-15',
  due_date: '2026-02-14',
  payment_terms: 30,
  discount_amount: 0,
  lines: [
    {
      id: 11,
      product_id: 5,
      line_type: 'product',
      description: 'Stocked Widget',
      quantity: 3,
      unit_price: 10,
      tax_rate: 0,
      discount_amount: 0,
      lot_number: '',
      serial_number: '',
      product_serial_id: null,
      expiry_date: '',
      product: { sku: 'A', tracking_method: 'none' },
    },
  ],
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/delivery-orders/stock-check') {
      return {
        data: { on_hand: 10, reserved: 2, available: 8, incoming: [], incoming_quantity: 0 },
      }
    }
    if (url === '/invoices/7') return { data: invoice }
    if (url.startsWith('/warehouses')) {
      return { data: { data: [{ id: 1, name: 'Main', is_default: true }] } }
    }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/invoices/7']}>
        <Routes>
          <Route path="/invoices/:id" element={<CreateInvoicePage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CreateInvoicePage — available-to-promise chip on invoice lines (#687 UX1)', () => {
  it('shows a stock chip per product line using the selected warehouse', async () => {
    renderPage()

    const chip = await screen.findByTestId('stock-availability-chip')
    expect(chip.textContent).toContain('8')
    expect(chip.textContent).toContain('On hand 10 · Reserved 2')
    expect(chip.getAttribute('data-insufficient')).toBe('false')

    await waitFor(() =>
      expect(apiGet).toHaveBeenCalledWith('/sales/delivery-orders/stock-check', {
        params: { product_id: '5', warehouse_id: '1' },
      }),
    )
  })

  it('turns the chip red when the line quantity exceeds availability', async () => {
    const { container } = renderPage()

    const chip = await screen.findByTestId('stock-availability-chip')
    expect(chip.getAttribute('data-insufficient')).toBe('false')

    const qtyInput = container.querySelector('td[data-label="Qty"] input') as HTMLInputElement
    expect(qtyInput).toBeTruthy()
    fireEvent.change(qtyInput, { target: { value: '9' } })

    await waitFor(() =>
      expect(
        screen.getByTestId('stock-availability-chip').getAttribute('data-insufficient'),
      ).toBe('true'),
    )
  })
})
