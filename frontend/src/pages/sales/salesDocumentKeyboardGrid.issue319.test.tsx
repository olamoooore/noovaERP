// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, act } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { useState, type ReactElement } from 'react'
import { SalesDocumentLineGrid } from '@/components/sales/SalesDocumentLineGrid'
import { emptySalesDocumentLine, type SalesDocumentLine } from '@/lib/salesDocumentLines'
import QuotationCreatePage from './QuotationCreatePage'
import SalesOrderCreatePage from './SalesOrderCreatePage'

// #319 (SALES-UX): keyboard-first line grid on the quotation and sales order
// editors (shared SalesDocumentLineGrid).
// - Focus starts on the customer; after a pick it moves to line 1's product.
// - Tab: product -> qty -> price (-> discount); Enter on the last cell adds
//   and focuses a new row.
// - An empty row is always present at the end and ignored on save.
// - Typing or scanning a SKU/barcode in the product cell selects an exact
//   match immediately (existing GET /products?search= lookup).

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
HTMLElement.prototype.scrollIntoView = () => {}

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

const yarn = { id: 5, name: 'Yarn', sku: 'YRN-1', barcode: '9555000000011', price: '10', tax_rate: 0, stock_quantity: 3 }
const yarnBig = { id: 6, name: 'Yarn big', sku: 'YRN-10', barcode: '9555000000028', price: '12', tax_rate: 0, stock_quantity: 3 }
const ali = { id: 1, name: 'Klinik Ali', code: 'C-ALI', payment_terms: 30, customer_contacts: [], addresses: [] }

function productSearch(search?: string) {
  const all = [yarn, yarnBig]
  // A large catalogue: the unfiltered first page does not hold these items,
  // so a pick must come from the search for what was typed/scanned.
  if (!search) return []
  const q = search.toLowerCase()
  return all.filter((p) => p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q) || p.barcode.includes(q))
}

beforeEach(() => {
  window.localStorage.clear()
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, any> }) => {
    if (url === '/customers/1') return { data: ali }
    if (url === '/customers') return { data: { data: [ali] } }
    if (url === '/products/5') return { data: { data: yarn } }
    if (url === '/products/6') return { data: { data: yarnBig } }
    if (url === '/products') return { data: { data: productSearch(config?.params?.search) } }
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

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

let latestLines: SalesDocumentLine[] = []

function GridHarness({ initial }: { initial: SalesDocumentLine[] }) {
  const [lines, setLines] = useState<SalesDocumentLine[]>(initial)
  latestLines = lines
  return <SalesDocumentLineGrid lines={lines} setLines={setLines} money={(v) => v.toFixed(2)} />
}

function renderGrid(initial: SalesDocumentLine[]) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <GridHarness initial={initial} />
    </QueryClientProvider>,
  )
}

const cell = (row: number, field: string) => {
  const slot = document.querySelector(`[data-field-key="lines.${row}.${field}"]`)
  if (!slot) throw new Error(`no cell lines.${row}.${field}`)
  const control = field === 'product_id'
    ? slot.querySelector('button[role="combobox"]')
    : slot.querySelector('input, textarea')
  if (!control) throw new Error(`no control in lines.${row}.${field}`)
  return control as HTMLElement
}

const filledLine = (): SalesDocumentLine => ({
  ...emptySalesDocumentLine(),
  product_id: '5',
  sku: 'YRN-1',
  description: 'Yarn',
  unit_price: 10,
})

describe('#319 keyboard-first sales line grid', () => {
  it('always shows one empty row at the end, never saved as a line', async () => {
    renderGrid([filledLine()])
    // The empty entry row follows the last line; it is not a line in state.
    expect(cell(1, 'product_id')).toBeTruthy()
    expect(screen.getAllByTestId('sales-line-entry')).toHaveLength(1)
    await act(async () => {})
    expect(latestLines).toHaveLength(1)

    // An untouched starter row already is the empty row: no second one.
    cleanup()
    renderGrid([emptySalesDocumentLine()])
    await act(async () => {})
    expect(latestLines).toHaveLength(1)
    expect(screen.queryByTestId('sales-line-entry')).toBeNull()
    expect(document.querySelector('[data-field-key="lines.1.product_id"]')).toBeNull()
  })

  it('a SKU typed on the empty end row adds it as a line and moves to its qty', async () => {
    renderGrid([filledLine()])
    const trigger = cell(1, 'product_id')
    trigger.focus()
    fireEvent.keyDown(trigger, { key: 'Y' })
    const input = await screen.findByPlaceholderText(/Search name, SKU, barcode/i)
    fireEvent.change(input, { target: { value: 'YRN-10' } })
    fireEvent.keyDown(input, { key: 'Enter' })

    await waitFor(() => expect(latestLines).toHaveLength(2))
    expect(latestLines[1].product_id).toBe('6')
    expect(latestLines[1].sku).toBe('YRN-10')
    expect(latestLines[1].quantity).toBe(1)
    await waitFor(() => expect(document.activeElement).toBe(cell(1, 'quantity')))
    // A fresh empty row follows the new line.
    expect(cell(2, 'product_id')).toBeTruthy()
  })

  it('Tab moves product -> qty -> price -> discount, Shift+Tab goes back', async () => {
    renderGrid([filledLine()])

    cell(0, 'product_id').focus()
    fireEvent.keyDown(cell(0, 'product_id'), { key: 'Tab' })
    expect(document.activeElement).toBe(cell(0, 'quantity'))
    fireEvent.keyDown(cell(0, 'quantity'), { key: 'Tab' })
    expect(document.activeElement).toBe(cell(0, 'unit_price'))
    fireEvent.keyDown(cell(0, 'unit_price'), { key: 'Tab' })
    expect(document.activeElement).toBe(cell(0, 'discount_amount'))

    fireEvent.keyDown(cell(0, 'discount_amount'), { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(cell(0, 'unit_price'))
    fireEvent.keyDown(cell(0, 'unit_price'), { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(cell(0, 'quantity'))
    fireEvent.keyDown(cell(0, 'quantity'), { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(cell(0, 'product_id'))
  })

  it('Enter on the last cell focuses the next row, adding one when needed', async () => {
    renderGrid([filledLine(), filledLine()])

    // Enter on a middle cell moves along the row and never submits.
    cell(0, 'quantity').focus()
    const enterOnQty = fireEvent.keyDown(cell(0, 'quantity'), { key: 'Enter' })
    expect(enterOnQty).toBe(false) // default prevented
    expect(document.activeElement).toBe(cell(0, 'unit_price'))

    // Enter on the last cell (discount) goes to the next row's product.
    cell(0, 'discount_amount').focus()
    fireEvent.keyDown(cell(0, 'discount_amount'), { key: 'Enter' })
    expect(document.activeElement).toBe(cell(1, 'product_id'))
    expect(latestLines).toHaveLength(2)

    // Enter on the last cell of the last row adds and focuses a new row.
    cell(1, 'discount_amount').focus()
    const enterOnLast = fireEvent.keyDown(cell(1, 'discount_amount'), { key: 'Enter' })
    expect(enterOnLast).toBe(false)
    await waitFor(() => expect(latestLines).toHaveLength(3))
    expect(latestLines[2].product_id).toBe('')
    await waitFor(() => expect(document.activeElement).toBe(cell(2, 'product_id')))
    expect(cell(2, 'quantity')).toBeTruthy() // a real line, not the entry row
  })

  it('typing a SKU in the product cell and pressing Enter selects the exact match at once', async () => {
    renderGrid([emptySalesDocumentLine()])
    const trigger = cell(0, 'product_id')
    trigger.focus()

    // Typing on the closed cell opens the search seeded with the typed key.
    fireEvent.keyDown(trigger, { key: 'Y' })
    const input = await screen.findByPlaceholderText(/Search name, SKU, barcode/i)
    expect((input as HTMLInputElement).value).toBe('Y')

    // A scanner types the rest and sends Enter before any debounce settles.
    fireEvent.change(input, { target: { value: 'YRN-1' } })
    fireEvent.keyDown(input, { key: 'Enter' })

    await waitFor(() => expect(latestLines[0].product_id).toBe('5'))
    expect(latestLines[0].sku).toBe('YRN-1')
    expect(apiGet).toHaveBeenCalledWith('/products', expect.objectContaining({
      params: expect.objectContaining({ search: 'YRN-1' }),
    }))
    // The picked row is ready for its quantity.
    await waitFor(() => expect(document.activeElement).toBe(cell(0, 'quantity')))
  })

  it('a scanned barcode with a single exact match is selected without Enter', async () => {
    renderGrid([emptySalesDocumentLine()])
    const trigger = cell(0, 'product_id')
    trigger.focus()
    fireEvent.keyDown(trigger, { key: '9' })
    const input = await screen.findByPlaceholderText(/Search name, SKU, barcode/i)
    fireEvent.change(input, { target: { value: '9555000000028' } })

    await waitFor(() => expect(latestLines[0].product_id).toBe('6'), { timeout: 2000 })
    expect(latestLines[0].sku).toBe('YRN-10')
  })

  it('a partial SKU that is a prefix of another is not auto-selected', async () => {
    renderGrid([emptySalesDocumentLine()])
    const trigger = cell(0, 'product_id')
    trigger.focus()
    fireEvent.keyDown(trigger, { key: 'Y' })
    const input = await screen.findByPlaceholderText(/Search name, SKU, barcode/i)
    fireEvent.change(input, { target: { value: 'YRN-1' } })

    // Both YRN-1 and YRN-10 match the search; nothing is picked until Enter.
    await screen.findByText('Yarn big')
    await new Promise((resolve) => setTimeout(resolve, 400))
    expect(latestLines[0].product_id).toBe('')
  })
})

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
  { name: 'quotation', path: '/quotations/new', page: () => <QuotationCreatePage />, endpoint: '/quotations' },
  { name: 'sales order', path: '/sales/orders/new', page: () => <SalesOrderCreatePage />, endpoint: '/sales/orders' },
]

describe.each(forms)('#319 $name form keyboard entry', ({ path, page, endpoint }) => {
  it('starts on the customer and moves to line 1 product after the pick', async () => {
    renderAt(path, page())
    const customerSlot = await waitFor(() => {
      const slot = document.querySelector('[data-field-key="customer_id"]')
      if (!slot) throw new Error('no customer slot')
      return slot
    })
    const customerTrigger = customerSlot.querySelector('button[role="combobox"]') as HTMLElement
    await waitFor(() => expect(document.activeElement).toBe(customerTrigger))

    fireEvent.click(customerTrigger)
    fireEvent.click(await screen.findByText('Klinik Ali'))

    await waitFor(() => expect(document.activeElement).toBe(cell(0, 'product_id')))
  })

  it('ignores the trailing empty row on save', async () => {
    renderAt(path, page())
    const customerTrigger = await waitFor(() => {
      const el = document.querySelector('[data-field-key="customer_id"] button[role="combobox"]')
      if (!el) throw new Error('no customer trigger')
      return el as HTMLElement
    })
    fireEvent.click(customerTrigger)
    fireEvent.click(await screen.findByText('Klinik Ali'))

    const trigger = cell(0, 'product_id')
    trigger.focus()
    fireEvent.keyDown(trigger, { key: 'Y' })
    const input = await screen.findByPlaceholderText(/Search name, SKU, barcode/i)
    fireEvent.change(input, { target: { value: 'YRN-1' } })
    fireEvent.keyDown(input, { key: 'Enter' })

    // The picked row is followed by a fresh empty row.
    await waitFor(() => expect(document.querySelector('[data-field-key="lines.1.product_id"]')).not.toBeNull())

    const save = screen.getAllByRole('button').find((b) => (b as HTMLButtonElement).type === 'submit')
    expect(save).toBeTruthy()
    fireEvent.click(save!)

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    const call = apiPost.mock.calls.find(([url]) => String(url).startsWith(endpoint))
    expect(call).toBeTruthy()
    const payload = call![1] as { lines: any[] }
    expect(payload.lines).toHaveLength(1)
    expect(payload.lines[0].product_id).toBe(5)
  })
})
