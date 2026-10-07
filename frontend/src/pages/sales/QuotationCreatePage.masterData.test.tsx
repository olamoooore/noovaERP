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


// The real customer combobox searches the server through a Radix popover; the
// behaviour under test is what the form does once a customer is picked, so the
// picker is a pair of buttons here.
vi.mock('@/components/ui/CustomerSearchCombobox', () => ({
  CustomerSearchCombobox: ({ onValueChange }: { onValueChange: (id: string, c?: any) => void }) => (
    <div>
      <button type="button" onClick={() => onValueChange('1', { id: '1', name: 'Klinik Ali', code: 'C-ALI' })}>pick-ali</button>
      <button type="button" onClick={() => onValueChange('2', { id: '2', name: 'Hospital Bee', code: 'C-BEE' })}>pick-bee</button>
    </div>
  ),
}))

// Issue #321 (Q13): the quotation's Credit Terms, Salesperson come from master
// data (payment-terms master, tenant users) and the free-text Validity that
// could contradict Valid Until is gone (derived from it instead).
const ali = { id: 1, name: 'Klinik Ali', code: 'C-ALI', payment_terms: 30, customer_contacts: [], addresses: [] }
const bee = { id: 2, name: 'Hospital Bee', code: 'C-BEE', payment_terms: 45, customer_contacts: [], addresses: [] }
const product = { id: 5, name: 'Yarn', sku: 'YRN-1', price: '10', tax_rate: 0 }
const paymentTerms = [
  { id: 7, name: 'Net 45', code: 'NET45', type: 'net_days', days: 45, is_active: true },
  { id: 8, name: 'Net 60', code: 'NET60', type: 'net_days', days: 60, is_active: true },
]
const tenantUsers = [
  { id: 3, name: 'Aisha Rahman', email: 'aisha@example.com' },
  { id: 4, name: 'Ben Tan', email: 'ben@example.com' },
]

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/customers/1') return { data: ali }
    if (url === '/customers/2') return { data: bee }
    if (url.startsWith('/customers')) return { data: { data: [ali, bee] } }
    if (url.startsWith('/products/')) return { data: { data: product } }
    if (url.startsWith('/products')) return { data: { data: [product] } }
    // The payment-terms index returns a bare array.
    if (url === '/accounting/payment-terms') return { data: paymentTerms }
    if (url === '/tenant-users') return { data: { data: tenantUsers } }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: { data: { id: 99 } } }))

  resolveBatchMock.mockReset()
  resolveBatchMock.mockImplementation(async (_context: any, lines: any[]) => ({
    currency_code: 'MYR',
    base_currency_code: 'MYR',
    exchange_rate: 1,
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

async function addProductLine() {
  const productTrigger = (await screen.findAllByRole('combobox')).find((c) =>
    c.textContent?.includes('Search product'),
  )
  fireEvent.click(productTrigger!)
  fireEvent.click(await screen.findByText('Yarn'))
}

async function submitAndGetPayload() {
  fireEvent.click(screen.getByRole('button', { name: /create quotation/i, hidden: true }))
  await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
  return apiPost.mock.calls[0][1]
}

describe('QuotationCreatePage — master data instead of free text (#321)', () => {
  it('picks the payment term from the master and the salesperson from users, and derives validity from Valid Until', async () => {
    renderPage()
    fireEvent.click(await screen.findByText('pick-ali'))
    await addProductLine()
    fireEvent.click(screen.getByTitle('References, contacts, addresses, logistics and terms'))

    const term = (await screen.findByLabelText('Payment Terms')) as HTMLSelectElement
    expect(term.tagName).toBe('SELECT')
    await screen.findByRole('option', { name: 'Net 60', hidden: true })
    fireEvent.change(term, { target: { value: '8' } })

    const salesperson = screen.getByLabelText('Salesperson') as HTMLSelectElement
    expect(salesperson.tagName).toBe('SELECT')
    await screen.findByRole('option', { name: 'Aisha Rahman', hidden: true })
    fireEvent.change(salesperson, { target: { value: 'Aisha Rahman' } })

    // The free-text Validity that could contradict Valid Until is gone.
    expect(screen.queryByPlaceholderText('e.g. 30 days')).toBeNull()

    const payload = await submitAndGetPayload()
    expect(payload.payment_term_id).toBe(8)
    expect(payload.credit_terms).toBe('Net 60')
    expect(payload.salesperson).toBe('Aisha Rahman')
    // Valid Until defaults to quote date + 30 days → validity follows it.
    expect(payload.validity).toBe('30 days')
  })

  it("defaults the payment term to the master term matching the customer's day count", async () => {
    renderPage()
    fireEvent.click(await screen.findByText('pick-bee'))
    await addProductLine()
    fireEvent.click(screen.getByTitle('References, contacts, addresses, logistics and terms'))

    const term = (await screen.findByLabelText('Payment Terms')) as HTMLSelectElement
    await waitFor(() => expect(term.value).toBe('7'))

    const payload = await submitAndGetPayload()
    expect(payload.payment_term_id).toBe(7)
    expect(payload.credit_terms).toBe('Net 45')
  })
})
