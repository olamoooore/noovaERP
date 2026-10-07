// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { ReactElement } from 'react'
import QuotationCreatePage from './QuotationCreatePage'
import SalesOrderCreatePage from './SalesOrderCreatePage'
import CreateInvoicePage from '@/pages/accounting/customers/CreateInvoicePage'

// #318 (SALES-UX / F10): submit validation on the sales document forms is
// shown on the field or grid cell (not only in a toast), the first invalid
// field is scrolled into view and focused, and Laravel 422 `errors` keys such
// as `lines.2.quantity` land on the matching grid cell.

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

vi.mock('@/components/ui/CustomerSearchCombobox', () => ({
  CustomerSearchCombobox: ({ onValueChange }: { onValueChange: (id: string, c?: any) => void }) => (
    <button type="button" onClick={() => onValueChange('1', { id: '1', name: 'Klinik Ali', code: 'C-ALI' })}>pick-ali</button>
  ),
}))

const ali = { id: 1, name: 'Klinik Ali', code: 'C-ALI', payment_terms: 30, customer_contacts: [], addresses: [] }
const product = { id: 5, name: 'Yarn', sku: 'YRN-1', price: '10', tax_rate: 0 }

const scrollIntoView = vi.fn()

beforeEach(() => {
  window.localStorage.clear()
  scrollIntoView.mockReset()
  HTMLElement.prototype.scrollIntoView = scrollIntoView
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/customers/1') return { data: ali }
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
    lines: (lines ?? []).map(() => ({ unit_price: 10, list_price: 10, source: 'price_list' })),
  }))
})

afterEach(cleanup)

function renderAt(path: string, page: ReactElement) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path={path} element={page} />
          <Route path="*" element={<div>Somewhere else</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const forms = [
  { name: 'quotation', render: () => renderAt('/quotations/new', <QuotationCreatePage />) },
  { name: 'sales order', render: () => renderAt('/sales/orders/new', <SalesOrderCreatePage />) },
  { name: 'invoice', render: () => renderAt('/accounting/customers/bills/new', <CreateInvoicePage />) },
] as const

const slot = (key: string) => document.querySelector(`[data-field-key="${key}"]`) as HTMLElement | null

function submit() {
  const button = document.querySelector('form button[type="submit"]') as HTMLButtonElement
  fireEvent.click(button)
}

async function pickProductOnRow(row: number) {
  const triggers = (await screen.findAllByRole('combobox')).filter((c) => /Search (product|name, SKU)/.test(c.textContent ?? ''))
  fireEvent.click(triggers[row])
  fireEvent.click(await screen.findByText('Yarn'))
  await waitFor(() => expect(resolveBatchMock).toHaveBeenCalled())
}

async function expectInlineErrorFocused(key: string, message: RegExp) {
  await waitFor(() => expect(slot(key)).not.toBeNull())
  const cell = slot(key)!
  await waitFor(() => expect(within(cell).getByRole('alert').textContent).toMatch(message))
  await waitFor(() => expect(cell.contains(document.activeElement)).toBe(true))
  expect(scrollIntoView).toHaveBeenCalled()
}

describe.each(forms)('$name form — inline validation with scroll-and-focus (#318)', (form) => {
  it('shows a missing customer on the customer field and focuses it', async () => {
    form.render()
    await screen.findByText('pick-ali')

    submit()

    await expectInlineErrorFocused('customer_id', /select a customer/i)
    expect(apiPost).not.toHaveBeenCalled()
  })

  it('shows a missing product on the line cell and focuses it', async () => {
    form.render()
    fireEvent.click(await screen.findByText('pick-ali'))
    const description = (await screen.findAllByPlaceholderText('Description'))[0]
    fireEvent.change(description, { target: { value: 'Custom service' } })

    submit()

    await expectInlineErrorFocused('lines.0.product_id', /select a product/i)
    expect(apiPost).not.toHaveBeenCalled()
  })

  it('maps a server 422 `lines.N.quantity` error onto the grid cell and focuses it', async () => {
    apiPost.mockImplementation(async (url: string) => {
      if (url.startsWith('/products') || url.startsWith('/customers')) return { data: { data: {} } }
      return Promise.reject({
        response: {
          status: 422,
          data: {
            message: 'The given data was invalid.',
            errors: { 'lines.0.quantity': ['The quantity may not have more than 4 decimal places.'] },
          },
        },
      })
    })
    form.render()
    fireEvent.click(await screen.findByText('pick-ali'))
    await pickProductOnRow(0)

    submit()

    await expectInlineErrorFocused('lines.0.quantity', /more than 4 decimal places/)
  })

  it('clears the inline error once the field is edited', async () => {
    form.render()
    fireEvent.click(await screen.findByText('pick-ali'))
    await pickProductOnRow(0)
    const quantity = slot('lines.0.quantity')!.querySelector('input')!
    fireEvent.change(quantity, { target: { value: '' } })

    submit()
    await expectInlineErrorFocused('lines.0.quantity', /quantity/i)

    fireEvent.change(quantity, { target: { value: '2' } })
    await waitFor(() => expect(within(slot('lines.0.quantity')!).queryByRole('alert')).toBeNull())
  })
})

describe('invoice: payload line indexes are mapped back to grid rows (#318)', () => {
  it('a server error on payload line 0 lands on grid row 1 when row 0 is an unsent blank row', async () => {
    apiPost.mockImplementation(async () => Promise.reject({
      response: {
        status: 422,
        data: { message: 'The given data was invalid.', errors: { 'lines.0.unit_price': ['The unit price must be at least 0.'] } },
      },
    }))
    renderAt('/accounting/customers/bills/new', <CreateInvoicePage />)
    fireEvent.click(await screen.findByText('pick-ali'))
    fireEvent.click(screen.getByRole('button', { name: /add line/i }))
    await pickProductOnRow(1)

    submit()

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost.mock.calls[0][1].lines).toHaveLength(1)
    await expectInlineErrorFocused('lines.1.unit_price', /at least 0/)
    expect(within(slot('lines.0.unit_price')!).queryByRole('alert')).toBeNull()
  })
})
