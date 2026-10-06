// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { CustomerCreditNoteDetail } from './CustomerCreditNoteDetail'
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

const outcomeNote = {
  id: 7,
  credit_note_number: 'CN-2026-0007',
  credit_note_date: '2026-02-01',
  total_amount: 150,
  currency_code: 'MYR',
  status: 'posted',
  customer: { id: 1, name: 'Klinik Ali' },
  invoice: { id: 5, invoice_number: 'INV-2026-0005' },
  lines: [],
  einvoice_status: 'not_submitted',
  outcome: {
    state: 'partially_applied',
    applied_amount: 100,
    remaining_credit: 50,
    applied_to: { invoice_id: 5, invoice_number: 'INV-2026-0005' },
  },
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/customers/credit-notes/7') return { data: outcomeNote }
    return { data: { data: [] } }
  })
  useAuthStore.setState({ role: 'Accountant', permissions: ['accounting.view'] })
})

afterEach(() => {
  cleanup()
  useAuthStore.setState({ role: null, permissions: [] })
})

describe('CustomerCreditNoteDetail — credit outcome (#525)', () => {
  it('shows where the credit went: applied invoice, applied amount and remaining credit', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/accounting/customers/credit-notes/7']}>
          <Routes>
            <Route path="/accounting/customers/credit-notes/:id" element={<CustomerCreditNoteDetail />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Credit outcome')).toBeTruthy()
    expect(screen.getByText('Partly applied')).toBeTruthy()
    expect(screen.getByText('Remaining customer credit')).toBeTruthy()
    const appliedLink = screen
      .getAllByRole('link', { name: 'INV-2026-0005' })
      .find((link) => link.getAttribute('href') === '/accounting/customers/bills/5')
    expect(appliedLink).toBeTruthy()
    expect(appliedLink!.parentElement!.textContent).toMatch(/100\.00/)
    const remaining = screen.getByText('Remaining customer credit').nextElementSibling
    expect(remaining?.textContent).toMatch(/^\D*50\.00$/)
  })
})
