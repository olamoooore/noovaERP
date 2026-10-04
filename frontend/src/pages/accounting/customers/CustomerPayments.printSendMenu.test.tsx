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
  amount: 250,
  method: 'bank_transfer',
  reference: 'TRX-31',
  payment_status: 'recorded',
  currency: 'MYR',
  created_at: '2026-02-01T09:00:00Z',
  customer: { id: 1, name: 'Klinik Ali', code: 'KA-1' },
  invoice: { id: 5, invoice_number: 'INV-2026-0005' },
}

const RESOLUTION = {
  outcome: 'RESOLVED',
  template_id: 'tpl-rcpt',
  template_name: 'Payment Receipt',
  rule: { scope_type: 'tenant', scope_value: null },
  rule_label: 'tenant rule',
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/payments?')) {
      return { data: { data: [payment], current_page: 1, last_page: 1, per_page: 20, total: 1 } }
    }
    if (url === '/payments/31') return { data: payment }
    if (url === '/accounting/payment-methods') return { data: { data: [] } }
    if (url.startsWith('/documents/receipt/resolution')) {
      return { data: { data: RESOLUTION } }
    }
    if (url.startsWith('/template-builder/templates')) {
      return { data: { data: [{ id: 'tpl-rcpt', name: 'Payment Receipt' }] } }
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

describe('CustomerPayments — receipt print and send menu (#618)', () => {
  it('offers the menu on the payment detail with the resolved template and no send actions', async () => {
    renderPage()
    await screen.findByText('PAY-2026-0031')

    fireEvent.click(screen.getByRole('button', { name: 'View payment' }))
    await screen.findByText(/Payment PAY-2026-0031/)

    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')

    expect(screen.getByText('Preview')).toBeTruthy()
    expect(screen.getByText('Download PDF')).toBeTruthy()
    expect(screen.getByText('Print')).toBeTruthy()
    expect(screen.getByText('Choose template')).toBeTruthy()
    expect(screen.queryByText('Email')).toBeNull()
    expect(screen.queryByText('WhatsApp')).toBeNull()

    expect(await screen.findByText('Using: Payment Receipt (tenant rule)')).toBeTruthy()
  })

  it('previews at the receipt PDF endpoint with the resolved template', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)

    renderPage()
    await screen.findByText('PAY-2026-0031')
    fireEvent.click(screen.getByRole('button', { name: 'View payment' }))
    await screen.findByText(/Payment PAY-2026-0031/)
    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')
    await screen.findByText('Using: Payment Receipt (tenant rule)')

    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/payments/31/pdf',
        expect.objectContaining({
          params: { template: 'tpl-rcpt' },
          responseType: 'blob',
        }),
      )
    })
    expect(open).toHaveBeenCalled()
  })
})
