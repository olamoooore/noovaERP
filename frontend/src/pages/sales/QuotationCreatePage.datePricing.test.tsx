// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
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
      <MemoryRouter initialEntries={['/quotations/new']}>
        <QuotationCreatePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function pickCustomerAndProduct(container: HTMLElement) {
  // Pick the customer.
  const customerTrigger = (await screen.findAllByRole('combobox')).find((c) =>
    c.textContent?.includes('Search customers'),
  )
  expect(customerTrigger, 'customer combobox trigger').toBeTruthy()
  fireEvent.click(customerTrigger!)
  fireEvent.click(await screen.findByText('Klinik Ali'))

  // Pick a product on the starter line (opens its popover; one mocked result).
  const productTrigger = (await screen.findAllByRole('combobox')).find((c) =>
    c.textContent?.includes('Search product'),
  )
  expect(productTrigger, 'product combobox trigger').toBeTruthy()
  fireEvent.click(productTrigger!)
  fireEvent.click(await screen.findByText('Yarn'))

  // The picked product's price lands in the line's unit-price cell.
  const priceInput = await waitFor(() => {
    const found = ([...container.querySelectorAll('input[type="number"]')] as HTMLInputElement[]).find(
      (i) => i.value === '10',
    )
    expect(found, 'unit price input with the catalogue price').toBeTruthy()
    return found as HTMLInputElement
  })
  return priceInput
}

function changeQuoteDate(container: HTMLElement) {
  const dateLabel = [...container.querySelectorAll('label')].find(
    (l) => l.textContent?.trim() === 'Quote Date',
  )
  expect(dateLabel, 'Quote Date label').toBeTruthy()
  const dateInput = dateLabel!.parentElement!.querySelector('input[type="date"]') as HTMLInputElement
  expect(dateInput, 'Quote Date input').toBeTruthy()
  fireEvent.change(dateInput, { target: { value: '2026-06-15' } })
}

describe('QuotationCreatePage date-effective pricing (#299 / F7)', () => {
  it('re-resolves auto-priced lines against the new quote date', async () => {
    const { container } = renderPage()
    await pickCustomerAndProduct(container)

    // Product pick may already have resolved today's price — but nothing
    // may be resolved for the new date until the date input changes.
    expect(resolveBatchMock.mock.calls.filter((c: any[]) => c[0]?.date === '2026-06-15')).toHaveLength(0)
    changeQuoteDate(container)

    await waitFor(() => {
      const withNewDate = resolveBatchMock.mock.calls.filter((c: any[]) => c[0]?.date === '2026-06-15')
      expect(withNewDate.length).toBeGreaterThan(0)
    })

    // The auto line reprices to the engine answer.
    await waitFor(() => {
      const priceInputs = ([...container.querySelectorAll('input[type="number"]')] as HTMLInputElement[]).filter(
        (i) => i.value !== '',
      )
      expect(priceInputs.some((i) => i.value === '99')).toBe(true)
    })
  })

  it('keeps a hand-typed price when the date changes', async () => {
    const { container } = renderPage()
    const priceInput = await pickCustomerAndProduct(container)

    // Hand-type a price override (price_mode becomes manual).
    fireEvent.change(priceInput, { target: { value: '77' } })
    expect(priceInput.value).toBe('77')

    changeQuoteDate(container)

    // Still re-resolved for the new date, but the manual price is untouched.
    await waitFor(() => {
      const withNewDate = resolveBatchMock.mock.calls.filter((c: any[]) => c[0]?.date === '2026-06-15')
      expect(withNewDate.length).toBeGreaterThan(0)
    })
    expect(priceInput.value).toBe('77')
  })
})
