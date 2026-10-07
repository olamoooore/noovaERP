// @vitest-environment jsdom
// #495 (SALES-ODOO "Outstanding credits"): an open invoice lists the
// customer's posted credit notes still holding credit (same currency/entity,
// not the invoice's own) and applies all or part of it through
// POST /accounting/customers/credit-notes/{id}/applications.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { useAuthStore } from '@/store/authStore'
import { OutstandingCreditNotesCard } from './OutstandingCreditNotes'

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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: apiPost, put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const outcome = (remaining: number) => ({
  state: 'customer_credit', applied_amount: 0, remaining_credit: remaining, applied_to: null,
})

const NOTES = [
  { id: 7, invoice_id: 2, customer_id: 3, credit_note_number: 'CN-0007', total_amount: 100, status: 'posted', currency_code: 'MYR', legal_entity_id: 1, outcome: outcome(60) },
  { id: 8, invoice_id: 13, customer_id: 3, credit_note_number: 'CN-OWN', total_amount: 50, status: 'posted', currency_code: 'MYR', legal_entity_id: 1, outcome: outcome(50) },
  { id: 9, invoice_id: 4, customer_id: 3, credit_note_number: 'CN-USD', total_amount: 40, status: 'posted', currency_code: 'USD', legal_entity_id: 1, outcome: outcome(40) },
  { id: 10, invoice_id: 5, customer_id: 3, credit_note_number: 'CN-OTHER-ENTITY', total_amount: 30, status: 'posted', currency_code: 'MYR', legal_entity_id: 2, outcome: outcome(30) },
]

beforeEach(() => {
  useAuthStore.setState({ role: 'Accountant', permissions: ['accounting.view', 'accounting.write'] })
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockResolvedValue({ data: { data: NOTES } })
  apiPost.mockResolvedValue({ data: { message: 'ok' } })
})

afterEach(() => {
  cleanup()
})

function wrap(ui: React.ReactElement) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

const card = (balance = 250) => (
  <OutstandingCreditNotesCard customerId={3} invoiceId={13} invoiceNumber="INV-0013" invoiceBalance={balance} currency="MYR" legalEntityId={1} />
)

describe('OutstandingCreditNotesCard (#495)', () => {
  it('lists only credit notes whose credit can settle this invoice', async () => {
    wrap(card())

    const panel = await screen.findByTestId('outstanding-credit-notes')
    expect(within(panel).getByText('CN-0007')).toBeTruthy()
    expect(within(panel).queryByText('CN-OWN')).toBeNull()
    expect(within(panel).queryByText('CN-USD')).toBeNull()
    expect(within(panel).queryByText('CN-OTHER-ENTITY')).toBeNull()
    expect(within(panel).getByTestId('outstanding-credit-notes-total').textContent).toMatch(/60\.00/)
    expect(apiGet).toHaveBeenCalledWith('/accounting/customers/credit-notes', {
      params: { customer_id: 3, status: 'posted', outcome: 'customer_credit' },
    })
  })

  it('applies a partial amount, pre-filled with min(remaining, balance)', async () => {
    wrap(card(45))

    const panel = await screen.findByTestId('outstanding-credit-notes')
    fireEvent.click(within(panel).getByRole('button', { name: /apply to this invoice/i }))

    const dialog = await screen.findByRole('dialog')
    const input = within(dialog).getByLabelText('Credit to apply to INV-0013') as HTMLInputElement
    expect(input.value).toBe('45')

    fireEvent.change(input, { target: { value: '50' } })
    expect(within(dialog).getByText(/exceeds the invoice balance/i)).toBeTruthy()
    expect((within(dialog).getByRole('button', { name: /apply credit/i }) as HTMLButtonElement).disabled).toBe(true)

    fireEvent.change(input, { target: { value: '20' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /apply credit/i }))
    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/accounting/customers/credit-notes/7/applications', {
        applications: [{ invoice_id: 13, amount: 20 }],
      }),
    )
  })

  it('hides the apply action without accounting.write', async () => {
    useAuthStore.setState({ role: 'Viewer', permissions: ['accounting.view'] })
    wrap(card())

    const panel = await screen.findByTestId('outstanding-credit-notes')
    expect(within(panel).getByText('CN-0007')).toBeTruthy()
    expect(within(panel).queryByRole('button', { name: /apply/i })).toBeNull()
  })
})
