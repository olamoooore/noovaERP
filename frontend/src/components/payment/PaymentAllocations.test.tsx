// @vitest-environment jsdom
// #488 (PAY-UX2): the #155 split-payment / unapplied-credit backend gets a UI —
// a payment shows its applications and unapplied amount, "Allocate" lists the
// customer's open POSTED invoices and posts to /payments/{id}/allocations, and
// a customer's unapplied payments can be listed (customer page / invoice).
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { useAuthStore } from '@/store/authStore'
import { PaymentAllocationsPanel, UnappliedCreditsCard } from './PaymentAllocations'

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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: apiPost, put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const ALLOCATIONS = {
  data: [
    { id: 1, payment_id: 40, invoice_id: 11, applied_amount: '100.00', reversed_at: null, created_at: '2026-03-01T00:00:00Z', invoice: { id: 11, invoice_number: 'INV-0011' } },
    { id: 2, payment_id: 40, invoice_id: 10, applied_amount: '30.00', reversed_at: '2026-03-02T00:00:00Z', created_at: '2026-03-01T00:00:00Z', invoice: { id: 10, invoice_number: 'INV-0010' } },
  ],
  amount: 500,
  applied_amount: 130,
  unapplied_amount: 400,
}

const ELIGIBLE = [
  { id: 12, invoice_number: 'INV-0012', invoice_date: '2026-02-01', total: 200, balance: 200, status: 'sent', lifecycle_status: 'posted', customer_id: 3 },
  { id: 13, invoice_number: 'INV-0013', invoice_date: '2026-02-02', total: 300, balance: 250, status: 'partial', lifecycle_status: 'posted', customer_id: 3 },
  { id: 14, invoice_number: 'INV-0014-DRAFT', invoice_date: '2026-02-03', total: 90, balance: 90, status: 'sent', lifecycle_status: 'draft', customer_id: 3 },
]

beforeEach(() => {
  useAuthStore.setState({ role: 'Accountant', permissions: ['accounting.view', 'accounting.write'] })
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/payments/40/allocations') return { data: ALLOCATIONS }
    if (url === '/payments/eligible-invoices') {
      expect(config?.params?.customer_id).toBe(3)
      return { data: { data: ELIGIBLE } }
    }
    if (url === '/payments') {
      return {
        data: {
          data: [
            { id: 40, payment_number: 'PAY-0040', payment_date: '2026-03-01', amount: '500.00', unapplied_amount: '400.00', customer_id: 3, payment_status: 'applied', currency: 'MYR' },
            { id: 41, payment_number: 'PAY-0041', payment_date: '2026-03-05', amount: '80.00', unapplied_amount: '80.00', customer_id: 3, payment_status: 'applied', currency: 'MYR' },
          ],
          total: 2,
        },
      }
    }
    return { data: { data: [] } }
  })
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

describe('PaymentAllocationsPanel (#488)', () => {
  it('shows the applications, the applied total and the unapplied amount', async () => {
    wrap(<PaymentAllocationsPanel paymentId={40} customerId={3} paymentStatus="applied" currency="MYR" />)

    const panel = await screen.findByTestId('payment-allocations')
    await within(panel).findByText('INV-0011')
    expect(within(panel).getByText('INV-0010')).toBeTruthy()
    expect(within(panel).getByText(/reversed/i)).toBeTruthy()
    expect(within(panel).getByTestId('unapplied-amount').textContent).toMatch(/400\.00/)
    // Applied counts live applications only (the reversed 30 is excluded).
    expect(within(panel).getByTestId('applied-amount').textContent).toMatch(/100\.00/)
  })

  it('Allocate lists only the customer\'s open posted invoices and posts the split', async () => {
    wrap(<PaymentAllocationsPanel paymentId={40} customerId={3} paymentStatus="applied" currency="MYR" />)

    fireEvent.click(await screen.findByRole('button', { name: /^allocate$/i }))
    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('INV-0012')
    expect(within(dialog).getByText('INV-0013')).toBeTruthy()
    expect(within(dialog).queryByText('INV-0014-DRAFT')).toBeNull()

    fireEvent.change(within(dialog).getByLabelText('Amount to apply to INV-0012'), { target: { value: '200' } })
    fireEvent.change(within(dialog).getByLabelText('Amount to apply to INV-0013'), { target: { value: '150' } })
    expect(within(dialog).getByTestId('allocation-remaining').textContent).toMatch(/50\.00/)

    fireEvent.click(within(dialog).getByRole('button', { name: /apply allocation/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/payments/40/allocations', {
        applications: [
          { invoice_id: 12, amount: 200 },
          { invoice_id: 13, amount: 150 },
        ],
      }),
    )
  })

  it('blocks an allocation above the unapplied amount or an invoice balance', async () => {
    wrap(<PaymentAllocationsPanel paymentId={40} customerId={3} paymentStatus="applied" currency="MYR" />)

    fireEvent.click(await screen.findByRole('button', { name: /^allocate$/i }))
    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('INV-0012')

    fireEvent.change(within(dialog).getByLabelText('Amount to apply to INV-0012'), { target: { value: '250' } })
    expect(within(dialog).getByText(/exceeds the invoice balance/i)).toBeTruthy()
    expect((within(dialog).getByRole('button', { name: /apply allocation/i }) as HTMLButtonElement).disabled).toBe(true)

    fireEvent.change(within(dialog).getByLabelText('Amount to apply to INV-0012'), { target: { value: '200' } })
    fireEvent.change(within(dialog).getByLabelText('Amount to apply to INV-0013'), { target: { value: '250' } })
    expect(within(dialog).getByText(/exceeds the unapplied amount/i)).toBeTruthy()
    expect((within(dialog).getByRole('button', { name: /apply allocation/i }) as HTMLButtonElement).disabled).toBe(true)
    expect(apiPost).not.toHaveBeenCalled()
  })

  it('hides Allocate without accounting.write, or when the payment is not posted', async () => {
    useAuthStore.setState({ role: 'Viewer', permissions: ['accounting.view'] })
    const { unmount } = wrap(<PaymentAllocationsPanel paymentId={40} customerId={3} paymentStatus="applied" currency="MYR" />)
    await screen.findByText('INV-0011')
    expect(screen.queryByRole('button', { name: /^allocate$/i })).toBeNull()
    unmount()

    useAuthStore.setState({ role: 'Accountant', permissions: ['accounting.view', 'accounting.write'] })
    wrap(<PaymentAllocationsPanel paymentId={40} customerId={3} paymentStatus="recorded" currency="MYR" />)
    await screen.findByText('INV-0011')
    expect(screen.queryByRole('button', { name: /^allocate$/i })).toBeNull()
  })
})

describe('UnappliedCreditsCard (#488)', () => {
  it('lists the customer\'s payments holding unapplied credit with the total', async () => {
    wrap(<UnappliedCreditsCard customerId={3} />)

    const card = await screen.findByTestId('unapplied-credits')
    await within(card).findByText('PAY-0040')
    expect(within(card).getByText('PAY-0041')).toBeTruthy()
    expect(within(card).getByTestId('unapplied-credits-total').textContent).toMatch(/480\.00/)
    expect(apiGet).toHaveBeenCalledWith('/payments', { params: expect.objectContaining({ unapplied: 1, customer_id: 3 }) })
  })

  it('on an invoice, Apply opens the allocation pre-filled for that invoice', async () => {
    wrap(<UnappliedCreditsCard customerId={3} invoiceId={13} invoiceBalance={250} />)

    const card = await screen.findByTestId('unapplied-credits')
    await within(card).findByText('PAY-0041')
    fireEvent.click(within(card).getAllByRole('button', { name: /apply to this invoice/i })[1])

    const dialog = await screen.findByRole('dialog')
    const input = (await within(dialog).findByLabelText('Amount to apply to INV-0013')) as HTMLInputElement
    // min(unapplied 80, balance 250)
    await waitFor(() => expect(input.value).toBe('80'))
    fireEvent.click(within(dialog).getByRole('button', { name: /apply allocation/i }))
    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/payments/41/allocations', {
        applications: [{ invoice_id: 13, amount: 80 }],
      }),
    )
  })

  it('renders nothing on an invoice when the customer has no unapplied credit', async () => {
    apiGet.mockImplementation(async () => ({ data: { data: [], total: 0 } }))
    const { container } = wrap(<UnappliedCreditsCard customerId={3} invoiceId={13} invoiceBalance={250} hideWhenEmpty />)
    await waitFor(() => expect(apiGet).toHaveBeenCalled())
    expect(container.querySelector('[data-testid="unapplied-credits"]')).toBeNull()
  })
})
