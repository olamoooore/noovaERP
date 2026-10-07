// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState } from 'react'
import CreateCreditNoteDialog from './CreateCreditNoteDialog'
import { SalesDocumentLineGrid } from '@/components/sales/SalesDocumentLineGrid'
import {
  emptySalesDocumentLine,
  getSalesDocumentLineTotal,
  type SalesDocumentLine,
} from '@/lib/salesDocumentLines'

// #526 (CN-UX5): the credit note dialog reimplemented line entry and the
// `qty × price × rate` total instead of using the shared sales line editor,
// so discounts / tax-inclusive prices drifted (CN3) and every editor fix had
// to be repeated. Credit notes now use SalesDocumentLineGrid in a "credit"
// mode that adds the invoiced, already credited and to-credit columns, with
// the shared line-total calculation and the #319 keyboard flow.

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
        // 5 × 100, line discount 50, 6% tax on top.
        id: 101,
        product_id: 1,
        product_name: 'Widget',
        description: 'Widget',
        quantity: '5.00',
        unit_price: '100.00',
        tax_rate: '6.00',
        tax_inclusive: false,
        discount_amount: '50.00',
        line_total: '477.00',
        account_id: null,
      },
      {
        // 2 × 106 with 6% tax INCLUDED in the price.
        id: 102,
        product_id: 2,
        product_name: 'Gadget',
        description: 'Gadget',
        quantity: '2.00',
        unit_price: '106.00',
        tax_rate: '6.00',
        tax_inclusive: true,
        discount_amount: '0.00',
        line_total: '212.00',
        account_id: null,
      },
      {
        // Already credited in full by a posted credit note.
        id: 103,
        product_id: 3,
        product_name: 'Gizmo',
        description: 'Gizmo',
        quantity: '1.00',
        unit_price: '10.00',
        tax_rate: '0.00',
        tax_inclusive: false,
        discount_amount: '0.00',
        line_total: '10.00',
        account_id: null,
      },
    ],
  },
  priorNotes: [
    {
      id: 1,
      status: 'posted',
      lines: [
        { invoice_line_id: 101, quantity: '1.00' },
        { invoice_line_id: 103, quantity: '1.00' },
      ],
    },
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

async function loadedRow(description: string) {
  const row = await rowFor('Widget')
  await waitFor(() => expect(within(row).getByLabelText('Already credited').textContent).toBe('1'))
  return description === 'Widget' ? row : rowFor(description)
}

describe('#526 — shared line calculation', () => {
  it('a tax-inclusive line total is the discounted amount (tax extracted, not added)', () => {
    const line: SalesDocumentLine = {
      ...emptySalesDocumentLine(),
      quantity: 2,
      unit_price: 106,
      tax_rate: 6,
      discount_amount: 0,
      tax_inclusive: true,
    }
    expect(getSalesDocumentLineTotal(line)).toBeCloseTo(212, 2)
    // Tax-exclusive lines keep adding tax on the discounted base.
    expect(getSalesDocumentLineTotal({ ...line, tax_inclusive: false })).toBeCloseTo(224.72, 2)
  })
})

describe('#526 — credit note uses the shared sales line editor', () => {
  it('renders the invoice lines in the shared grid with invoiced / already credited / to credit columns', async () => {
    renderDialog()
    const widget = await loadedRow('Widget')

    const table = widget.closest('table') as HTMLTableElement
    // The shared SalesDocumentLineGrid table and row markup, not a bespoke table.
    expect(table.classList.contains('doc-lines-table')).toBe(true)
    expect(widget.classList.contains('doc-line-row')).toBe(true)
    const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent?.trim())
    expect(headers).toEqual(expect.arrayContaining(['Invoiced', 'Already credited', 'To credit', 'Unit Price', 'Disc', 'Line Total']))
    // Credit mode: lines come from the invoice — no product picking, no new lines.
    expect(screen.queryByPlaceholderText(/Add a line/)).toBeNull()
    expect(screen.queryByRole('button', { name: /Add Line/ })).toBeNull()
  })

  it('shows and enforces the creditable quantity per line', async () => {
    renderDialog()
    const widget = await loadedRow('Widget')

    const qty = within(widget).getByLabelText('Quantity to credit for Widget') as HTMLInputElement
    expect(qty.max).toBe('4')
    fireEvent.change(qty, { target: { value: '5' } })
    expect(within(widget).getByText(/max 4/i)).toBeTruthy()
    expect(qty.getAttribute('aria-invalid')).toBe('true')

    const gizmo = await rowFor('Gizmo')
    expect((within(gizmo).getByLabelText('Quantity to credit for Gizmo') as HTMLInputElement).disabled).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: 'Create Credit Note' }))
    await new Promise((r) => setTimeout(r, 20))
    expect(mutateAsync).not.toHaveBeenCalled()
  })

  it('keyboard flow: Tab qty → price, Enter on price moves to the next creditable line', async () => {
    renderDialog()
    const widget = await loadedRow('Widget')
    const gadget = await rowFor('Gadget')

    const widgetQty = within(widget).getByLabelText('Quantity to credit for Widget') as HTMLInputElement
    const widgetPrice = within(widget).getByLabelText('Unit price for Widget') as HTMLInputElement
    const gadgetQty = within(gadget).getByLabelText('Quantity to credit for Gadget') as HTMLInputElement

    widgetQty.focus()
    fireEvent.keyDown(widgetQty, { key: 'Tab' })
    expect(document.activeElement).toBe(widgetPrice)

    fireEvent.keyDown(widgetPrice, { key: 'Enter' })
    expect(document.activeElement).toBe(gadgetQty)

    // Enter on the last creditable line does not add a row.
    const gadgetPrice = within(gadget).getByLabelText('Unit price for Gadget') as HTMLInputElement
    gadgetPrice.focus()
    fireEvent.keyDown(gadgetPrice, { key: 'Enter' })
    expect(screen.getAllByRole('row').filter((row) => row.classList.contains('doc-line-row'))).toHaveLength(3)
  })

  it('line totals and the credit total come from the shared calculation (discount share, tax-inclusive)', async () => {
    renderDialog()
    fireEvent.click(await screen.findByRole('button', { name: 'Full credit' }))
    const widget = await loadedRow('Widget')
    const gadget = await rowFor('Gadget')

    // Widget: 4 remaining × 100, 4/5 of the 50 discount = 40 → 360 + 6% = 381.60.
    expect(within(widget).getByLabelText('Line discount').textContent).toMatch(/40\.00/)
    expect(within(widget).getByLabelText('Line total').textContent).toMatch(/381\.60/)
    // Gadget: 2 × 106 tax-inclusive → 212.00.
    expect(within(gadget).getByLabelText('Line total').textContent).toMatch(/212\.00/)
    expect(screen.getByText('Total:').parentElement?.textContent).toMatch(/593\.60/)

    fireEvent.click(screen.getByRole('button', { name: 'Create Credit Note' }))
    await waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1))
    const payload = (mutateAsync.mock.calls[0] as unknown[])[0] as { lines: Array<Record<string, unknown>> }
    expect(payload.lines).toHaveLength(2)
    expect(payload.lines[0]).toMatchObject({ invoice_line_id: 101, quantity: 4, discount_amount: 40, tax_inclusive: false })
    expect(payload.lines[1]).toMatchObject({ invoice_line_id: 102, quantity: 2, discount_amount: 0, tax_inclusive: true })
  })

  it('partial credit: the discount share follows the quantity to credit', async () => {
    renderDialog()
    const widget = await loadedRow('Widget')
    fireEvent.change(within(widget).getByLabelText('Quantity to credit for Widget'), { target: { value: '2' } })

    // 2 × 100 − 20 (2/5 of 50) = 180 + 6% = 190.80.
    expect(within(widget).getByLabelText('Line discount').textContent).toMatch(/20\.00/)
    expect(within(widget).getByLabelText('Line total').textContent).toMatch(/190\.80/)
    expect(screen.getByText('Total:').parentElement?.textContent).toMatch(/190\.80/)
  })
})

describe('#526 — SalesDocumentLineGrid credit mode', () => {
  function Harness({ initial, readOnly = false }: { initial: SalesDocumentLine[]; readOnly?: boolean }) {
    const [lines, setLines] = useState(initial)
    const [qc] = useState(() => new QueryClient({ defaultOptions: { queries: { retry: false } } }))
    return (
      <QueryClientProvider client={qc}>
        <SalesDocumentLineGrid
          lines={lines}
          setLines={setLines}
          money={(v) => v.toFixed(2)}
          credit={{ readOnly }}
        />
      </QueryClientProvider>
    )
  }
  const creditLine = (overrides: Partial<SalesDocumentLine> = {}): SalesDocumentLine => ({
    ...emptySalesDocumentLine(),
    description: 'Widget',
    quantity: 0,
    unit_price: 100,
    tax_rate: 0,
    discount_amount: 0,
    credit: { invoice_line_id: 101, invoiced_quantity: 5, credited_quantity: 1, invoiced_discount: 0 },
    ...overrides,
  })

  it('adds the invoiced / already credited / to credit columns and drops product entry', () => {
    render(<Harness initial={[creditLine()]} />)
    const row = screen.getByText('Widget').closest('tr') as HTMLTableRowElement
    expect(within(row).getByLabelText('Invoiced').textContent).toBe('5')
    expect(within(row).getByLabelText('Already credited').textContent).toBe('1')
    expect(within(row).getByLabelText('Quantity to credit for Widget')).toBeTruthy()
    expect(screen.queryByRole('button', { name: /Add Line/ })).toBeNull()
    expect(screen.queryByTestId('sales-line-entry')).toBeNull()
  })

  it('read-only (full credit) shows quantities as text, "Fully credited" when nothing remains', () => {
    render(
      <Harness
        readOnly
        initial={[
          creditLine({ quantity: 4 }),
          creditLine({ description: 'Gizmo', quantity: 0, credit: { invoice_line_id: 103, invoiced_quantity: 1, credited_quantity: 1, invoiced_discount: 0 } }),
        ]}
      />,
    )
    expect(screen.queryByLabelText('Quantity to credit for Widget')).toBeNull()
    const gizmo = screen.getByText('Gizmo').closest('tr') as HTMLTableRowElement
    expect(within(gizmo).getByText('Fully credited')).toBeTruthy()
  })
})
