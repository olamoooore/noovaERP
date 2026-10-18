// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, act } from '@testing-library/react'
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

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 30, credit_limit: 1000000 }

const lastInvoice = {
  id: 7,
  invoice_number: 'INV-7',
  notes: null,
  payment_terms_snapshot: null,
  lines: [
    { id: 11, line_type: 'product', product_id: 5, description: 'Auto Widget', quantity: 2, unit_price: 10, tax_rate: 0, discount_amount: 0 },
    { id: 12, line_type: 'product', product_id: 6, description: 'Manual Widget', quantity: 1, unit_price: 66, tax_rate: 0, discount_amount: 0 },
  ],
}

const engineResult = (productLines: any[], unitPrice: number) => ({
  currency_code: 'USD',
  base_currency_code: 'USD',
  exchange_rate: 1,
  rate_date: '2026-01-01',
  rate_source: 'price_list',
  rate_stale: false,
  price_list_name: 'BASE',
  price_list_currency: 'USD',
  lines: productLines.map(() => ({
    unit_price: unitPrice,
    list_price: unitPrice,
    source: 'price_list',
    pricing_trace: { pricelist_code: 'BASE' },
  })),
})

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices') return { data: { data: [{ id: 7 }] } }
    if (url.startsWith('/invoices/')) return { data: { data: lastInvoice } }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    if (url.startsWith('/settings/sales')) return { data: { data: { approval_threshold: 10000 } } }
    return { data: { data: [] } }
  })

  resolveBatchMock.mockReset()
  resolveBatchMock.mockImplementation(async (_context: any, lines: any[]) => engineResult(lines, 99))
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

async function pickCustomerAndRepeat() {
  const customerTrigger = (await screen.findAllByRole('combobox')).find((c) =>
    c.textContent?.includes('Search customers'),
  )
  expect(customerTrigger, 'customer combobox trigger').toBeTruthy()
  fireEvent.click(customerTrigger!)
  fireEvent.click(await screen.findByText('Klinik Ali'))

  const repeat = await screen.findByRole('button', { name: /Repeat last invoice/ }, { timeout: 5000 })
  await waitFor(() => expect((repeat as HTMLButtonElement).disabled).toBe(false), { timeout: 5000 })
  fireEvent.click(repeat)
  await screen.findByText('Auto Widget')
}

function changeInvoiceDate(container: HTMLElement, value: string) {
  const dateLabel = [...container.querySelectorAll('label')].find(
    (l) => l.textContent?.trim() === 'Invoice Date',
  )
  expect(dateLabel, 'Invoice Date label').toBeTruthy()
  const dateInput = dateLabel!.parentElement!.querySelector('input[type="date"]') as HTMLInputElement
  expect(dateInput, 'Invoice Date input').toBeTruthy()
  fireEvent.change(dateInput, { target: { value } })
}

const priceInputsOf = (container: HTMLElement) =>
  [...container.querySelectorAll('td[data-label="Unit price"] input')] as HTMLInputElement[]

describe('CreateInvoicePage async pricing races (#296)', () => {
  it('keeps a hand-typed price edited while the pricing request is in flight', async () => {
    const { container } = renderPage()
    await pickCustomerAndRepeat()

    // Hold the next pricing response open so the operator can edit meanwhile.
    const pending: Array<(unitPrice: number) => void> = []
    resolveBatchMock.mockImplementation((_context: any, lines: any[]) =>
      new Promise((resolve) => {
        pending.push((unitPrice: number) => resolve(engineResult(lines, unitPrice)))
      }),
    )

    changeInvoiceDate(container, '2026-06-15')
    await waitFor(() => expect(pending).toHaveLength(1))

    // While the request is in flight, hand-type the first line's price.
    const priceInputs = priceInputsOf(container)
    fireEvent.change(priceInputs[0], { target: { value: '77' } })
    expect(priceInputs[0].value).toBe('77')

    // The stale snapshot must not clobber the manual override.
    await act(async () => {
      pending[0](99)
    })

    expect(priceInputsOf(container)[0].value).toBe('77')
  })

  it('ignores a stale pricing response that resolves after a newer request', async () => {
    const { container } = renderPage()
    await pickCustomerAndRepeat()

    const pending: Array<{ date?: string; resolve: (unitPrice: number) => void }> = []
    resolveBatchMock.mockImplementation((context: any, lines: any[]) =>
      new Promise((resolve) => {
        pending.push({ date: context?.date, resolve: (unitPrice: number) => resolve(engineResult(lines, unitPrice)) })
      }),
    )

    changeInvoiceDate(container, '2026-06-15')
    await waitFor(() => expect(pending).toHaveLength(1))
    changeInvoiceDate(container, '2026-07-15')
    await waitFor(() => expect(pending).toHaveLength(2))

    // Newer request lands first, older request lands last.
    await act(async () => {
      pending[1].resolve(55)
    })
    await waitFor(() => expect(priceInputsOf(container)[0].value).toBe('55'))

    await act(async () => {
      pending[0].resolve(99)
    })

    expect(priceInputsOf(container)[0].value).toBe('55')
  })
})
