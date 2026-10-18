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

const medicalCase = {
  id: 7,
  case_no: 'MC-2026-0007',
  status: 'delivered',
  customer_id: 1,
  invoice_id: null,
  items: [
    { id: 70, product_id: 100, product: { name: 'Stent A' }, allocated_qty: 6, delivered_qty: 6, invoiced_qty: 0, unit_price: 25 },
  ],
}

const invoices = [
  { id: 11, invoice_number: 'INV-0011', total: 250, status: 'posted' },
  { id: 12, invoice_number: 'INV-0012', total: 99, status: 'cancelled' },
]

const invoiceDetail = {
  id: 11,
  invoice_number: 'INV-0011',
  lines: [
    { id: 501, product_id: 100, description: 'Stent A', quantity: 10, product: { name: 'Stent A' } },
    { id: 502, product_id: 200, description: 'Balloon B', quantity: 4, product: { name: 'Balloon B' } },
  ],
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiPost.mockResolvedValue({ data: {} })
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/medical-cases/7') return { data: medicalCase }
    if (url === '/invoices') return { data: { data: invoices } }
    if (url === '/invoices/11') return { data: invoiceDetail }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/sales/medical-cases/7']}>
        <Routes>
          <Route path="/sales/medical-cases/:id" element={<MedicalCaseDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('MedicalCaseDetailPage — invoice dialog picks invoice lines (#566)', () => {
  it('offers only same-product invoice lines and sends invoice_item_id per case line', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /invoice/i }))

    const dialog = await screen.findByRole('dialog')
    const invoiceSelect = within(dialog).getByLabelText('Invoice') as HTMLSelectElement

    // Cancelled invoices are not offered.
    await waitFor(() => expect(within(invoiceSelect).getByText(/INV-0011/)).toBeTruthy())
    expect(within(invoiceSelect).queryByText(/INV-0012/)).toBeNull()

    fireEvent.change(invoiceSelect, { target: { value: '11' } })

    const lineSelect = (await within(dialog).findByLabelText(/invoice line for stent a/i)) as HTMLSelectElement
    await waitFor(() => expect(Array.from(lineSelect.options).map((option) => option.value)).toContain('501'))
    const optionValues = Array.from(lineSelect.options).map((option) => option.value)
    expect(optionValues).not.toContain('502')

    fireEvent.change(lineSelect, { target: { value: '501' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /confirm/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost).toHaveBeenCalledWith('/medical-cases/7/invoice', {
      invoice_id: 11,
      items: [{ id: 70, invoiced_qty: 6, invoice_item_id: 501 }],
    })
  })
})
