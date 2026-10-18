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

const { apiGet, apiPost, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn(), apiPut: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: apiPut,
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
  discount_amount: 0,
  lines: [
    {
      id: 11,
      product_id: 5,
      line_type: 'product',
      description: 'Financial service',
      quantity: 1,
      unit_price: 100,
      tax_rate: 9,
      tax_rule_id: 21,
      tax_treatment: 'standard_rated',
      discount_amount: 0,
      lot_number: '',
      serial_number: '',
      product_serial_id: null,
      expiry_date: '',
      product: { sku: 'A', tracking_method: 'none' },
    },
  ],
}

// GET /sales/tax-preview options: the tenant's own output tax codes.
const taxOptions = [
  { id: 21, code: 'SG-GST-9', name: 'GST 9%', rate: 9, treatment: 'standard_rated' },
  { id: 23, code: 'SG-GST-EXEMPT', name: 'Exempt supplies', rate: 0, treatment: 'exempt' },
]

beforeEach(() => {
  apiPost.mockReset()
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/invoices/preflight') return { data: { ready: true, passed: 1, total: 1, checks: [] } }
    return { data: {} }
  })
  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: { id: 7 } }))
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/7') return { data: invoice }
    if (url === '/sales/tax-preview') return { data: { data: { options: taxOptions } } }
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

describe('CreateInvoicePage — line tax code / treatment (#310)', () => {
  it('offers the tax codes from data plus the missing treatments and sends the picked exempt code on save', async () => {
    renderPage()

    const optionLabels = () => Array.from(screen.getByLabelText('Tax code').querySelectorAll('option')).map((option) => option.textContent)
    // Labels come from the tax-code data (rules) plus the treatments no rule declares.
    await waitFor(() => expect(optionLabels()).toEqual(['Auto', 'GST 9% · Standard-rated', 'Exempt supplies · Exempt', 'Zero-rated', 'Out-of-scope']))
    const select = screen.getByLabelText('Tax code') as HTMLSelectElement
    // A saved standard-rated line is left to the server (no pinned code).
    expect(select.value).toBe('')

    fireEvent.change(select, { target: { value: 'rule:23' } })
    expect(select.value).toBe('rule:23')

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() =>
      expect(apiPut).toHaveBeenCalledWith(
        '/invoices/7',
        expect.objectContaining({
          lines: [expect.objectContaining({ product_id: 5, tax_rate: 0, tax_rule_id: 23 })],
        }),
      ),
    )
  })

  it('sends an out-of-scope treatment as tax_treatment with a 0% rate', async () => {
    renderPage()

    await waitFor(() => expect(screen.getByLabelText('Tax code').querySelector('option[value="rule:23"]')).not.toBeNull())
    const select = screen.getByLabelText('Tax code') as HTMLSelectElement
    fireEvent.change(select, { target: { value: 'treatment:out_of_scope' } })

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(apiPut).toHaveBeenCalled())
    const payload = apiPut.mock.calls[0][1] as { lines: Array<Record<string, unknown>> }
    expect(payload.lines[0]).toMatchObject({ tax_rate: 0, tax_treatment: 'out_of_scope' })
    expect(payload.lines[0]).not.toHaveProperty('tax_rule_id')
  })
})

