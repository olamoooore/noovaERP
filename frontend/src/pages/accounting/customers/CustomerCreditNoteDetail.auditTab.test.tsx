// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
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

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
  },
}))

const creditNote = {
  id: 7,
  credit_note_number: 'CN-2026-0007',
  status: 'draft',
  credit_note_date: '2026-02-01',
  total_amount: 100,
  currency_code: 'MYR',
  customer: { id: 1, name: 'Klinik Ali' },
  invoice: { id: 5, invoice_number: 'INV-2026-0005' },
  lines: [],
}

const auditPayload = {
  data: [
    {
      id: 'audit-9',
      source: 'audit_log',
      occurred_at: '2026-02-02T09:00:00+00:00',
      user: { id: 3, name: 'Ali Admin' },
      kind: 'lifecycle',
      event: 'customer_credit_note_posted',
      field: 'status',
      path: 'status',
      old_value: 'draft',
      new_value: 'posted',
      request_id: 'req-cn',
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
  filters: { fields: ['status'], users: [{ id: 3, name: 'Ali Admin' }] },
}

beforeEach(() => {
  localStorage.clear()
  useAuthStore.getState().setAuth(
    { id: 3, name: 'Ali Admin', email: 'ali@example.com' },
    'token',
    'tenant-1',
    'Accountant',
    ['accounting.view'],
  )
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/customers/credit-notes/7') return { data: creditNote }
    if (url === '/accounting/customers/credit-notes/7/audit') return { data: auditPayload }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

describe('CustomerCreditNoteDetail — Audit tab (#905 / PCU1)', () => {
  it('renders the Audit tab and loads the read-only credit-note audit feed', async () => {
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

    await screen.findByText(/Credit Note CN-2026-0007/)

    const auditTabTrigger = await screen.findByRole('tab', { name: /audit/i })
    fireEvent.mouseDown(auditTabTrigger, { button: 0 })

    expect(await screen.findByTestId('audit-tab')).toBeTruthy()
    expect(await screen.findByText('customer_credit_note_posted')).toBeTruthy()
    expect(apiGet).toHaveBeenCalledWith(
      '/accounting/customers/credit-notes/7/audit',
      expect.objectContaining({ params: expect.objectContaining({ per_page: 100 }) }),
    )
  })
})
