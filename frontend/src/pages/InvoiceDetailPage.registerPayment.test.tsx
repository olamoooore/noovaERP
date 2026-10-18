// @vitest-environment jsdom
// #491 (PAY-UX5): "Register payment" on the invoice detail page opens the
// existing payment dialog in place, prefilled with the open balance and today's
// local date — no navigation to a separate page.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
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
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/9') return { data: currentInvoice }
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

describe('InvoiceDetailPage — Register payment (#491 PAY-UX5)', () => {
  it('opens the payment dialog in place, prefilled with the balance and today', async () => {
    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)

    fireEvent.click(screen.getByRole('button', { name: /register payment/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/register payment/i)).toBeTruthy()
    expect(screen.queryByText('separate payment page')).toBeNull()

    const amount = within(dialog).getByLabelText(/^amount/i) as HTMLInputElement
    await waitFor(() => expect(amount.value).toBe('60'))
    expect((within(dialog).getByLabelText(/payment date/i) as HTMLInputElement).value).toBe('2026-03-15')

    fireEvent.click(within(dialog).getByRole('button', { name: /record payment/i }))
    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
        invoice_id: '9',
        amount: '60',
        payment_date: '2026-03-15',
      })),
    )
  })

  it('hides the action without accounting.write', async () => {
    useAuthStore.setState({ role: 'Sales', permissions: ['accounting.view', 'invoices.view'] })
    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)
    expect(screen.queryByRole('button', { name: /register payment|record payment/i })).toBeNull()
  })

  it('hides the action when the invoice has no open balance', async () => {
    currentInvoice = { ...postedInvoice, status: 'paid', paid_amount: 100, balance: 0 }
    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)
    expect(screen.queryByRole('button', { name: /register payment|record payment/i })).toBeNull()
  })

  it('hides the action on a draft invoice', async () => {
    currentInvoice = { ...postedInvoice, status: 'draft', paid_amount: 0, balance: 100 }
    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)
    expect(screen.queryByRole('button', { name: /register payment|record payment/i })).toBeNull()
  })
})
