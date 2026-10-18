// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { InvoiceDetailPage } from './InvoiceDetailPage'
import { useAuthStore } from '@/store/authStore'

// Issue #345: the Send toast reflects what the bundle actually included — no
// "Acceptance link generated" when the tenant turned acceptance off — and
// names each skipped part with its reason from POST /invoices/{id}/send-bundle.

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

const { apiGet, apiPost, toastMock } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn(), toastMock: vi.fn() }))

vi.mock('@/hooks/use-toast', () => ({
  useToast: () => ({ toast: toastMock, dismiss: vi.fn(), toasts: [] }),
  toast: toastMock,
}))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const invoice = {
  id: 9,
  invoice_number: 'INV-2026-0009',
  customer_id: 1,
  customer: { id: 1, code: 'KA-1', name: 'Klinik Ali', email: 'billing@klinik.example', phone: '', billing_address: '' },
  invoice_date: '2026-02-01',
  due_date: '2026-02-28',
  status: 'posted',
  subtotal: 100,
  tax_amount: 0,
  discount_amount: 0,
  total: 100,
  paid_amount: 0,
  balance: 100,
  notes: '',
  terms: '',
  created_at: '2026-02-01T09:00:00Z',
  lines: [],
  payments: [],
  delivery_orders: [],
  evidence: [],
}

const ACCEPTANCE_OFF = 'Acceptance link not included: customer acceptance is turned off in Sales settings.'

beforeEach(() => {
  useAuthStore.setState({ role: 'Owner', user: { id: 2, name: 'Owner', email: 'o@b.c' }, token: 't' } as any)
  apiGet.mockReset()
  apiPost.mockReset()
  toastMock.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/9') return { data: invoice }
    return { data: { data: [] } }
  })
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/invoices/9/send-bundle') {
      return {
        data: {
          message: 'Invoice sent with pay-now link',
          data: {
            payment_url: 'https://pay.test/checkout/9',
            skipped: [{ part: 'acceptance_link', reason: 'acceptance_disabled', message: ACCEPTANCE_OFF }],
          },
        },
      }
    }
    return { data: {} }
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
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('InvoiceDetailPage — send bundle toast (#345)', () => {
  it('does not claim an acceptance link when acceptance is off and says why', async () => {
    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)

    fireEvent.click(await screen.findByRole('button', { name: /^send$/i }))
    fireEvent.click(await screen.findByRole('button', { name: /send to customer/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/invoices/9/send-bundle', expect.anything()))
    await waitFor(() => expect(toastMock).toHaveBeenCalledWith(expect.objectContaining({ title: 'Invoice sent to customer' })))

    const sent = toastMock.mock.calls.map(([arg]) => arg).find((arg: any) => arg?.title === 'Invoice sent to customer')
    expect(sent.description).not.toMatch(/acceptance link (generated|and)/i)
    expect(sent.description).toContain('pay-now link')
    expect(sent.description).toContain(ACCEPTANCE_OFF)
  })
})
