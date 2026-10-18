// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import CreateCreditNoteDialog from './CreateCreditNoteDialog'

// #522 (CN-UX1): picking an invoice used to copy EVERY line at full quantity,
// so a partial credit meant reducing/deleting every other line and a
// forgotten line over-credited the customer. The dialog now offers
// "Full credit / Partial credit"; Partial lists each invoice line with
// invoiced, already credited and "to credit" (default 0) and only lines with
// a quantity are sent. Remaining quantity counts POSTED credit notes only —
// the same rule the #513 per-line cap enforces at post.

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

const { mutateAsync, invoice, priorNotes } = vi.hoisted(() => ({
  mutateAsync: vi.fn(async () => ({})),
  invoice: {
    id: 42,
    invoice_number: 'INV-0042',
    customer_id: 9,
    customer: { name: 'Acme' },
    total: 742,
    balance: 742,
    status: 'posted',
    currency_code: 'MYR',
    lines: [
      {
        id: 101,
        product_id: 1,
        product_name: 'Widget',
        description: 'Widget',
        quantity: '5.00',
        unit_price: '100.00',
        tax_rate: '6.00',
        tax_inclusive: false,
        discount_amount: '0.00',
        line_total: '530.00',
        account_id: null,
      },
      {
        id: 102,
        product_id: 2,
        product_name: 'Gadget',
        description: 'Gadget',
        quantity: '2.00',
        unit_price: '100.00',
        tax_rate: '6.00',
        tax_inclusive: false,
        discount_amount: '0.00',
        line_total: '212.00',
        account_id: null,
      },
    ],
  },
  priorNotes: [
    // Posted: 1 Widget already credited — counts against the remainder.
    { id: 1, status: 'posted', lines: [{ invoice_line_id: 101, quantity: '1.00' }] },
    // Draft and voided notes do not count (the #513 cap ignores them).
    { id: 2, status: 'draft', lines: [{ invoice_line_id: 101, quantity: '3.00' }] },
    { id: 3, status: 'voided', lines: [{ invoice_line_id: 102, quantity: '2.00' }] },
  ],
}))

vi.mock('@/lib/api', () => ({
  default: {
    get: vi.fn(async (url: string) => {
      if (url === '/invoices/42') return { data: invoice }
      if (url === '/accounting/customers/invoices/42/credit-notes') return { data: { data: priorNotes } }
      return { data: { data: [] } }
    }),
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
  },
}))
vi.mock('@/hooks/useCustomerCreditNotes', () => ({
  useCreateCreditNote: () => ({ mutateAsync, isPending: false }),
}))
vi.mock('@/components/ui/CustomerSearchCombobox', () => ({
  CustomerSearchCombobox: () => null,
}))

afterEach(() => {
  cleanup()
  mutateAsync.mockClear()
})

function renderDialog() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <CreateCreditNoteDialog invoiceId={42} onClose={() => {}} onSuccess={() => {}} />
    </QueryClientProvider>,
  )
}

async function rowFor(description: string) {
  const cell = await screen.findByText(description)
  return cell.closest('tr') as HTMLTableRowElement
}

function submit() {
  fireEvent.click(screen.getByRole('button', { name: 'Create Credit Note' }))
}

describe('#522 — full vs partial credit', () => {
  it('Partial credit lists invoiced / already credited / to credit (default 0) and sends only lines with a quantity', async () => {
    renderDialog()

    fireEvent.click(await screen.findByRole('button', { name: 'Partial credit' }))

    const widget = await rowFor('Widget')
    await waitFor(() => expect(within(widget).getByLabelText('Already credited').textContent).toBe('1'))
    expect(within(widget).getByLabelText('Invoiced').textContent).toBe('5')
    const widgetQty = within(widget).getByLabelText('Quantity to credit for Widget') as HTMLInputElement
    expect(widgetQty.value).toBe('0')

    const gadget = await rowFor('Gadget')
    expect(within(gadget).getByLabelText('Already credited').textContent).toBe('0')
    expect((within(gadget).getByLabelText('Quantity to credit for Gadget') as HTMLInputElement).value).toBe('0')

    fireEvent.change(widgetQty, { target: { value: '2' } })
    submit()

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1))
    const payload = (mutateAsync.mock.calls[0] as unknown[])[0] as { lines: Array<Record<string, unknown>> }
    expect(payload.lines).toHaveLength(1)
    expect(payload.lines[0]).toMatchObject({ invoice_line_id: 101, quantity: 2, unit_price: 100 })
  })

  it('Partial credit with nothing to credit is blocked', async () => {
    renderDialog()
    fireEvent.click(await screen.findByRole('button', { name: 'Partial credit' }))
    await rowFor('Widget')

    submit()

    await new Promise((r) => setTimeout(r, 20))
    expect(mutateAsync).not.toHaveBeenCalled()
  })

  it('a quantity above the remaining creditable quantity is flagged and not submitted', async () => {
    renderDialog()
    fireEvent.click(await screen.findByRole('button', { name: 'Partial credit' }))

    const widget = await rowFor('Widget')
    await waitFor(() => expect(within(widget).getByLabelText('Already credited').textContent).toBe('1'))
    // 5 invoiced, 1 already credited → at most 4 remain.
    fireEvent.change(within(widget).getByLabelText('Quantity to credit for Widget'), { target: { value: '5' } })

    expect(within(widget).getByText(/max 4/i)).toBeTruthy()
    submit()

    await new Promise((r) => setTimeout(r, 20))
    expect(mutateAsync).not.toHaveBeenCalled()
  })

  it('Full credit credits the remaining quantity of every line, not the invoiced quantity', async () => {
    renderDialog()
    fireEvent.click(await screen.findByRole('button', { name: 'Full credit' }))

    const widget = await rowFor('Widget')
    await waitFor(() => expect(within(widget).getByLabelText('Already credited').textContent).toBe('1'))

    submit()

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1))
    const payload = (mutateAsync.mock.calls[0] as unknown[])[0] as { lines: Array<Record<string, unknown>> }
    expect(payload.lines).toHaveLength(2)
    expect(payload.lines[0]).toMatchObject({ invoice_line_id: 101, quantity: 4 })
    expect(payload.lines[1]).toMatchObject({ invoice_line_id: 102, quantity: 2 })
    // Payload carries only API fields — no UI bookkeeping.
    expect(payload.lines[0]).not.toHaveProperty('remaining_quantity')
  })
})
