// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { InvoiceDetailPage } from './InvoiceDetailPage'
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

const draftInvoice = {
  id: 9,
  invoice_number: 'INV-2026-0009',
  status: 'draft',
  customer_id: 1,
  customer: { id: 1, code: 'KA-1', name: 'Klinik Ali', email: 'billing@klinik.example', phone: '', billing_address: '' },
  invoice_date: '2026-02-01',
  due_date: '2026-02-28',
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

function preflightWith(status: 'pass' | 'warn' | 'fail') {
  return {
    ready: status !== 'fail',
    passed: status === 'pass' ? 2 : 1,
    total: 2,
    checks: [
      { key: 'lines', label: 'Has line items', status: 'pass', detail: '1 line(s) to post.' },
      {
        key: 'duplicate',
        label: 'Likely duplicate',
        status,
        detail: 'Likely duplicate of INV-2026-0001 — check before posting.',
      },
    ],
  }
}

let preflight = preflightWith('fail')

beforeEach(() => {
  useAuthStore.setState({ role: 'Admin', user: { id: 2, name: 'Acct', email: 'a@b.c' }, token: 't' })
  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: {} }))
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/9') return { data: draftInvoice }
    if (url === '/invoices/9/preflight') return { data: preflight }
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
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('InvoiceDetailPage — pre-flight gate on Post (#344)', () => {
  it('disables Post and lists the failing reasons when a check fails', async () => {
    preflight = preflightWith('fail')
    renderPage()

    const notice = await screen.findByTestId('preflight-blocked')
    expect(notice.textContent).toContain('Likely duplicate')
    expect(notice.textContent).toContain('Likely duplicate of INV-2026-0001')
    expect(notice.textContent).not.toContain('Has line items')

    const post = screen.getByRole('button', { name: /post invoice/i }) as HTMLButtonElement
    expect(post.disabled).toBe(true)
    fireEvent.click(post)
    expect(apiPost).not.toHaveBeenCalledWith('/invoices/9/post')
  })

  it('keeps Post enabled when checks only warn', async () => {
    preflight = preflightWith('warn')
    renderPage()

    await screen.findByText(/Ready 1\/2|1 issue/)
    const post = screen.getByRole('button', { name: /post invoice/i }) as HTMLButtonElement
    await waitFor(() => expect(post.disabled).toBe(false))
    expect(screen.queryByTestId('preflight-blocked')).toBeNull()
  })
})
