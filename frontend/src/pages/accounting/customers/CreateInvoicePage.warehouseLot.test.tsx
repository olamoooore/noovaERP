// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
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

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

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
    resolveBatch: vi.fn(async () => ({
      currency_code: 'USD',
      base_currency_code: 'USD',
      exchange_rate: 1,
      rate_date: '2026-01-01',
      price_list_name: 'BASE',
      price_list_currency: 'USD',
      lines: [],
    })),
    sellableCurrencies: vi.fn(async () => []),
    listPriceLists: vi.fn(async () => ({ data: [] })),
  },
}))

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 30 }

const batchA = { id: 101, batch_number: 'LOT-A', available_quantity: 5, expiry_date: '2027-06-30' }
const batchB = { id: 202, batch_number: 'LOT-B', available_quantity: 7, expiry_date: '2027-07-30' }

const invoice = {
  id: 7,
  customer_id: 1,
  invoice_number: 'INV-7',
  invoice_date: '2026-01-15',
  due_date: '2026-02-14',
  payment_terms: 30,
  discount_amount: 0,
  lines: [
    {
      id: 11,
      product_id: 5,
      line_type: 'product',
      description: 'Batch A',
      quantity: 1,
      unit_price: 10,
      tax_rate: 0,
      discount_amount: 0,
      product_batch_id: 101,
      lot_number: 'LOT-A',
      serial_number: '',
      product_serial_id: null,
      expiry_date: '2027-06-30',
      product: { sku: 'A', tracking_method: 'batch' },
    },
    {
      id: 12,
      product_id: 6,
      line_type: 'product',
      description: 'Batch B',
      quantity: 1,
      unit_price: 20,
      tax_rate: 0,
      discount_amount: 0,
      product_batch_id: 202,
      lot_number: 'LOT-B',
      serial_number: '',
      product_serial_id: null,
      expiry_date: '2027-07-30',
      product: { sku: 'B', tracking_method: 'batch' },
    },
  ],
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string, config?: any) => {
    if (url.startsWith('/products/') && url.includes('tracking-availability')) {
      const warehouse = String(config?.params?.warehouse_id ?? '1')
      const productId = url.startsWith('/products/5/') ? 5 : 6
      // Warehouse 2 keeps LOT-A (product 5) but not LOT-B (product 6).
      if (warehouse === '2') {
        return { data: { tracking_method: 'batch', batches: productId === 5 ? [batchA] : [] } }
      }
      return { data: { tracking_method: 'batch', batches: productId === 5 ? [batchA] : [batchB] } }
    }
    if (url === '/invoices/7') return { data: invoice }
    if (url.startsWith('/warehouses')) {
      return { data: { data: [
        { id: 1, name: 'Main', is_default: true },
        { id: 2, name: 'North', is_default: false },
      ] } }
    }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/invoices/7']}>
        <Routes>
          <Route path="/invoices/:id" element={<CreateInvoicePage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function lotSelects(container: HTMLElement): HTMLSelectElement[] {
  return [...container.querySelectorAll('select')].filter((sel) => {
    const texts = [...sel.options].map((o) => o.textContent ?? '')
    // Tracking selects: they either list lots or state there are none.
    return texts.some((t) => t.includes('LOT-')) || texts.some((t) => /Select available lot|No available lots/.test(t))
  }) as HTMLSelectElement[]
}

function warehouseSelect(container: HTMLElement): HTMLSelectElement {
  const label = [...container.querySelectorAll('label')].find((l) => l.textContent?.includes('Warehouse'))
  expect(label, 'warehouse label').toBeTruthy()
  const select = label!.querySelector('select')
  expect(select, 'warehouse select').toBeTruthy()
  return select as HTMLSelectElement
}

describe('CreateInvoicePage warehouse change keeps available lot picks (#298 / F6)', () => {
  it('confirms before clearing, keeps lots that exist in the new warehouse, cancel keeps old warehouse', async () => {
    const { container } = renderPage()

    // Hydrated invoice with two lot selections, both visible.
    const selects = await waitFor(() => {
      const found = lotSelects(container)
      expect(found.length).toBe(2)
      return found
    })
    await waitFor(() => {
      expect(selects[0].value).toBe('101')
      expect(selects[1].value).toBe('202')
    })
    const whSelect = warehouseSelect(container)
    await waitFor(() => expect(whSelect.value).toBe('1'))

    // Switch to warehouse 2: LOT-A exists there, LOT-B does not.
    fireEvent.change(whSelect, { target: { value: '2' } })

    // A confirmation names exactly the picks that would be lost.
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/1 lot selection will be cleared/)).toBeTruthy()

    // Cancel keeps the previous warehouse and both selections.
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(warehouseSelect(container).value).toBe('1')
    await waitFor(() => {
      expect(lotSelects(container)[0].value).toBe('101')
      expect(lotSelects(container)[1].value).toBe('202')
    })

    // Confirming switches the warehouse, keeps LOT-A (available in warehouse
    // 2) and clears only LOT-B (not available there).
    fireEvent.change(warehouseSelect(container), { target: { value: '2' } })
    const dialog2 = await screen.findByRole('dialog')
    fireEvent.click(within(dialog2).getByRole('button', { name: 'Change warehouse' }))
    await waitFor(() => expect(warehouseSelect(container).value).toBe('2'))
    await waitFor(() => {
      const after = lotSelects(container)
      expect(after[0].value).toBe('101')
      expect(after[1].value).toBe('')
    })
  })
})
