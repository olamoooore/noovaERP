// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, act } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import QuotationCreatePage from './QuotationCreatePage'

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

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 30 }
const product = { id: 5, name: 'Yarn', sku: 'YRN-1', price: '10', tax_rate: 0 }

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
    if (url.startsWith('/products/')) return { data: { data: product } }
    if (url.startsWith('/products')) return { data: { data: [product] } }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
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
      <MemoryRouter initialEntries={['/quotations/new']}>
        <QuotationCreatePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function pickCustomerAndProduct(container: HTMLElement) {
  const customerTrigger = (await screen.findAllByRole('combobox')).find((c) =>
    c.textContent?.includes('Search customers'),
  )
  expect(customerTrigger, 'customer combobox trigger').toBeTruthy()
  fireEvent.click(customerTrigger!)
  fireEvent.click(await screen.findByText('Klinik Ali'))

  const productTrigger = (await screen.findAllByRole('combobox')).find((c) =>
    c.textContent?.includes('Search product'),
  )
  expect(productTrigger, 'product combobox trigger').toBeTruthy()
  fireEvent.click(productTrigger!)
  fireEvent.click(await screen.findByText('Yarn'))

  const priceInput = await waitFor(() => {
    const found = ([...container.querySelectorAll('input[type="number"]')] as HTMLInputElement[]).find(
      (i) => i.value === '10',
    )
    expect(found, 'unit price input with the catalogue price').toBeTruthy()
    return found as HTMLInputElement
  })
  return priceInput
}

function changeQuoteDate(container: HTMLElement, value: string) {
  const dateLabel = [...container.querySelectorAll('label')].find(
    (l) => l.textContent?.trim() === 'Quote Date',
  )
  expect(dateLabel, 'Quote Date label').toBeTruthy()
  const dateInput = dateLabel!.parentElement!.querySelector('input[type="date"]') as HTMLInputElement
  expect(dateInput, 'Quote Date input').toBeTruthy()
  fireEvent.change(dateInput, { target: { value } })
}

describe('QuotationCreatePage async pricing races (#296)', () => {
  it('keeps a hand-typed price edited while the product-pick pricing request is in flight', async () => {
    const { container } = renderPage()

    // Hold the product-pick pricing response open so the operator can edit.
    const pending: Array<(unitPrice: number) => void> = []
    resolveBatchMock.mockImplementation((_context: any, lines: any[]) =>
      new Promise((resolve) => {
        pending.push((unitPrice: number) => resolve(engineResult(lines, unitPrice)))
      }),
    )

    const priceInput = await pickCustomerAndProduct(container)
    await waitFor(() => expect(pending).toHaveLength(1))

    // While the request is in flight, hand-type a price override.
    fireEvent.change(priceInput, { target: { value: '77' } })
    expect(priceInput.value).toBe('77')

    // The stale product-pick response must not clobber the manual override.
    await act(async () => {
      pending[0](99)
    })

    expect(priceInput.value).toBe('77')
  })

  it('ignores a stale quote-date response that resolves after a newer request', async () => {
    const { container } = renderPage()
    const priceInput = await pickCustomerAndProduct(container)
    await waitFor(() => expect(priceInput.value).toBe('99'))

    const pending: Array<(unitPrice: number) => void> = []
    resolveBatchMock.mockImplementation((_context: any, lines: any[]) =>
      new Promise((resolve) => {
        pending.push((unitPrice: number) => resolve(engineResult(lines, unitPrice)))
      }),
    )

    changeQuoteDate(container, '2026-06-15')
    await waitFor(() => expect(pending).toHaveLength(1))
    changeQuoteDate(container, '2026-07-15')
    await waitFor(() => expect(pending).toHaveLength(2))

    // Newer request lands first, older request lands last.
    await act(async () => {
      pending[1](55)
    })
    await waitFor(() => expect(priceInput.value).toBe('55'))

    await act(async () => {
      pending[0](99)
    })

    expect(priceInput.value).toBe('55')
  })
})
