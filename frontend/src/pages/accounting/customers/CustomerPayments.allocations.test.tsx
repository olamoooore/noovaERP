// @vitest-environment jsdom
// #488 (PAY-UX2): the payment detail view shows the #155 applications and
// unapplied amount, with an Allocate action.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { useAuthStore } from '@/store/authStore'
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

const payment = {
  id: 31,
  payment_number: 'PAY-2026-0031',
  payment_date: '2026-02-01',
  amount: 500,
  method: 'bank_transfer',
  payment_status: 'applied',
  journal_entry_id: 77,
  currency: 'MYR',
  created_at: '2026-02-01T09:00:00Z',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali', code: 'KA-1' },
  invoice: null,
}

beforeEach(() => {
  useAuthStore.setState({ role: 'Accountant', permissions: ['accounting.view', 'accounting.write'] })
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/payments?')) {
      return { data: { data: [payment], current_page: 1, last_page: 1, per_page: 20, total: 1 } }
    }
    if (url === '/payments/31') return { data: payment }
    if (url === '/payments/31/allocations') {
      return {
        data: {
          data: [
            { id: 1, payment_id: 31, invoice_id: 5, applied_amount: '120.00', reversed_at: null, created_at: '2026-02-01T09:00:00Z', invoice: { id: 5, invoice_number: 'INV-2026-0005' } },
            { id: 2, payment_id: 31, invoice_id: 6, applied_amount: '80.00', reversed_at: null, created_at: '2026-02-01T09:00:00Z', invoice: { id: 6, invoice_number: 'INV-2026-0006' } },
          ],
          amount: 500,
          applied_amount: 200,
          unapplied_amount: 300,
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
      <MemoryRouter initialEntries={['/accounting/customers/payments']}>
        <Routes>
          <Route path="/accounting/customers/payments" element={<CustomerPayments />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CustomerPayments — payment applications (#488)', () => {
  it('shows every invoice the payment is applied to, the unapplied amount and Allocate', async () => {
    renderPage()
    await screen.findByText('PAY-2026-0031')
    fireEvent.click(screen.getByRole('button', { name: 'View payment' }))
    await screen.findByText(/Payment PAY-2026-0031/)

    const panel = await screen.findByTestId('payment-allocations')
    await within(panel).findByText('INV-2026-0005')
    expect(within(panel).getByText('INV-2026-0006')).toBeTruthy()
    expect(within(panel).getByTestId('unapplied-amount').textContent).toMatch(/300\.00/)
    expect(within(panel).getByRole('button', { name: /^allocate$/i })).toBeTruthy()
    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/payments/31/allocations'))
  })
})
