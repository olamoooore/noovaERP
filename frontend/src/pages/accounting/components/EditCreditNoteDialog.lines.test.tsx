// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import EditCreditNoteDialog from './EditCreditNoteDialog'

// #518 (CN9): a draft credit note's lines must be editable — the dialog sends
// the edited lines so the server can recalculate totals and tax.

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

// A stable query result, as react-query returns: a fresh object per render
// would re-fire the dialog's [creditNote] effect forever.
const { mutateAsync, query } = vi.hoisted(() => ({
  mutateAsync: vi.fn(async () => ({})),
  query: {
    isLoading: false,
    data: {
      id: 7,
      credit_note_number: 'CN-0007',
      credit_note_date: '2026-10-01',
      reason: 'Return',
      notes: '',
      status: 'draft',
      lines: [
        {
          invoice_line_id: 11,
          product_id: null,
          description: 'Widget',
          quantity: '1.00',
          unit_price: '100.00',
          tax_rate: '6.00',
          tax_inclusive: false,
          discount_amount: '0.00',
          account_id: null,
        },
      ],
    },
  },
}))

vi.mock('@/hooks/useCustomerCreditNotes', () => ({
  useUpdateCreditNote: () => ({ mutateAsync, isPending: false }),
  useCustomerCreditNote: () => query,
}))
vi.mock('@/lib/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))

afterEach(() => {
  cleanup()
  mutateAsync.mockClear()
})

describe('#518 — Edit Credit Note lines', () => {
  it('sends the edited line quantity on save', async () => {
    render(<EditCreditNoteDialog creditNoteId={7} onClose={() => {}} onSuccess={() => {}} />)

    const qty = screen.getByLabelText('Quantity for line 1') as HTMLInputElement
    expect(qty.value).toBe('1')
    fireEvent.change(qty, { target: { value: '3' } })

    fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1))
    const arg = (mutateAsync.mock.calls[0] as unknown as [{ id: number; creditNote: { lines: Array<Record<string, unknown>> } }])[0]
    expect(arg.id).toBe(7)
    expect(arg.creditNote.lines).toHaveLength(1)
    expect(arg.creditNote.lines[0]).toMatchObject({ invoice_line_id: 11, quantity: 3, unit_price: 100 })
    // Linked lines inherit their tax rate server-side (CN5).
    expect(arg.creditNote.lines[0]).not.toHaveProperty('tax_rate')
  })
})
