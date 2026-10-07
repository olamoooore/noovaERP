// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import CreateCreditNoteDialog from './CreateCreditNoteDialog'

// #527 (SALES-ODOO): the credit note dialog is a reversal wizard with three
// refund methods. "Partial refund" is the #522 draft credit note (sent through
// the create mutation); "Full refund" and "Full refund and new draft invoice"
// call the credit-note-reversal endpoint, which builds the lines from the
// invoice and posts the credit note; the re-issue mode hands the new draft
// invoice id back to the caller.

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

const { mutateAsync, apiPost, invoice } = vi.hoisted(() => ({
  mutateAsync: vi.fn(async () => ({})),
  apiPost: vi.fn(async (_url: string, body: { mode: string }) => ({
    data: {
      credit_note: { id: 7 },
      invoice: body.mode === 'full_refund_new_draft' ? { id: 77, invoice_number: 'INV-0042-R02' } : null,
    },
  })),
  invoice: {
    id: 42,
    invoice_number: 'INV-0042',
    customer_id: 9,
    customer: { name: 'Acme' },
    total: 530,
    balance: 530,
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
    ],
  },
}))

vi.mock('@/lib/api', () => ({
  default: {
    get: vi.fn(async (url: string) => {
      if (url === '/invoices/42') return { data: invoice }
      return { data: { data: [] } }
    }),
    post: apiPost,
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
  apiPost.mockClear()
})

function renderDialog(props: { onSuccess?: () => void; onReissued?: (id: number) => void } = {}) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <CreateCreditNoteDialog
        invoiceId={42}
        onClose={() => {}}
        onSuccess={props.onSuccess ?? (() => {})}
        onReissued={props.onReissued}
      />
    </QueryClientProvider>,
  )
}

describe('#527 — credit note reversal wizard', () => {
  it('defaults to Partial refund, which creates a draft credit note through the #522 path', async () => {
    renderDialog()
    expect((await screen.findByRole('radio', { name: /Partial refund/ }) as HTMLInputElement).checked).toBe(true)
    expect(screen.getByRole('button', { name: 'Partial credit' })).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Full credit' }))
    await screen.findByText('Widget')
    fireEvent.click(screen.getByRole('button', { name: 'Create Credit Note' }))

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1))
    expect(apiPost).not.toHaveBeenCalled()
  })

  it('Full refund posts through the reversal endpoint and is not a draft', async () => {
    const onSuccess = vi.fn()
    const onReissued = vi.fn()
    renderDialog({ onSuccess, onReissued })
    fireEvent.click(await screen.findByRole('radio', { name: /^Full refund(?! and)/ }))
    await screen.findByText('Widget')
    // The full modes hide the draft-only full/partial toggle.
    expect(screen.queryByRole('button', { name: 'Partial credit' })).toBeNull()
    fireEvent.change(screen.getByPlaceholderText(/Product return/), { target: { value: 'Wrong price' } })

    fireEvent.click(screen.getByRole('button', { name: 'Post Credit Note' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
    const [url, body] = apiPost.mock.calls[0] as unknown as [string, Record<string, unknown>]
    expect(url).toBe('/accounting/customers/invoices/42/credit-note-reversal')
    expect(body).toMatchObject({ mode: 'full_refund', reason: 'Wrong price' })
    expect(mutateAsync).not.toHaveBeenCalled()
    await waitFor(() => expect(onSuccess).toHaveBeenCalledTimes(1))
    expect(onReissued).not.toHaveBeenCalled()
  })

  it('Full refund and new draft invoice hands the new draft back to the caller', async () => {
    const onReissued = vi.fn()
    renderDialog({ onReissued })
    fireEvent.click(await screen.findByRole('radio', { name: /Full refund and new draft invoice/ }))
    await screen.findByText('Widget')

    fireEvent.click(screen.getByRole('button', { name: 'Post & Create Draft Invoice' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
    const [, body] = apiPost.mock.calls[0] as unknown as [string, Record<string, unknown>]
    expect(body).toMatchObject({ mode: 'full_refund_new_draft' })
    await waitFor(() => expect(onReissued).toHaveBeenCalledWith(77))
  })
})
