// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
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

// #500: a POSTED customer payment (journal entry set) has a receipt to send.
const postedPayment = {
  id: 32,
  payment_number: 'PAY-2026-0032',
  payment_date: '2026-02-01',
  amount: 1050,
  method: 'bank_transfer',
  reference: 'TRX-32',
  payment_status: 'applied',
  journal_entry_id: 900,
  customer_id: 1,
  currency: 'MYR',
  created_at: '2026-02-01T09:00:00Z',
  customer: { id: 1, name: 'Klinik Ali', code: 'KA-1' },
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/payments?')) {
      return { data: { data: [postedPayment], current_page: 1, last_page: 1, per_page: 20, total: 1 } }
    }
    if (url === '/payments/32') return { data: postedPayment }
    if (url === '/payments/32/receipt-deliveries') {
      return { data: { data: [{ id: 7, channel: 'email', recipient: 'ap@klinik.test', status: 'sent', sent_at: '2026-02-02T09:00:00Z' }] } }
    }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { data: { id: 8, channel: 'email', recipient: 'ap@klinik.test', status: 'sent' } } })
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

describe('CustomerPayments — send the payment receipt (#500)', () => {
  it('emails the receipt of a posted payment and shows its delivery history', async () => {
    renderPage()
    await screen.findByText('PAY-2026-0032')
    fireEvent.click(screen.getByRole('button', { name: 'View payment' }))
    await screen.findByText(/Payment PAY-2026-0032/)

    expect(await screen.findByText('ap@klinik.test')).toBeTruthy()

    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')
    expect(screen.getByText('WhatsApp')).toBeTruthy()

    fireEvent.click(screen.getByText('Email'))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/payments/32/send-receipt', { channel: 'email' })
    })
  })
})
