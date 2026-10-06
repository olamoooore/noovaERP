// @vitest-environment jsdom
// #488 (PAY-UX2): an open invoice shows the customer's unapplied payments
// (outstanding credits) with an action to apply one to this invoice.
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

// Pin "today" so the local-date prefill is asserted deterministically.
vi.mock('@/lib/dateOnly', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/dateOnly')>()),
  todayDateOnly: () => '2026-03-15',
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

let currentInvoice: typeof postedInvoice = postedInvoice

beforeEach(() => {
  currentInvoice = postedInvoice
  useAuthStore.setState({
    role: 'Accountant',
    user: { id: 2, name: 'Acct', email: 'a@b.c' },
    token: 't',
    permissions: ['accounting.view', 'accounting.write', 'invoices.view'],
  })
  apiGet.mockReset()
  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: { id: 1 } }))
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/invoices/9') return { data: currentInvoice }
    if (url === '/payments' && config?.params?.unapplied === 1 && config?.params?.customer_id === 1) {
      return {
        data: {
          data: [
            { id: 70, payment_number: 'PAY-2026-0070', payment_date: '2026-02-10', amount: '90.00', unapplied_amount: '25.00', customer_id: 1, payment_status: 'applied' },
          ],
          total: 1,
        },
      }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/invoices/9']}>
        <Routes>
          <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
          <Route path="/invoices/:id/payment" element={<div>separate payment page</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('InvoiceDetailPage — outstanding credits (#488)', () => {
  it("shows the customer's unapplied payments on an open invoice with an apply action", async () => {
    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)

    const card = await screen.findByTestId('unapplied-credits')
    await within(card).findByText('PAY-2026-0070')
    expect(within(card).getByTestId('unapplied-credits-total').textContent).toMatch(/25\.00/)
    expect(within(card).getByRole('button', { name: /apply to this invoice/i })).toBeTruthy()
  })

  it('does not offer the credits on a paid invoice', async () => {
    currentInvoice = { ...postedInvoice, status: 'paid', paid_amount: 100, balance: 0 }
    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)
    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/invoices/9'))
    expect(screen.queryByTestId('unapplied-credits')).toBeNull()
  })
})
