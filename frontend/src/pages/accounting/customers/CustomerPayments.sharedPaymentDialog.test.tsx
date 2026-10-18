// @vitest-environment jsdom
// #487 (PAY-UX1): the Payments page records AND edits drafts through the one
// shared RegisterPaymentDialog (no page-local payment form).
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, within, fireEvent, waitFor } from '@testing-library/react'
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

const { apiGet, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPut: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: apiPut,
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const draft = {
  id: 11,
  payment_number: 'PAY-2026-0011',
  payment_date: '2026-02-10',
  amount: 100,
  method: 'cash',
  payment_status: 'recorded',
  source: 'manual',
  journal_entry_id: null,
  invoice_id: 7,
  reference: 'R-1',
  notes: null,
  bank_account_id: null,
  currency: 'MYR',
  customer: { id: 1, name: 'Klinik Ali' },
  created_at: '2026-02-10T09:00:00Z',
}

beforeEach(() => {
  apiGet.mockReset()
  apiPut.mockReset()
  apiPut.mockResolvedValue({ data: { data: {} } })
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/7') {
      return { data: { id: 7, invoice_number: 'INV-7', customer_id: 1, balance: 250, total: 250 } }
    }
    if (url.startsWith('/payments?')) {
      return { data: { data: [draft], current_page: 1, last_page: 1, per_page: 20, total: 1 } }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage(entry = '/accounting/customers/payments') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route path="/accounting/customers/payments" element={<CustomerPayments />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CustomerPayments — shared payment dialog (#487 PAY-UX1)', () => {
  it('"Record Payment" opens the shared RegisterPaymentDialog', async () => {
    renderPage()
    await screen.findByText('PAY-2026-0011')
    fireEvent.click(screen.getByRole('button', { name: /record payment/i }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/register payment/i)).toBeTruthy()
    expect(within(dialog).getByRole('button', { name: /more/i })).toBeTruthy()
  })

  it('editing a draft uses the shared dialog and PUTs the shared payload', async () => {
    renderPage()
    const row = (await screen.findByText('PAY-2026-0011')).closest('tr') as HTMLElement
    fireEvent.click(within(row).getByRole('button', { name: /edit unposted payment/i }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/edit draft payment/i)).toBeTruthy()
    expect(within(dialog).getByRole('button', { name: /more/i })).toBeTruthy()

    fireEvent.click(within(dialog).getByRole('button', { name: /save changes/i }))
    await waitFor(() => expect(apiPut).toHaveBeenCalled())
    expect(apiPut).toHaveBeenCalledWith('/payments/11', expect.objectContaining({
      invoice_id: '7',
      amount: '100',
      payment_date: '2026-02-10',
      method: 'cash',
      reference: 'R-1',
      bank_account_id: null,
    }))
  })

  it('a ?invoice_id deep link opens the shared dialog prefilled (and stays prefilled)', async () => {
    renderPage('/accounting/customers/payments?invoice_id=7')
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/register payment/i)).toBeTruthy()
    const amount = within(dialog).getByLabelText(/^amount/i) as HTMLInputElement
    await waitFor(() => expect(amount.value).toBe('250'))
  })
})
