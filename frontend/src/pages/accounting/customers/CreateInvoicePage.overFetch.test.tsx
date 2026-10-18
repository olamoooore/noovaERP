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

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 7 }
const product = { id: 5, name: 'Yarn', sku: 'YRN-1', price: '10' }

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices') return { data: { data: [] } }
    if (url.startsWith('/invoices/')) return { data: { data: null } }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    if (url.startsWith('/products/')) return { data: { data: product } }
    if (url.startsWith('/products')) return { data: { data: [product] } }
    return { data: { data: [] } }
  })
  resolveBatchMock.mockReset()
  resolveBatchMock.mockImplementation(async () => ({ lines: [] }))
})

afterEach(cleanup)

// #301: any list request asking for 1,000 rows is an over-fetch.
const bulkListCalls = () =>
  apiGet.mock.calls.filter(([url, config]) => {
    const perPage = Number(config?.params?.per_page ?? new URLSearchParams(String(url).split('?')[1] ?? '').get('per_page') ?? 0)
    return perPage >= 500
  })
const productListCalls = () => apiGet.mock.calls.filter(([url]) => String(url).split('?')[0] === '/products')
const customerListCalls = () => apiGet.mock.calls.filter(([url]) => String(url).split('?')[0] === '/customers')

function renderPage(path = '/invoices/new') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <CreateInvoicePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CreateInvoicePage — no bulk preloads on open (#301)', () => {
  it('opens without the 1,000-customer and 1,000-product preloads', async () => {
    renderPage()
    await screen.findByRole('button', { name: /product catalog/i })
    await new Promise((resolve) => setTimeout(resolve, 100))

    expect(bulkListCalls()).toEqual([])
    expect(customerListCalls()).toEqual([])
    expect(productListCalls()).toEqual([])
  })

  it('the catalogue searches the server, paginated, only once opened', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /product catalog/i }))
    await waitFor(() => expect(productListCalls().length).toBeGreaterThan(0))
    const [, config] = productListCalls()[0]
    expect(Number(config?.params?.page)).toBe(1)
    expect(Number(config?.params?.per_page)).toBeLessThanOrEqual(100)
    expect(bulkListCalls()).toEqual([])
  })
})
