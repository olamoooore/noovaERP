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

async function pickCustomerAndRepeat(container: HTMLElement) {
  // Pick the customer (opens the Radix popover, one mocked result).
  const customerTrigger = (await screen.findAllByRole('combobox')).find((c) =>
    c.textContent?.includes('Search customers'),
  )
  expect(customerTrigger, 'customer combobox trigger').toBeTruthy()
  fireEvent.click(customerTrigger!)
  fireEvent.click(await screen.findByText('Klinik Ali'))

  // Repeat the last invoice so the grid has real product lines.
  const repeat = await screen.findByRole('button', { name: /Repeat last invoice/ }, { timeout: 5000 })
  await waitFor(() => expect((repeat as HTMLButtonElement).disabled).toBe(false), { timeout: 5000 })
  fireEvent.click(repeat)
  await screen.findByText('Auto Widget')

  // Make the second line's price a hand-typed (manual) override.
  const priceInputs = [...container.querySelectorAll('td[data-label="Unit price"] input')] as HTMLInputElement[]
  expect(priceInputs.length).toBe(2)
  fireEvent.change(priceInputs[1], { target: { value: '77' } })
}

describe('CreateInvoicePage date-effective pricing (#299 / F7)', () => {
  it('re-resolves auto lines for the new invoice date and keeps manual prices', async () => {
    const { container } = renderPage()
    await pickCustomerAndRepeat(container)

    // #343 (approved 7 Oct 2026): repeat re-prices through the engine instead
    // of carrying the old prices.
    await waitFor(() => expect(resolveBatchMock).toHaveBeenCalled())

    // Change the invoice date to a different price-list validity period.
    const dateLabel = [...container.querySelectorAll('label')].find(
      (l) => l.textContent?.trim() === 'Invoice Date',
    )
    expect(dateLabel, 'Invoice Date label').toBeTruthy()
    const dateInput = dateLabel!.parentElement!.querySelector('input[type="date"]') as HTMLInputElement
    expect(dateInput, 'Invoice Date input').toBeTruthy()
    fireEvent.change(dateInput, { target: { value: '2026-06-15' } })

    // The pricing engine is re-asked with the NEW date (not the stale state).
    await waitFor(() => {
      const withNewDate = resolveBatchMock.mock.calls.filter((c: any[]) => c[0]?.date === '2026-06-15')
      expect(withNewDate.length).toBeGreaterThan(0)
    })

    // Auto/carried line repriced to the engine answer (99); the hand-typed
    // price (77) must be untouched.
    await waitFor(() => {
      const priceInputs = [...container.querySelectorAll('td[data-label="Unit price"] input')] as HTMLInputElement[]
      expect(priceInputs[0].value).toBe('99')
      expect(priceInputs[1].value).toBe('77')
    })
  })
})
