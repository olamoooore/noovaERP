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
  status: 'draft',
  customer_id: 1,
  invoice_number: 'INV-7',
  invoice_date: '2026-01-15',
  due_date: '2026-02-14',
  payment_terms: 30,
  po_number: 'PO-77',
  discount_amount: 0,
  lines: [
    {
      id: 11,
      product_id: 5,
      line_type: 'product',
      description: 'Service',
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

const failingReport = {
  ready: false,
  passed: 7,
  total: 8,
  checks: [
    { key: 'lines', label: 'Has line items', status: 'pass', detail: '1 line(s) to post.' },
    { key: 'duplicate', label: 'Likely duplicate', status: 'fail', detail: 'Likely duplicate of INV-2026-0001 — check before posting.' },
    { key: 'e_invoice', label: 'Customer TIN', status: 'warn', detail: 'Customer TIN is missing.' },
  ],
}

beforeEach(() => {
  apiPost.mockReset()
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/invoices/preflight') return { data: failingReport }
    return { data: {} }
  })
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
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

describe('CreateInvoicePage — pre-flight checklist while editing (#344)', () => {
  it('runs the dry-run checklist on the unsaved form and blocks Save & Send on a failure', async () => {
    renderPage()

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith(
        '/invoices/preflight',
        expect.objectContaining({
          invoice_id: 7,
          customer_id: 1,
          po_number: 'PO-77',
          lines: [expect.objectContaining({ product_id: 5, quantity: 3, unit_price: 10 })],
        }),
      ),
    )

    const checklist = await screen.findByTestId('preflight-checklist')
    expect(checklist.textContent).toContain('Pre-flight checklist · 7/8 ready')
    const notice = screen.getByTestId('preflight-blocked')
    expect(notice.textContent).toContain('Likely duplicate of INV-2026-0001')

    // Save & Send posts the invoice, so it is disabled; a plain save is not.
    expect((screen.getByRole('button', { name: /save & send/i }) as HTMLButtonElement).disabled).toBe(true)
    expect((screen.getByRole('button', { name: /save changes/i }) as HTMLButtonElement).disabled).toBe(false)
  })

  it('re-runs the checklist when the form changes', async () => {
    const { container } = renderPage()

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/invoices/preflight', expect.anything()))

    const qtyInput = container.querySelector('td[data-label="Qty"] input') as HTMLInputElement
    fireEvent.change(qtyInput, { target: { value: '4' } })

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith(
        '/invoices/preflight',
        expect.objectContaining({ lines: [expect.objectContaining({ quantity: 4 })] }),
      ),
    )
    // No save happened — the checklist is a dry run.
    expect(apiPost).not.toHaveBeenCalledWith('/invoices', expect.anything())
  })
})
