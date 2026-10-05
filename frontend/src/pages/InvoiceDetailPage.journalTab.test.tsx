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

const baseInvoice = {
  id: 9,
  invoice_number: 'INV-2026-0009',
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

const previewPayload = {
  status: 'draft',
  source: 'preview',
  preview: true,
  error: null,
  journal_entry: null,
  posting_date: '2026-02-01',
  description: 'Invoice #INV-2026-0009 - Klinik Ali',
  reference: 'INV-2026-0009',
  legal_entity: { id: 3, name: 'Klinik Entity', code: 'KE-1' },
  currency: { document: 'MYR', base: 'MYR', exchange_rate: 1 },
  lines: [
    {
      account_id: 41,
      account_code: '1100',
      account_name: 'Accounts Receivable',
      debit: 100,
      credit: 0,
      description: 'Invoice INV-2026-0009',
      tax_code: null,
      currency_code: null,
      original_amount: null,
      exchange_rate: null,
      department_id: null,
      department_name: null,
      project_id: null,
      project_name: null,
    },
    {
      account_id: 42,
      account_code: '4100',
      account_name: 'Sales Revenue',
      debit: 0,
      credit: 100,
      description: 'Sales - Invoice INV-2026-0009',
      tax_code: null,
      currency_code: null,
      original_amount: null,
      exchange_rate: null,
      department_id: null,
      department_name: null,
      project_id: null,
      project_name: null,
    },
  ],
  total_debit: 100,
  total_credit: 100,
  is_balanced: true,
}

const actualPayload = {
  ...previewPayload,
  status: 'posted',
  source: 'actual',
  preview: false,
  journal_entry: {
    id: 7,
    entry_number: 'JE-2026-0007',
    entry_date: '2026-02-01',
    status: 'posted',
    description: 'Invoice #INV-2026-0009 - Klinik Ali',
    reference: 'INV-2026-0009',
  },
}

beforeEach(() => {
  useAuthStore.setState({ role: 'Accountant', user: { id: 2, name: 'Acct', email: 'a@b.c' }, token: 't' })
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/9') return { data: { ...baseInvoice, status: 'draft' } }
    if (url === '/invoices/9/journal') return { data: previewPayload }
    if (url === '/invoices/9/preflight') {
      return { data: { ready: true, passed: 8, total: 8, checks: [] } }
    }
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

describe('InvoiceDetailPage — Journal tab (#883)', () => {
  it('shows the draft journal preview from the shared server-side builder', async () => {
    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)

    fireEvent.mouseDown(screen.getByRole('tab', { name: /journal/i }))

    expect(await screen.findByText('Journal preview')).toBeTruthy()
    expect(apiGet).toHaveBeenCalledWith('/invoices/9/journal')
    expect(screen.getByText('Preview — not posted')).toBeTruthy()
    expect(screen.getByText('The entry Posting will create — nothing is written until you post this invoice.')).toBeTruthy()
    expect(screen.getByText('1100 — Accounts Receivable')).toBeTruthy()
    expect(screen.getByText('4100 — Sales Revenue')).toBeTruthy()
    expect(screen.getByText('Balanced')).toBeTruthy()
  })

  it('shows the actual entry and links each posted line to the account ledger', async () => {
    apiGet.mockImplementation(async (url: string) => {
      if (url === '/invoices/9') return { data: { ...baseInvoice, status: 'posted' } }
      if (url === '/invoices/9/journal') return { data: actualPayload }
      return { data: { data: [] } }
    })

    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)

    fireEvent.mouseDown(screen.getByRole('tab', { name: /journal/i }))

    expect(await screen.findByText('Journal entry JE-2026-0007')).toBeTruthy()

    const link = screen.getByRole('link', { name: /1100 — Accounts Receivable/ })
    expect(link.getAttribute('href')).toBe(
      '/accounting/reporting/general-ledger?account_id=41&start_date=2026-02-01&end_date=2026-02-01',
    )

    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/invoices/9/journal'))
  })

  it('surfaces a setup error on the preview instead of hiding it', async () => {
    apiGet.mockImplementation(async (url: string) => {
      if (url === '/invoices/9') return { data: { ...baseInvoice, status: 'draft' } }
      if (url === '/invoices/9/journal') {
        return {
          data: {
            ...previewPayload,
            error: 'Account role(s) could not be resolved to a chart_of_accounts.id: sales_revenue.',
            lines: [],
            total_debit: 0,
            total_credit: 0,
            is_balanced: false,
          },
        }
      }
      if (url === '/invoices/9/preflight') {
        return { data: { ready: true, passed: 8, total: 8, checks: [] } }
      }
      return { data: { data: [] } }
    })

    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)

    fireEvent.mouseDown(screen.getByRole('tab', { name: /journal/i }))

    expect(await screen.findByText('Journal cannot be built yet')).toBeTruthy()
    expect(screen.getByText(/sales_revenue/)).toBeTruthy()
  })
})
