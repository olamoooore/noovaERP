// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
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

const { apiGet, apiPost, resolveBatchMock } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  resolveBatchMock: vi.fn(),
}))

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
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    if (url.startsWith('/products/')) return { data: { data: product } }
    if (url.startsWith('/products')) return { data: { data: [product] } }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: { data: { id: 99 } } }))

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
    lines: lines.map(() => ({ unit_price: 10, list_price: 10, source: 'price_list' })),
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

describe('QuotationCreatePage — commitment date + shipping policy (#408)', () => {
  it('edits the commitment date and policy and sends them on create', async () => {
    renderPage()

    // Pick customer + product on the starter line (same flow as the date test).
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

    // Issue #408 controls sit on the header strip.
    const commitment = (await screen.findByLabelText('Commitment Date')) as HTMLInputElement
    const policy = (await screen.findByLabelText('Shipping Policy')) as HTMLSelectElement
    fireEvent.change(commitment, { target: { value: '2026-11-20' } })
    fireEvent.change(policy, { target: { value: 'when_all_ready' } })

    fireEvent.click(screen.getByRole('button', { name: /create quotation/i }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledTimes(1)
    })

    const [url, payload] = apiPost.mock.calls[0]
    expect(url).toBe('/quotations')
    expect(payload.commitment_date).toBe('2026-11-20')
    expect(payload.shipping_policy).toBe('when_all_ready')
  })
})
