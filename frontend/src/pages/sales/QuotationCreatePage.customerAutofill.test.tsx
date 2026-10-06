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

const ali = {
  id: 1,
  name: 'Klinik Ali',
  code: 'C-ALI',
  email: 'office@ali.test',
  phone: '03-1111',
  payment_terms: 30,
  customer_contacts: [
    { id: 11, name: 'Dr Doctor', email: 'doc@ali.test', phone: '011-0', contact_category: 'doctor' },
    { id: 12, name: 'Primary Pat', email: 'pat@ali.test', phone: '011-1', is_primary: true },
    { id: 13, name: 'Billing Bob', email: 'bob@ali.test', phone: '011-2', contact_category: 'billing' },
  ],
  addresses: [
    { type: 'billing', street: '1 Old Bill Rd', city: 'Shah Alam', is_default: false },
    { type: 'billing', street: '2 Ali Billing St', city: 'Kuala Lumpur', is_default: true },
    { type: 'shipping', street: '3 Ali Dock', city: 'Klang', is_default: true },
  ],
}

const bee = {
  id: 2,
  name: 'Hospital Bee',
  code: 'C-BEE',
  email: 'office@bee.test',
  phone: '04-2222',
  payment_terms: 60,
  customer_contacts: [
    { id: 21, name: 'Bee Billing', email: 'billing@bee.test', phone: '012-9', contact_category: 'billing' },
  ],
  addresses: [
    { type: 'billing', street: '9 Bee Billing Ave', city: 'Penang', is_default: true },
    { type: 'shipping', street: '8 Bee Warehouse', city: 'Butterworth', is_default: true },
  ],
}

const product = { id: 5, name: 'Yarn', sku: 'YRN-1', price: '10', tax_rate: 0 }

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/customers/1') return { data: ali }
    if (url === '/customers/2') return { data: bee }
    if (url.startsWith('/customers')) return { data: { data: [ali, bee] } }
    if (url.startsWith('/products/')) return { data: { data: product } }
    if (url.startsWith('/products')) return { data: { data: [product] } }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: { data: { id: 99 } } }))

  resolveBatchMock.mockReset()
  resolveBatchMock.mockImplementation(async (_context: any, lines: any[]) => ({
    currency_code: 'MYR',
    base_currency_code: 'MYR',
    exchange_rate: 1,
    rate_date: '2026-01-01',
    rate_source: 'price_list',
    rate_stale: false,
    price_list_name: 'BASE',
    price_list_currency: 'MYR',
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
  expect(productTrigger, 'product combobox trigger').toBeTruthy()
  fireEvent.click(productTrigger!)
  fireEvent.click(await screen.findByText('Yarn'))
}

const attentionInput = () => screen.getByPlaceholderText('Primary contact') as HTMLInputElement
const billingStreetInput = () => screen.getAllByPlaceholderText('Street')[0] as HTMLInputElement

async function submitAndGetPayload() {
  fireEvent.click(screen.getByRole('button', { name: /create quotation/i, hidden: true }))
  await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
  const [url, payload] = apiPost.mock.calls[0]
  expect(url).toBe('/quotations')
  return payload
}

describe('QuotationCreatePage — customer auto-fill (#320)', () => {
  it('fills contact, email, phone, reference, credit terms and both addresses from the customer, like the invoice', async () => {
    renderPage()
    fireEvent.click(await screen.findByText('pick-ali'))
    await addProductLine()
    fireEvent.click(screen.getByTitle('References, contacts, addresses, logistics and terms'))

    // Invoice parity: the billing-category contact wins over primary / first.
    await waitFor(() => expect(attentionInput().value).toBe('Billing Bob'))

    const payload = await submitAndGetPayload()
    expect(payload.attention_to).toBe('Billing Bob')
    expect(payload.contact_email).toBe('bob@ali.test')
    expect(payload.contact_phone).toBe('011-2')
    expect(payload.customer_reference).toBe('C-ALI')
    expect(payload.credit_terms).toBe('Net 30 days')
    expect(payload.billing_address_snapshot).toBe('2 Ali Billing St\nKuala Lumpur')
    expect(payload.shipping_address_snapshot).toBe('3 Ali Dock\nKlang')
  })

  it('a customer change refreshes untouched fields but keeps the values the user edited', async () => {
    renderPage()
    fireEvent.click(await screen.findByText('pick-ali'))
    await addProductLine()
    fireEvent.click(screen.getByTitle('References, contacts, addresses, logistics and terms'))
    await waitFor(() => expect(billingStreetInput().value).toBe('2 Ali Billing St'))

    // The user overrides two auto-filled values.
    fireEvent.change(attentionInput(), { target: { value: 'Purchasing Desk' } })
    fireEvent.change(billingStreetInput(), { target: { value: '77 Typed Street' } })

    fireEvent.click(screen.getByText('pick-bee', { selector: 'button' }))
    // Untouched values move to the new customer...
    await waitFor(() => expect((screen.getByPlaceholderText('billing@customer.com') as HTMLInputElement).value).toBe('billing@bee.test'))

    const payload = await submitAndGetPayload()
    expect(payload.customer_id).toBe(2)
    // ...the user's edits survive the customer change.
    expect(payload.attention_to).toBe('Purchasing Desk')
    expect(payload.billing_address_snapshot).toBe('77 Typed Street\nKuala Lumpur')
    // Everything the user did not touch follows the new customer (no stale
    // values from the previous customer).
    expect(payload.contact_email).toBe('billing@bee.test')
    expect(payload.contact_phone).toBe('012-9')
    expect(payload.customer_reference).toBe('C-BEE')
    expect(payload.credit_terms).toBe('Net 60 days')
    expect(payload.shipping_address_snapshot).toBe('8 Bee Warehouse\nButterworth')
  })
})
