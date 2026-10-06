// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SalesOrderCreatePage } from './SalesOrderCreatePage'
import { SalesOrderEditPage } from './SalesOrderEditPage'
import { SalesOrdersPage } from './SalesOrdersPage'
import { useAuthStore } from '@/store/authStore'

/**
 * Issue #374 (SALES-SO / O5): sales orders are created and edited directly on
 * the shared sales document pieces (line grid + pricing hook + totals), and
 * draft lines travel to PUT /sales/orders/{id}. Non-draft orders are not
 * editable on this form (#407 lock/amend governs confirmed orders).
 */

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

const { apiGet, apiPost, apiPut, resolveBatchMock } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPut: vi.fn(),
  resolveBatchMock: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: apiPut,
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

function makeOrder(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    sales_order_number: 'SO-2026-0005',
    customer_id: 1,
    customer,
    order_date: '2026-09-01',
    status: 'draft',
    currency_code: 'MYR',
    lock_version: 3,
    notes: 'original note',
    internal_notes: null,
    discount_amount: 0,
    lines: [
      {
        id: 11,
        line_type: 'product',
        sequence: 0,
        product_id: 7,
        product: { id: 7, name: 'Widget', sku: 'W-1' },
        description: 'Widget',
        quantity: 2,
        unit_price: 20,
        tax_rate: 0,
        discount_amount: 0,
        line_total: 40,
        income_account_id: 400,
      },
    ],
    ...overrides,
  }
}

let currentOrder: Record<string, unknown> = makeOrder()

beforeEach(() => {
  useAuthStore.setState({
    user: { id: 1, name: 'Me', email: 'me@example.com' },
    role: 'Owner',
    permissions: [],
    token: 'test-token',
  })
  currentOrder = makeOrder()

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/5') return { data: currentOrder }
    if (url.startsWith('/sales/orders')) return { data: { data: [], meta: { total: 0, last_page: 1 } } }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    if (url.startsWith('/products/')) return { data: { data: product } }
    if (url.startsWith('/products')) return { data: { data: [product] } }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: { id: 99 } }))
  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: {} }))

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

function renderAt(path: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/sales/orders" element={<SalesOrdersPage />} />
          <Route path="/sales/orders/create" element={<SalesOrderCreatePage />} />
          <Route path="/sales/orders/:id/edit" element={<SalesOrderEditPage />} />
          <Route path="/sales/orders/:id" element={<div>sales order detail</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Sales order create/edit (#374)', () => {
  it('registers the create and edit routes in the app router', () => {
    const app = readFileSync(resolve(__dirname, '../../App.tsx'), 'utf8')
    expect(app).toMatch(/<Route path="\/sales\/orders\/create" element={<SalesOrderCreatePage \/>} \/>/)
    expect(app).toMatch(/<Route path="\/sales\/orders\/:id\/edit" element={<SalesOrderEditPage \/>} \/>/)
  })

  it('the orders list links to the create page', async () => {
    renderAt('/sales/orders')

    fireEvent.click(await screen.findByRole('button', { name: /new sales order/i }))

    expect(await screen.findByRole('heading', { name: 'Create Sales Order' })).toBeTruthy()
  })

  it('creates a sales order with engine-priced lines through POST /sales/orders', async () => {
    renderAt('/sales/orders/create')

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

    await waitFor(() => expect(resolveBatchMock).toHaveBeenCalled())

    fireEvent.click(screen.getByRole('button', { name: /create sales order/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
    const [url, payload] = apiPost.mock.calls[0]
    expect(url).toBe('/sales/orders')
    expect(payload.customer_id).toBe(1)
    expect(payload.channel).toBe('admin')
    expect(payload.lines).toHaveLength(1)
    expect(payload.lines[0]).toEqual(expect.objectContaining({
      line_type: 'product',
      product_id: 5,
      price_mode: 'auto',
      unit_price: 10,
    }))

    expect(await screen.findByText('sales order detail')).toBeTruthy()
  })

  it('edits a draft order: lines and version go to PUT /sales/orders/{id}', async () => {
    renderAt('/sales/orders/5/edit')

    expect(await screen.findByRole('heading', { name: 'Edit Sales Order' })).toBeTruthy()
    const notes = (await screen.findByLabelText('Notes (printed)')) as HTMLTextAreaElement
    await waitFor(() => expect(notes.value).toBe('original note'))
    fireEvent.change(notes, { target: { value: 'revised note' } })

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(apiPut).toHaveBeenCalledTimes(1))
    const [url, payload] = apiPut.mock.calls[0]
    expect(url).toBe('/sales/orders/5')
    expect(payload.version).toBe(3)
    expect(payload.notes).toBe('revised note')
    expect(payload.lines).toHaveLength(1)
    expect(payload.lines[0]).toEqual(expect.objectContaining({
      product_id: 7,
      quantity: 2,
      unit_price: 20,
      // A saved price is carried, never silently re-priced.
      price_mode: 'carried',
      // Hidden line fields survive the edit.
      income_account_id: 400,
    }))
    // The currency snapshot is fixed after creation.
    expect(payload.currency_code).toBeUndefined()

    expect(await screen.findByText('sales order detail')).toBeTruthy()
  })

  it('does not offer line editing on a non-draft order', async () => {
    currentOrder = makeOrder({ status: 'confirmed' })
    renderAt('/sales/orders/5/edit')

    expect(await screen.findByTestId('sales-order-not-editable')).toBeTruthy()
    expect(screen.queryByRole('button', { name: /save changes/i })).toBeNull()
    expect(apiPut).not.toHaveBeenCalled()
  })
})
