// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QuotationEditPage } from './QuotationEditPage'
import QuotationCreatePage from './QuotationCreatePage'
import { useAuthStore } from "@/store/authStore"
import { useSettingsStore } from "@/store/settingsStore"
import { formatCurrency } from "@/lib/utils"

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

const { apiGet, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPut: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: apiPut,
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))


// Issue #322 (Q14): a quotation saved in USD must show every amount in USD
// even though the tenant's default currency is MYR.
const usdQuotation = {
  id: 5,
  quotation_number: 'QUO-2026-0005',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  quotation_date: '2026-09-01',
  valid_until: '2026-09-30',
  status: 'draft',
  lock_version: 3,
  currency_code: 'USD',
  base_currency_code: 'MYR',
  exchange_rate: 4.5,
  discount_type: 'fixed',
  discount_value: 0,
  lines: [
    {
      id: 11,
      line_type: 'product',
      product_id: 7,
      product: { id: 7, name: 'Widget', sku: 'W-1' },
      description: 'Widget',
      quantity: 2,
      unit_price: 20,
      tax_rate: 0,
      discount_amount: 0,
      line_total: 40,
    },
  ],
}

beforeEach(() => {
  useAuthStore.setState({
    user: { id: 1, name: 'Me', email: 'me@example.com' },
    role: 'Owner',
    permissions: [],
    token: 'test-token',
  })
  useSettingsStore.setState({ currencyCode: 'MYR' })

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/quotations/5') return { data: usdQuotation }
    if (url === '/price-lists') return { data: { data: [{ id: 3, name: 'VIP', currency_code: 'USD' }] } }
    if (url === '/accounting/currencies') return { data: { data: [{ code: 'MYR' }, { code: 'USD' }] } }
    return { data: { data: [] } }
  })
  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: {} }))
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotations/5/edit']}>
        <Routes>
          <Route path="/quotations/:id/edit" element={<QuotationEditPage />} />
          <Route path="*" element={<div>other</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('QuotationEditPage — amounts in the document currency (#322)', () => {
  it('shows the saved document currency in the selector and formats every amount in it', async () => {
    renderPage()

    const currency = (await screen.findByLabelText('Document currency')) as HTMLSelectElement
    await waitFor(() => expect(currency.value).toBe('USD'))

    const usdTotal = formatCurrency(40, 'USD')
    expect(usdTotal).not.toBe(formatCurrency(40, 'MYR'))

    fireEvent.click(screen.getByTitle('References, contacts, addresses, logistics and terms'))
    expect((await screen.findByTestId('summary-total')).textContent).toBe(usdTotal)
    expect(screen.getByTestId('summary-subtotal').textContent).toBe(usdTotal)
    // The line total and the totals card use the same document currency;
    // nothing on the form is rendered in the tenant default (MYR).
    expect(screen.getAllByText(usdTotal, { exact: false }).length).toBeGreaterThan(2)
    expect(document.body.textContent).not.toContain(formatCurrency(40, 'MYR'))
  })

  it('keeps the saved currency snapshot: the selector is locked on edit', async () => {
    renderPage()
    const currency = (await screen.findByLabelText('Document currency')) as HTMLSelectElement
    await waitFor(() => expect(currency.value).toBe('USD'))
    expect(currency.disabled).toBe(true)
  })
})

describe('QuotationCreatePage — amounts follow the selected price list currency (#322)', () => {
  it('formats the totals in the USD price list currency, not the tenant default', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/quotations/new']}>
          <QuotationCreatePage />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    const list = (await screen.findByLabelText('Price list')) as HTMLSelectElement
    await screen.findByRole('option', { name: /VIP/ })
    fireEvent.change(list, { target: { value: '3' } })

    fireEvent.click(screen.getByTitle('References, contacts, addresses, logistics and terms'))
    expect((await screen.findByTestId('summary-total')).textContent).toBe(formatCurrency(0, 'USD'))
    expect(formatCurrency(0, 'USD')).not.toBe(formatCurrency(0, 'MYR'))
  })
})
