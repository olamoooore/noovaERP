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

const postedInvoice = {
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
  written_off_amount: 0,
  notes: '',
  terms: '',
  created_at: '2026-02-01T09:00:00Z',
  lines: [],
  payments: [],
  delivery_orders: [],
  evidence: [],
  write_offs: [],
}

const expenseAccounts = [
  { id: 73, code: '5900', name: 'Bad Debt Expense', type: 'expense', is_active: true },
  { id: 74, code: '5910', name: 'Sundry Expense', type: 'expense', is_active: true },
]

const previewPayload = {
  status: 'draft',
  source: 'preview',
  preview: true,
  error: null,
  journal_entry: null,
  posting_date: '2026-02-01',
  description: 'Bad debt write-off - Invoice INV-2026-0009',
  reference: 'INV-2026-0009',
  legal_entity: { id: 3, name: 'Klinik Entity', code: 'KE-1' },
  currency: { document: 'MYR', base: 'MYR', exchange_rate: 1 },
  lines: [
    {
      account_id: 73,
      account_code: '5900',
      account_name: 'Bad Debt Expense',
      debit: 100,
      credit: 0,
      description: 'Bad debt write-off - Invoice INV-2026-0009',
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
      account_code: '1200',
      account_name: 'Accounts Receivable',
      debit: 0,
      credit: 100,
      description: 'Bad debt write-off - Invoice INV-2026-0009',
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

beforeEach(() => {
  useAuthStore.setState({
    role: 'Accountant',
    user: { id: 2, name: 'Acct', email: 'a@b.c' },
    token: 't',
    permissions: ['accounting.post', 'accounting.view', 'invoices.view'],
  })
  apiGet.mockReset()
  apiPost.mockReset()
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/invoices/9/write-off/preview') return { data: previewPayload }
    if (url === '/invoices/9/write-off') {
      return {
        data: {
          message: 'Invoice written off',
          data: { ...postedInvoice, status: 'written_off', balance: 0, written_off_amount: 100 },
          write_off: { id: 1, invoice_id: 9, amount: 100, journal_entry_id: 55 },
        },
      }
    }
    return { data: {} }
  })
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/9') return { data: postedInvoice }
    if (url === '/accounting/chart-of-accounts') return { data: { data: expenseAccounts } }
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

describe('InvoiceDetailPage — write-off action (#886)', () => {
  it('shows the bad-debt journal preview, then posts the write-off with reason and account', async () => {
    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)

    fireEvent.click(screen.getByRole('button', { name: /write off/i }))
    expect(await screen.findByText('Write off invoice')).toBeTruthy()

    const amount = screen.getByLabelText(/^amount/i) as HTMLInputElement
    expect(amount.value).toBe('100')

    expect(await screen.findByRole('option', { name: /Bad Debt Expense/ })).toBeTruthy()
    fireEvent.change(screen.getByLabelText(/bad-debt expense account/i), { target: { value: '73' } })

    expect(await screen.findByText('Journal preview')).toBeTruthy()
    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/invoices/9/write-off/preview', { amount: 100, account_id: 73 }),
    )
    expect(screen.getAllByText('5900 — Bad Debt Expense').length).toBeGreaterThanOrEqual(2)
    expect(screen.getByText('1200 — Accounts Receivable')).toBeTruthy()
    expect(screen.getByText('Balanced')).toBeTruthy()

    fireEvent.change(screen.getByLabelText(/reason/i), { target: { value: 'Customer insolvent' } })
    fireEvent.click(screen.getByRole('button', { name: /confirm write-off/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/invoices/9/write-off', {
        amount: 100,
        reason: 'Customer insolvent',
        account_id: 73,
      }),
    )
    await waitFor(() => expect(screen.queryByText('Write off invoice')).toBeNull())
  })

  it('shows a Written off chip that links to the write-off journal entry', async () => {
    apiGet.mockImplementation(async (url: string) => {
      if (url === '/invoices/9') {
        return {
          data: {
            ...postedInvoice,
            status: 'written_off',
            balance: 0,
            written_off_amount: 100,
            write_offs: [
              {
                id: 1,
                invoice_id: 9,
                amount: 100,
                recovered_amount: 0,
                reason: 'Customer insolvent',
                account_id: 73,
                journal_entry_id: 55,
                created_by: 2,
                created_at: '2026-02-10T09:00:00Z',
                updated_at: '2026-02-10T09:00:00Z',
              },
            ],
          },
        }
      }
      return { data: { data: [] } }
    })

    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)

    const chip = await screen.findByRole('link', { name: /written off/i })
    expect(chip.getAttribute('href')).toBe('/accounting/core/journal-entries?entry_id=55')
    expect(screen.queryByRole('button', { name: /^write off$/i })).toBeNull()
  })

  it('hides the write-off action without accounting.post', async () => {
    useAuthStore.setState({ role: 'Accountant', permissions: ['invoices.view'] })

    renderPage()
    await screen.findByText(/Invoice INV-2026-0009/)

    expect(screen.queryByRole('button', { name: /write off/i })).toBeNull()
  })
})
