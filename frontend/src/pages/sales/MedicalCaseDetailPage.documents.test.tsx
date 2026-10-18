// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { MedicalCaseDetailPage } from './MedicalCaseDetailPage'

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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

let medicalCase: any

const doLineCandidates = {
  data: {
    80: [
      { id: 900, delivery_order_id: 40, delivery_order_number: 'DO-0040', quantity_delivered: 5, remaining_qty: 3, is_linked: true },
      { id: 901, delivery_order_id: 41, delivery_order_number: 'DO-0041', quantity_delivered: 10, remaining_qty: 10, is_linked: false },
    ],
  },
}

beforeEach(() => {
  medicalCase = {
    id: 8,
    case_no: 'MC-2026-0008',
    status: 'allocated',
    customer_id: 1,
    invoice_id: null,
    linked_delivery_orders: [{ id: 40, delivery_order_number: 'DO-0040', status: 'done' }],
    items: [
      {
        id: 80,
        product_id: 100,
        product: { name: 'Stent A' },
        allocated_qty: 3,
        delivered_qty: 0,
        invoiced_qty: 0,
        unit_price: 25,
        delivery_order_item_id: 900,
      },
    ],
  }
  apiGet.mockReset()
  apiPost.mockReset()
  apiPost.mockResolvedValue({ data: {} })
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/medical-cases/8') return { data: medicalCase }
    if (url === '/medical-cases/8/delivery-order-lines') return { data: doLineCandidates }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/sales/medical-cases/8']}>
        <Routes>
          <Route path="/sales/medical-cases/:id" element={<MedicalCaseDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('MedicalCaseDetailPage — case creates / links DO and invoice (#578)', () => {
  it('"Create DO" creates the delivery order from the case', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /create do/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/medical-cases/8/create-delivery-order'))
  })

  it('records delivery against a completed DO line (sends delivery_order_item_id)', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /record delivery/i }))

    const dialog = await screen.findByRole('dialog')
    const lineSelect = (await within(dialog).findByLabelText(/delivery order line for stent a/i)) as HTMLSelectElement
    await waitFor(() => expect(lineSelect.value).toBe('900'))

    fireEvent.click(within(dialog).getByRole('button', { name: /confirm/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost).toHaveBeenCalledWith('/medical-cases/8/deliver', {
      items: [{ id: 80, delivered_qty: 3, delivery_order_item_id: 900 }],
    })
  })

  it('"Create invoice" in the invoice dialog creates the invoice from the case', async () => {
    medicalCase.status = 'delivered'
    medicalCase.items[0].delivered_qty = 3
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /invoice/i }))

    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: /create invoice/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/medical-cases/8/create-invoice'))
  })

  it('shows linked DO and invoice chips with their status', async () => {
    medicalCase.status = 'invoiced'
    medicalCase.invoice_id = 11
    medicalCase.invoice = { id: 11, invoice_number: 'INV-0011', status: 'draft' }
    renderPage()

    const doChip = await screen.findByRole('button', { name: /DO-0040/ })
    expect(doChip.textContent).toMatch(/done/i)
    const invoiceChip = screen.getByRole('button', { name: /INV-0011/ })
    expect(invoiceChip.textContent).toMatch(/draft/i)
  })
})
