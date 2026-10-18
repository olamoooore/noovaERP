// @vitest-environment jsdom
// #508 (PY6): keyboard-first payment entry on the invoice list — `P` on a
// focused invoice row opens the shared payment dialog prefilled; `?` shows the
// shortcuts overlay. Neither fires while typing in a field.
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

import { InvoicesPage } from './InvoicesPage'

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
    if (url === '/invoices') {
      return { data: { data: [postedInvoice, draftInvoice, paidInvoice], current_page: 1, last_page: 1, total: 3 } }
    }
    if (url === '/invoices/9') return { data: postedInvoice }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/invoices']}>
        <Routes>
          <Route path="/invoices" element={<InvoicesPage />} />
          <Route path="/invoices/:id" element={<div>invoice detail page</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const rowFor = async (number: string) => (await screen.findByText(number)).closest('tr') as HTMLElement

describe('InvoicesPage — keyboard-first payment entry (#508 PY6)', () => {
  it('P on a focused payable row opens the payment dialog prefilled with the balance', async () => {
    renderPage()
    const row = await rowFor('INV-2026-0009')
    row.focus()
    expect(document.activeElement).toBe(row)
    fireEvent.keyDown(row, { key: 'p' })

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('Register payment')).toBeTruthy()
    const amount = within(dialog).getByLabelText(/^amount/i) as HTMLInputElement
    await waitFor(() => expect(amount.value).toBe('60'))
    expect(screen.queryByText('invoice detail page')).toBeNull()
  })

  it('P does nothing on a row that is not payable, or without accounting.write', async () => {
    renderPage()
    const draftRow = await rowFor('INV-2026-0010')
    draftRow.focus()
    fireEvent.keyDown(draftRow, { key: 'P' })
    await new Promise((r) => setTimeout(r, 20))
    expect(screen.queryByRole('dialog')).toBeNull()
    cleanup()

    useAuthStore.setState({ role: 'Sales', permissions: ['accounting.view', 'invoices.view'] })
    renderPage()
    const row = await rowFor('INV-2026-0009')
    row.focus()
    fireEvent.keyDown(row, { key: 'p' })
    await new Promise((r) => setTimeout(r, 20))
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('P typed in the search box does not open the payment dialog', async () => {
    renderPage()
    await rowFor('INV-2026-0009')
    const search = screen.getByPlaceholderText(/search invoice, customer, contact/i)
    search.focus()
    fireEvent.keyDown(search, { key: 'p' })
    await new Promise((r) => setTimeout(r, 20))
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('? opens the keyboard shortcuts overlay listing the payment shortcuts', async () => {
    renderPage()
    await rowFor('INV-2026-0009')
    fireEvent.keyDown(document.body, { key: '?', shiftKey: true })

    const overlay = await screen.findByRole('dialog')
    expect(within(overlay).getByText('Keyboard shortcuts')).toBeTruthy()
    expect(within(overlay).getByText(/register a payment for the focused invoice row/i)).toBeTruthy()
    expect(within(overlay).getByText(/save the payment as a draft/i)).toBeTruthy()
    expect(within(overlay).getByText(/save and post to the ledger/i)).toBeTruthy()
  })

  it('? typed in a text input does not open the overlay', async () => {
    renderPage()
    await rowFor('INV-2026-0009')
    const search = screen.getByPlaceholderText(/search invoice, customer, contact/i)
    search.focus()
    fireEvent.keyDown(search, { key: '?', shiftKey: true })
    await new Promise((r) => setTimeout(r, 20))
    expect(screen.queryByText('Keyboard shortcuts')).toBeNull()
  })
})
