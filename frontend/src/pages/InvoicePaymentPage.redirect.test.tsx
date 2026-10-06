// @vitest-environment jsdom
// #487 (PAY-UX1): the standalone "Record Payment" page is retired — the old
// /invoices/:id/payment URL redirects to the invoice detail page with the
// shared RegisterPaymentDialog open.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { useAuthStore } from '@/store/authStore'

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

import { InvoiceDetailPage } from './InvoiceDetailPage'
import { InvoicePaymentPage } from './InvoicePaymentPage'

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: { id: 1 } })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const postedInvoice = {
  id: 9,
  invoice_number: 'INV-2026-0009',
  customer_id: 1,
  customer: { id: 1, code: 'KA-1', name: 'Klinik Ali', email: '', phone: '', billing_address: '' },
  invoice_date: '2026-02-01',
  due_date: '2026-02-28',
  status: 'posted',
  subtotal: 100,
  tax_amount: 0,
  discount_amount: 0,
  total: 100,
  paid_amount: 40,
  balance: 60,
  written_off_amount: 0,
  notes: '',
  terms: '',
  created_at: '2026-02-01T09:00:00Z',
  lines: [],
  payments: [],
  delivery_orders: [],
  evidence: [],
  write_offs: [],
}

beforeEach(() => {
  useAuthStore.setState({
    role: 'Accountant',
    user: { id: 2, name: 'Acct', email: 'a@b.c' },
    token: 't',
    permissions: ['accounting.view', 'accounting.write', 'invoices.view'],
  })
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/9') return { data: postedInvoice }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

describe('/invoices/:id/payment (#487 PAY-UX1)', () => {
  it('redirects to the invoice with the shared payment dialog open', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/invoices/9/payment']}>
          <Routes>
            <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
            <Route path="/invoices/:id/payment" element={<InvoicePaymentPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByText(/Invoice INV-2026-0009/)
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/register payment/i)).toBeTruthy()
    const amount = within(dialog).getByLabelText(/^amount/i) as HTMLInputElement
    await waitFor(() => expect(amount.value).toBe('60'))
    expect(screen.queryByText('Payment Details')).toBeNull()
  })
})
