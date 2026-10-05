// @vitest-environment jsdom
// #825 (EINV-CORE UX8): the refund record (a correction_type=refund payment)
// must show the e-invoice status chip of its LHDN refund note, like #670 does
// for invoices and credit notes.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { CustomerPayments } from './CustomerPayments'

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

const refundPayment = {
  id: 77,
  payment_number: 'PAY-2026-0077',
  payment_date: '2026-02-10',
  amount: -250,
  method: 'refund',
  reference: 'Refund: PAY-2026-0042',
  payment_status: 'refunded',
  currency: 'MYR',
  correction_type: 'refund',
  customer: { id: 1, name: 'Klinik Ali' },
  invoice: { id: 5, invoice_number: 'INV-2026-0005' },
  created_at: '2026-02-10T09:00:00Z',
}

const acceptedRefundNote = {
  id: 9,
  document_type: 'refund_note',
  document_id: 77,
  country_code: 'MY',
  compliance_status: 'accepted',
  latest_submission: {
    id: 2,
    submission_status: 'accepted',
    external_document_uuid: 'LHDN-UUID-77',
  },
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/payments?')) {
      return { data: { data: [refundPayment], current_page: 1, last_page: 1, per_page: 20, total: 1 } }
    }
    if (url === '/accounting/payment-methods') return { data: { data: [] } }
    if (url.startsWith('/accounting/compliance/documents')) {
      return { data: { data: [acceptedRefundNote] } }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/accounting/customers/payments']}>
        <Routes>
          <Route path="/accounting/customers/payments" element={<CustomerPayments />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CustomerPayments — refund note status chip (#825 UX8)', () => {
  it('shows the LHDN refund note status chip on the refund record', async () => {
    renderPage()

    const row = (await screen.findByText('PAY-2026-0077')).closest('tr') as HTMLElement
    expect(row).toBeTruthy()

    expect(within(row).getByText('Refunded')).toBeTruthy()
    expect(await within(row).findByText('Valid')).toBeTruthy()

    expect(apiGet).toHaveBeenCalledWith(
      expect.stringContaining('document_type=refund_note'),
    )
    expect(apiGet).toHaveBeenCalledWith(
      expect.stringContaining('with_latest_submission=1'),
    )
  })
})
