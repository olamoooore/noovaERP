// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import CustomerCreditNotesPage from './CustomerCreditNotesPage'
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

const rows = [
  {
    id: 1, credit_note_number: 'CN-APPLIED', credit_note_date: '2026-02-01', total_amount: 200, currency_code: 'MYR',
    status: 'posted', customer_name: 'Klinik Ali', invoice_id: 5, invoice_number: 'INV-5',
    einvoice_status: 'valid',
    outcome: { state: 'applied', applied_amount: 200, remaining_credit: 0, applied_to: { invoice_id: 5, invoice_number: 'INV-5' } },
  },
  {
    id: 2, credit_note_number: 'CN-CREDIT', credit_note_date: '2026-02-02', total_amount: 75, currency_code: 'MYR',
    status: 'posted', customer_name: 'Klinik Ali', invoice_id: 6, invoice_number: 'INV-6',
    einvoice_status: 'not_submitted',
    outcome: { state: 'customer_credit', applied_amount: 0, remaining_credit: 75, applied_to: null },
  },
  {
    id: 3, credit_note_number: 'CN-DRAFT', credit_note_date: '2026-02-03', total_amount: 10, currency_code: 'MYR',
    status: 'draft', customer_name: 'Klinik Ali', invoice_id: 6, invoice_number: 'INV-6',
    einvoice_status: null, outcome: null,
  },
]

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/customers/credit-notes') return { data: { data: rows } }
    return { data: { data: [] } }
  })
  useAuthStore.setState({ role: 'Accountant', permissions: ['accounting.view'] })
})

afterEach(() => {
  cleanup()
  useAuthStore.setState({ role: null, permissions: [] })
})

function renderList() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <CustomerCreditNotesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CustomerCreditNotesPage — outcome columns and filters (#525)', () => {
  it('shows applied-to invoice link, remaining credit, outcome and e-invoice status per row', async () => {
    renderList()

    expect(await screen.findByText('CN-APPLIED')).toBeTruthy()
    expect(screen.getByRole('columnheader', { name: /applied to/i })).toBeTruthy()
    expect(screen.getByRole('columnheader', { name: /remaining credit/i })).toBeTruthy()

    const appliedRow = screen.getByText('CN-APPLIED').closest('tr')!
    const link = appliedRow.querySelector('a[href="/accounting/customers/bills/5"]')
    expect(link?.textContent).toBe('INV-5')
    expect(appliedRow.textContent).toContain('Applied to invoice')
    expect(appliedRow.textContent).toContain('Valid')

    const creditRow = screen.getByText('CN-CREDIT').closest('tr')!
    expect(creditRow.querySelector('a[href^="/accounting/customers/bills/"]')).toBeNull()
    expect(creditRow.textContent).toContain('Customer credit')
    expect(creditRow.textContent).toMatch(/75\.00/)
    expect(creditRow.textContent).toContain('Not submitted')

    const draftRow = screen.getByText('CN-DRAFT').closest('tr')!
    expect(draftRow.textContent).not.toContain('Not submitted')
  })

  it('sends the outcome and e-invoice status filters to the list API', async () => {
    renderList()
    await screen.findByText('CN-APPLIED')

    fireEvent.change(screen.getByLabelText(/filter by outcome/i), { target: { value: 'customer_credit' } })
    fireEvent.change(screen.getByLabelText(/filter by e-invoice status/i), { target: { value: 'valid' } })

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith('/accounting/customers/credit-notes', {
        params: expect.objectContaining({ outcome: 'customer_credit', einvoice_status: 'valid' }),
      })
    })
  })
})
