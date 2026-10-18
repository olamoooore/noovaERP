// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
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

const { apiGet, resolveBatchMock } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  resolveBatchMock: vi.fn(),
}))

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
    resolveBatch: resolveBatchMock,
    sellableCurrencies: vi.fn(async () => []),
    listPriceLists: vi.fn(async () => ({ data: [] })),
  },
}))

// #343 (D6): rapid line entry. Repeated and pasted lines are re-priced by the
// pricing engine (never the copied price) and unknown SKUs are reported per row.

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 30, credit_limit: 1000000 }

const lastInvoice = {
  id: 7,
  invoice_number: 'INV-7',
  notes: null,
  payment_terms_snapshot: null,
  lines: [
    { id: 11, line_type: 'product', product_id: 5, description: 'Auto Widget', quantity: 2, unit_price: 10, tax_rate: 0, discount_amount: 0 },
    { id: 12, line_type: 'product', product_id: 6, description: 'Old Gadget', quantity: 1, unit_price: 66, tax_rate: 0, discount_amount: 4 },
  ],
}

const catalogue = [
  { id: 5, sku: 'SKU-5', barcode: null, name: 'Paste Widget', price: 1 },
  { id: 50, sku: 'SKU-50', barcode: null, name: 'Other Widget', price: 1 },
]

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string, config?: any) => {
    if (url === '/invoices') return { data: { data: [{ id: 7 }] } }
    if (url.startsWith('/invoices/')) return { data: { data: lastInvoice } }
    if (url === '/products') {
      const search = String(config?.params?.search ?? '').toLowerCase()
      return { data: { data: catalogue.filter((product) => product.sku.toLowerCase().includes(search)) } }
    }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    if (url.startsWith('/settings/sales')) return { data: { data: { approval_threshold: 10000 } } }
    return { data: { data: [] } }
  })

  resolveBatchMock.mockReset()
  resolveBatchMock.mockImplementation(async (_context: any, lines: any[]) => ({
    currency_code: 'USD',
    base_currency_code: 'USD',
    exchange_rate: 1,
    rate_date: '2026-01-01',
    rate_source: 'price_list',
    rate_stale: false,
    price_list_name: 'BASE',
    price_list_currency: 'USD',
    lines: lines.map(() => ({
      unit_price: 99,
      list_price: 99,
      source: 'price_list',
      pricing_trace: { pricelist_code: 'BASE' },
    })),
  }))
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/invoices/new']}>
        <CreateInvoicePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const priceInputsOf = (container: HTMLElement) =>
  [...container.querySelectorAll('td[data-label="Unit price"] input')] as HTMLInputElement[]

async function pickCustomer() {
  const customerTrigger = (await screen.findAllByRole('combobox')).find((c) =>
    c.textContent?.includes('Search customers'),
  )
  expect(customerTrigger, 'customer combobox trigger').toBeTruthy()
  fireEvent.click(customerTrigger!)
  fireEvent.click(await screen.findByText('Klinik Ali'))
}

describe('CreateInvoicePage rapid line entry (#343 / D6)', () => {
  it('repeat last invoice re-prices every line through the engine instead of carrying the old prices', async () => {
    const { container } = renderPage()
    await pickCustomer()

    const repeat = await screen.findByRole('button', { name: /Repeat last invoice/ }, { timeout: 5000 })
    await waitFor(() => expect((repeat as HTMLButtonElement).disabled).toBe(false), { timeout: 5000 })
    fireEvent.click(repeat)
    await screen.findByText('Auto Widget')

    // The engine is asked for both repeated products, for this customer.
    await waitFor(() => expect(resolveBatchMock).toHaveBeenCalled())
    const [context, requested] = resolveBatchMock.mock.calls[resolveBatchMock.mock.calls.length - 1]
    expect(context.customer_id).toBe(1)
    expect(requested.map((line: any) => [line.product_id, line.quantity])).toEqual([[5, 2], [6, 1]])

    // Fresh engine price on screen — never the stale 10 / 66 from INV-7.
    await waitFor(() => {
      expect(priceInputsOf(container).map((input) => input.value)).toEqual(['99', '99'])
    })
  })

  it('paste adds matched SKUs at the engine price and reports unknown SKUs per row', async () => {
    const { container } = renderPage()

    fireEvent.click(await screen.findByRole('button', { name: /Paste rows/ }))
    const textarea = await screen.findByPlaceholderText(/SKU-001/)
    fireEvent.change(textarea, { target: { value: 'SKU-5\t2\t1.00\nNOPE-1\t3' } })
    fireEvent.click(screen.getByRole('button', { name: /Add rows/ }))

    // The unknown SKU is reported with its row number — not added as a free-text line.
    expect(await screen.findByText(/Row 2: NOPE-1/)).toBeTruthy()
    expect(screen.getByText(/not found/i)).toBeTruthy()

    // The matched product is priced by the engine (99), not the pasted 1.00.
    await waitFor(() => expect(resolveBatchMock).toHaveBeenCalled())
    const [, requested] = resolveBatchMock.mock.calls[resolveBatchMock.mock.calls.length - 1]
    expect(requested).toEqual([{ product_id: 5, quantity: 2 }])
    await waitFor(() => {
      expect(priceInputsOf(container).map((input) => input.value)).toEqual(['99'])
    })
    const descriptions = [...container.querySelectorAll('textarea, input')].map((el) => (el as HTMLInputElement).value)
    expect(descriptions).not.toContain('NOPE-1')
  })
})
