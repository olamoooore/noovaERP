// @vitest-environment jsdom
// #489 (PAY-UX3): customer-first payment entry in the shared dialog — pick the
// customer, see their open invoices, type the received amount and it is
// allocated oldest-due first (each row editable); the remainder is unapplied
// credit. One POST /payments/receipts creates, posts and allocates atomically.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useAuthStore } from '@/store/authStore'
import { RegisterPaymentDialog } from './RegisterPaymentDialog'
import { allocateOldestFirst } from '@/lib/paymentAllocation'

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
  default: { get: apiGet, post: apiPost, put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

vi.mock('@/lib/dateOnly', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/dateOnly')>()),
  todayDateOnly: () => '2026-03-15',
}))

// Returned newest-first (as the API orders by invoice date) — the UI must
// still allocate by oldest DUE date.
const openInvoices = [
  { id: 3, invoice_number: 'INV-C', invoice_date: '2026-03-01', due_date: '2026-03-25', balance: 500, total: 500, lifecycle_status: 'posted', customer_id: 1, foreign: false },
  { id: 2, invoice_number: 'INV-B', invoice_date: '2026-02-01', due_date: '2026-03-10', balance: 400, total: 400, lifecycle_status: 'posted', customer_id: 1, foreign: false },
  { id: 1, invoice_number: 'INV-A', invoice_date: '2026-01-15', due_date: '2026-02-23', balance: 300, total: 300, lifecycle_status: 'posted', customer_id: 1, foreign: false },
  { id: 4, invoice_number: 'INV-USD', invoice_date: '2026-01-01', due_date: '2026-01-31', balance: 100, total: 100, lifecycle_status: 'posted', customer_id: 1, foreign: true, currency_code: 'USD' },
  { id: 5, invoice_number: 'INV-DRAFT', invoice_date: '2026-01-01', due_date: '2026-01-31', balance: 90, total: 90, lifecycle_status: 'draft', customer_id: 1, foreign: false },
]

beforeEach(() => {
  useAuthStore.setState({
    role: 'Accountant',
    user: { id: 2, name: 'Acct', email: 'a@b.c' },
    token: 't',
    permissions: ['accounting.view', 'accounting.write', 'accounting.post'],
  })
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/payments/eligible-invoices') return { data: { data: openInvoices } }
    if (url === '/customers/1') return { data: { data: { id: 1, name: 'ABC Sdn Bhd' } } }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { id: 77, applied_amount: 1200, unapplied_amount: 50 } })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

function renderDialog(props: Partial<React.ComponentProps<typeof RegisterPaymentDialog>> = {}) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <RegisterPaymentDialog onOpenChange={() => {}} customerId={1} {...props} />
    </QueryClientProvider>,
  )
}

describe('allocateOldestFirst (#489)', () => {
  it('fills the oldest-due invoices first and skips foreign-currency ones', () => {
    expect(allocateOldestFirst(openInvoices.slice(0, 4), 1050)).toEqual({ 1: 300, 2: 400, 3: 350 })
    expect(allocateOldestFirst(openInvoices.slice(0, 4), 250)).toEqual({ 1: 250 })
    expect(allocateOldestFirst(openInvoices.slice(0, 4), 0)).toEqual({})
  })
})

describe('RegisterPaymentDialog — customer-first (#489 PAY-UX3)', () => {
  it('lists the open invoices oldest-due first and allocates the typed amount; the rest is unapplied credit', async () => {
    renderDialog()
    const dialog = await screen.findByRole('dialog')
    const table = await within(dialog).findByTestId('receipt-open-invoices')
    await within(table).findByText('INV-A')

    const rows = within(table).getAllByRole('row').slice(1)
    expect(rows.map((r) => within(r).getAllByRole('cell')[0].textContent)).toEqual(['INV-USD', 'INV-A', 'INV-B', 'INV-C'])
    expect(within(table).queryByText('INV-DRAFT')).toBeNull()
    // days overdue against 2026-03-15
    expect(within(rows[1]).getByText('20')).toBeTruthy()

    // interaction 1: amount
    fireEvent.change(within(dialog).getByLabelText(/^amount received/i), { target: { value: '1250' } })

    await waitFor(() => expect((within(dialog).getByLabelText('Apply to INV-C') as HTMLInputElement).value).toBe('500'))
    expect((within(dialog).getByLabelText('Apply to INV-A') as HTMLInputElement).value).toBe('300')
    expect((within(dialog).getByLabelText('Apply to INV-B') as HTMLInputElement).value).toBe('400')
    expect(within(dialog).queryByLabelText('Apply to INV-USD')).toBeNull()
    expect(within(dialog).getByTestId('receipt-unapplied').textContent).toContain('50.00')

    // interaction 2: post
    fireEvent.click(within(dialog).getByRole('button', { name: /post receipt/i }))
    await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
    expect(apiPost).toHaveBeenCalledWith('/payments/receipts', expect.objectContaining({
      customer_id: '1',
      amount: '1250',
      payment_date: '2026-03-15',
      method: 'bank_transfer',
      applications: [
        { invoice_id: 1, amount: 300 },
        { invoice_id: 2, amount: 400 },
        { invoice_id: 3, amount: 500 },
      ],
    }))
    expect(apiPost.mock.calls[0][1]).not.toHaveProperty('invoice_id')
  })

  it('lets each applied amount be edited, moving the difference to unapplied credit', async () => {
    renderDialog()
    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('INV-A')
    fireEvent.change(within(dialog).getByLabelText(/^amount received/i), { target: { value: '700' } })
    await waitFor(() => expect((within(dialog).getByLabelText('Apply to INV-B') as HTMLInputElement).value).toBe('400'))

    fireEvent.change(within(dialog).getByLabelText('Apply to INV-B'), { target: { value: '150' } })
    expect(within(dialog).getByTestId('receipt-unapplied').textContent).toContain('250.00')

    fireEvent.click(within(dialog).getByRole('button', { name: /post receipt/i }))
    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost.mock.calls[0][1].applications).toEqual([
      { invoice_id: 1, amount: 300 },
      { invoice_id: 2, amount: 150 },
    ])
  })

  it('blocks posting when the applied amounts exceed the amount received', async () => {
    renderDialog()
    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('INV-A')
    fireEvent.change(within(dialog).getByLabelText(/^amount received/i), { target: { value: '300' } })
    await waitFor(() => expect((within(dialog).getByLabelText('Apply to INV-A') as HTMLInputElement).value).toBe('300'))
    fireEvent.change(within(dialog).getByLabelText('Apply to INV-B'), { target: { value: '100' } })

    expect((within(dialog).getByRole('button', { name: /post receipt/i }) as HTMLButtonElement).disabled).toBe(true)
    expect(within(dialog).getByText(/exceeds the amount received/i)).toBeTruthy()
  })

  it('without accounting.post the dialog stays invoice-first (no posting from the dialog)', async () => {
    useAuthStore.setState({ permissions: ['accounting.view', 'accounting.write'] })
    renderDialog()
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).queryByTestId('receipt-open-invoices')).toBeNull()
    expect(within(dialog).queryByRole('button', { name: /by customer/i })).toBeNull()
    expect(within(dialog).getByRole('button', { name: /record payment/i })).toBeTruthy()
  })

  it('the Payments page dialog offers a switch to customer-first entry', async () => {
    renderDialog({ customerId: null, open: true })
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByRole('button', { name: /record payment/i })).toBeTruthy()
    fireEvent.click(within(dialog).getByRole('button', { name: /by customer/i }))
    expect(within(dialog).getByText(/pick a customer/i)).toBeTruthy()
  })
})
