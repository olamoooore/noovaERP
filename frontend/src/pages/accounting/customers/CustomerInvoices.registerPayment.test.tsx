// @vitest-environment jsdom
// #491 (PAY-UX5): the accounting "Customer Invoices" list and its detail page
// offer the same in-place "Register payment" action as the Sales pages.
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

import { CustomerBills } from './CustomerBills'
import { CustomerInvoiceDetail } from './CustomerInvoiceDetail'


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

const draftInvoice = { ...postedInvoice, id: 10, invoice_number: 'INV-2026-0010', status: 'draft', paid_amount: 0, balance: 100 }
const paidInvoice = { ...postedInvoice, id: 11, invoice_number: 'INV-2026-0011', status: 'paid', paid_amount: 100, balance: 0 }

beforeEach(() => {
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
    if (url.startsWith('/invoices?')) {
      return { data: { data: [postedInvoice, draftInvoice, paidInvoice], current_page: 1, last_page: 1, total: 3 } }
    }
    if (url === '/invoices/9') return { data: postedInvoice }
    if (url.startsWith('/payments?')) return { data: { data: [], total: 0 } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderAt(path: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/accounting/customers/bills" element={<CustomerBills />} />
          <Route path="/accounting/customers/bills/:id" element={<CustomerInvoiceDetail />} />
          <Route path="/accounting/customers/payments" element={<div>payments page</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const rowFor = async (number: string) => (await screen.findByText(number)).closest('tr') as HTMLElement

describe('CustomerBills — Register payment row action (#491 PAY-UX5)', () => {
  it('shows the action only on payable rows with an open balance', async () => {
    renderAt('/accounting/customers/bills')
    expect(within(await rowFor('INV-2026-0009')).getByTitle('Register payment')).toBeTruthy()
    expect(within(await rowFor('INV-2026-0010')).queryByTitle('Register payment')).toBeNull()
    expect(within(await rowFor('INV-2026-0011')).queryByTitle('Register payment')).toBeNull()
  })

  it('opens the payment dialog in place, prefilled with the balance and today', async () => {
    renderAt('/accounting/customers/bills')
    fireEvent.click(within(await rowFor('INV-2026-0009')).getByTitle('Register payment'))
    const dialog = await screen.findByRole('dialog')
    const amount = within(dialog).getByLabelText(/^amount/i) as HTMLInputElement
    await waitFor(() => expect(amount.value).toBe('60'))
    expect((within(dialog).getByLabelText(/payment date/i) as HTMLInputElement).value).toBe('2026-03-15')
  })

  it('hides the action without accounting.write', async () => {
    useAuthStore.setState({ role: 'Viewer', permissions: ['accounting.view', 'invoices.view'] })
    renderAt('/accounting/customers/bills')
    expect(within(await rowFor('INV-2026-0009')).queryByTitle('Register payment')).toBeNull()
  })
})

describe('CustomerInvoiceDetail — Register payment (#491 PAY-UX5)', () => {
  it('opens the payment dialog in place from the header, without leaving the page', async () => {
    renderAt('/accounting/customers/bills/9')
    fireEvent.click(await screen.findByRole('button', { name: /register payment/i }))
    const dialog = await screen.findByRole('dialog')
    expect(screen.queryByText('payments page')).toBeNull()
    const amount = within(dialog).getByLabelText(/^amount/i) as HTMLInputElement
    await waitFor(() => expect(amount.value).toBe('60'))
    expect((within(dialog).getByLabelText(/payment date/i) as HTMLInputElement).value).toBe('2026-03-15')
  })

  it('hides the action without accounting.write', async () => {
    useAuthStore.setState({ role: 'Viewer', permissions: ['accounting.view', 'invoices.view'] })
    renderAt('/accounting/customers/bills/9')
    await screen.findAllByText(/INV-2026-0009/)
    expect(screen.queryByRole('button', { name: /register payment|record payment/i })).toBeNull()
  })
})
