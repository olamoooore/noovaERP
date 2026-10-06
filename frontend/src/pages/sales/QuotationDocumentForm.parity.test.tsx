// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import QuotationCreatePage from './QuotationCreatePage'
import QuotationEditPage from './QuotationEditPage'
import { useAuthStore } from '@/store/authStore'

/**
 * Issue #314 parity: create and edit share one form, so the capabilities the
 * edit page used to own (SKU? no — discount, drag, pricing) and the ones the
 * create page used to own (SKU, price list, price_mode) apply to BOTH.
 *
 * These assertions fail on the pre-#314 pages:
 *  - edit had no pricing engine, no `price_mode`, no SKU column;
 *  - create had no line discount column.
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

const { apiGet, apiPost, apiPut, resolveBatchMock, listPriceListsMock } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPut: vi.fn(),
  resolveBatchMock: vi.fn(),
  listPriceListsMock: vi.fn(),
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
    listPriceLists: listPriceListsMock,
  },
}))

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 30 }
const yarn = { id: 5, name: 'Yarn', sku: 'YRN-1', price: '10', tax_rate: 0 }
const bolt = { id: 6, name: 'Bolt', sku: 'BLT-2', price: '20', tax_rate: 0 }
const widget = { id: 7, name: 'Widget', sku: 'W-1', price: '20', tax_rate: 0 }
const gadget = { id: 9, name: 'Gadget', sku: 'GDG-9', price: '30', tax_rate: 0 }
const allProducts = [yarn, bolt, widget, gadget]

const quotationControl: { value: Record<string, unknown> } = { value: {} }

function makeQuotation(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    quotation_number: 'QUO-2026-0005',
    customer_id: 1,
    customer: { id: 1, name: 'Klinik Ali' },
    quotation_date: '2026-09-01',
    valid_until: '2026-09-30',
    status: 'draft',
    lock_version: 3,
    discount_type: 'fixed',
    discount_value: 0,
    notes: 'original note',
    terms: '',
    billing_address_snapshot: '',
    shipping_address_snapshot: '',
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
      },
    ],
    ...overrides,
  }
}

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
  useAuthStore.setState({
    user: { id: 1, name: 'Me', email: 'me@example.com' },
    role: 'Owner',
    permissions: [],
    token: 'test-token',
  })

  quotationControl.value = makeQuotation()

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/quotations/5') return { data: quotationControl.value }
    if (url.startsWith('/tags/')) return { data: { data: [] } }
    if (url.startsWith('/products/')) {
      const id = url.split('/')[2]
      return { data: { data: allProducts.find((product) => String(product.id) === id) ?? null } }
    }
    if (url.startsWith('/products')) return { data: { data: allProducts } }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: { data: { id: 99 } } }))
  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: { data: { id: 5 } } }))

  listPriceListsMock.mockReset()
  listPriceListsMock.mockImplementation(async () => ({
    data: [{ id: 3, name: 'VIP', currency_code: 'USD' }],
  }))

  resolveBatchMock.mockReset()
  resolveBatchMock.mockImplementation(async (_context: any, lines: any[]) => engineResult(lines, 99))
})

afterEach(cleanup)

function renderCreate() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotations/new']}>
        <QuotationCreatePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function renderEdit() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotations/5/edit']}>
        <Routes>
          <Route path="/quotations/:id/edit" element={<QuotationEditPage />} />
          <Route path="/quotations/:id" element={<div>quotation detail</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function pickCustomer() {
  const customerTrigger = (await screen.findAllByRole('combobox')).find((combobox) =>
    combobox.textContent?.includes('Search customers'),
  )
  expect(customerTrigger, 'customer combobox trigger').toBeTruthy()
  fireEvent.click(customerTrigger!)
  fireEvent.click(await screen.findByText('Klinik Ali'))
}

async function pickProductOnStarterLine(name: string) {
  const productTrigger = (await screen.findAllByRole('combobox')).find((combobox) =>
    combobox.textContent?.includes('Search product'),
  )
  expect(productTrigger, 'product combobox trigger').toBeTruthy()
  fireEvent.click(productTrigger!)
  fireEvent.click(await screen.findByText(name))
}

const priceInputOfRow = (container: HTMLElement, rowIndex: number) => {
  const row = container.querySelectorAll('tbody tr')[rowIndex]
  return row.querySelectorAll('input[type="number"]')[1] as HTMLInputElement
}

const DETAILS_TITLE = 'References, contacts, addresses, logistics and terms'

// #323 moved notes/terms into the single details drawer; these helpers keep
// the existing parity assertions working through the new (accepted) layout.
async function editNotesInDrawer(value: string) {
  fireEvent.click(await screen.findByTitle(DETAILS_TITLE))
  const notes = (await screen.findByPlaceholderText('Internal notes...')) as HTMLTextAreaElement
  fireEvent.change(notes, { target: { value } })
  fireEvent.click(screen.getByRole('button', { name: 'Close' }))
  await waitFor(() => expect(screen.queryByPlaceholderText('Internal notes...')).toBeNull())
  return notes
}

describe('#314 quotation create/edit parity', () => {
  it('edit: renders the SKU column with the saved product SKU', async () => {
    renderEdit()

    expect(await screen.findByRole('columnheader', { name: 'SKU' })).toBeTruthy()
    expect(await screen.findByText('W-1')).toBeTruthy()
  })

  it('edit: sends an explicit price_mode for saved lines (no engine bypass)', async () => {
    renderEdit()
    await screen.findByRole('button', { name: /save changes/i })

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(apiPut).toHaveBeenCalledTimes(1))
    const [, payload] = apiPut.mock.calls[0]
    expect(payload.lines[0].price_mode).toBe('carried')
  })

  it('edit: a product pick resolves the price list and saves the line as auto', async () => {
    quotationControl.value = makeQuotation({ lines: [] })
    const { container } = renderEdit()

    await pickProductOnStarterLine('Yarn')

    await waitFor(() => expect(resolveBatchMock).toHaveBeenCalled())
    await waitFor(() => expect(priceInputOfRow(container, 0).value).toBe('99'))

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))
    await waitFor(() => expect(apiPut).toHaveBeenCalledTimes(1))
    const [, payload] = apiPut.mock.calls[0]
    expect(payload.lines[0].product_id).toBe(5)
    expect(payload.lines[0].price_mode).toBe('auto')
    expect(Number(payload.lines[0].unit_price)).toBe(99)
  })

  it('edit: a hand-typed price is marked manual so the engine never overwrites it', async () => {
    const { container } = renderEdit()
    await screen.findByRole('button', { name: /save changes/i })

    fireEvent.change(priceInputOfRow(container, 0), { target: { value: '77' } })
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(apiPut).toHaveBeenCalledTimes(1))
    const [, payload] = apiPut.mock.calls[0]
    expect(payload.lines[0].price_mode).toBe('manual')
    expect(Number(payload.lines[0].unit_price)).toBe(77)
  })

  it('edit: selecting a price list resolves through the engine with that list', async () => {
    renderEdit()
    const priceList = await screen.findByLabelText('Price list')

    fireEvent.change(priceList, { target: { value: '3' } })

    await waitFor(() => {
      expect(
        resolveBatchMock.mock.calls.some((call) => call[0]?.price_list_id === 3),
      ).toBe(true)
    })
  })

  it('create: renders the line discount column and sends the discount', async () => {
    renderCreate()
    await pickCustomer()
    await pickProductOnStarterLine('Yarn')

    expect(await screen.findByRole('columnheader', { name: 'Disc' })).toBeTruthy()

    const discount = screen.getByLabelText('Line discount') as HTMLInputElement
    fireEvent.change(discount, { target: { value: '3' } })

    fireEvent.click(screen.getByRole('button', { name: /create quotation/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
    const [, payload] = apiPost.mock.calls[0]
    expect(Number(payload.lines[0].discount_amount)).toBe(3)
  })

  it('create: renders the SKU column', async () => {
    renderCreate()
    expect(await screen.findByRole('columnheader', { name: 'SKU' })).toBeTruthy()
  })

  it('create: dragging a row persists the new order in the payload', async () => {
    const { container } = renderCreate()
    await pickCustomer()
    await pickProductOnStarterLine('Yarn')

    fireEvent.click(screen.getByRole('button', { name: /add line/i }))
    await pickProductOnStarterLine('Bolt')

    const rows = container.querySelectorAll('tbody tr')
    expect(rows.length).toBe(2)
    fireEvent.dragStart(rows[0])
    fireEvent.dragOver(rows[1])
    fireEvent.drop(rows[1])

    fireEvent.click(screen.getByRole('button', { name: /create quotation/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
    const [, payload] = apiPost.mock.calls[0]
    expect(payload.lines.map((line: any) => line.product_id)).toEqual([6, 5])
  })

  it('edit: dragging a row persists the new order in the payload', async () => {
    quotationControl.value = makeQuotation({
      lines: [
        {
          id: 11, line_type: 'product', product_id: 7, product: { id: 7, name: 'Widget', sku: 'W-1' },
          description: 'Widget', quantity: 1, unit_price: 20, tax_rate: 0, discount_amount: 0,
        },
        {
          id: 12, line_type: 'product', product_id: 9, product: { id: 9, name: 'Gadget', sku: 'GDG-9' },
          description: 'Gadget', quantity: 1, unit_price: 30, tax_rate: 0, discount_amount: 0,
        },
      ],
    })
    const { container } = renderEdit()
    await screen.findByRole('button', { name: /save changes/i })

    const rows = container.querySelectorAll('tbody tr')
    expect(rows.length).toBe(2)
    fireEvent.dragStart(rows[0])
    fireEvent.dragOver(rows[1])
    fireEvent.drop(rows[1])

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(apiPut).toHaveBeenCalledTimes(1))
    const [, payload] = apiPut.mock.calls[0]
    expect(payload.lines.map((line: any) => line.description)).toEqual(['Gadget', 'Widget'])
  })

  it('edit: keeps the #906 conflict dialog reachable', async () => {
    apiPut.mockRejectedValue({
      response: {
        status: 409,
        data: {
          message: 'stale',
          code: 'stale_record',
          current_version: 4,
          data: makeQuotation({ notes: 'their note', lock_version: 4 }),
        },
      },
    })
    renderEdit()
    await editNotesInDrawer('my note')
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('their note')).toBeTruthy()
    expect(within(dialog).getByRole('button', { name: /overwrite/i })).toBeTruthy()
  })
})
