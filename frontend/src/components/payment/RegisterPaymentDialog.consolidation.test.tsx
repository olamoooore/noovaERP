// @vitest-environment jsdom
// #487 (PAY-UX1): one shared RegisterPaymentDialog for recording AND editing a
// draft payment — one payload builder, at most 5 visible fields by default,
// optional fields (reference, notes) behind "More".
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RegisterPaymentDialog } from './RegisterPaymentDialog'
import { buildPaymentPayload } from '@/lib/paymentPayload'

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

const { apiGet, apiPost, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn(), apiPut: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: apiPost, put: apiPut, patch: vi.fn(), delete: vi.fn() },
}))

let methodRows: unknown[] = []

beforeEach(() => {
  methodRows = []
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/7') {
      return { data: { id: 7, invoice_number: 'INV-7', customer_id: 3, balance: 250, total: 250 } }
    }
    if (url === '/accounting/payment-methods') return { data: { data: methodRows } }
    if (url === '/accounting/bank-accounts') {
      return { data: { data: [{ id: 5, account_name: 'Maybank Current', gl_account_code: '1010' }] } }
    }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { id: 99 } })
  apiPut.mockResolvedValue({ data: { data: {} } })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

function renderDialog(props: Partial<React.ComponentProps<typeof RegisterPaymentDialog>>) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <RegisterPaymentDialog invoiceId={null} onOpenChange={() => {}} {...props} />
    </QueryClientProvider>,
  )
}

describe('buildPaymentPayload (#487 one payload builder)', () => {
  const form = {
    customer_id: '3',
    invoice_id: '7',
    amount: '250',
    payment_date: '2026-03-15',
    method: 'bank_transfer',
    reference: 'TT-1',
    notes: '',
    bank_account_id: '5',
  }

  it('casts the deposit account and omits exchange_rate unless edited', () => {
    const payload = buildPaymentPayload(form)
    expect(payload).toMatchObject({ invoice_id: '7', amount: '250', bank_account_id: 5, reference: 'TT-1' })
    expect(payload).not.toHaveProperty('exchange_rate')
  })

  it('sends an empty deposit account as null and an edited rate as exchange_rate', () => {
    const payload = buildPaymentPayload({ ...form, bank_account_id: '' }, { exchangeRate: 1.33 })
    expect(payload.bank_account_id).toBeNull()
    expect(payload.exchange_rate).toBe(1.33)
  })
})

describe('RegisterPaymentDialog — consolidated entry (#487 PAY-UX1)', () => {
  it('shows at most 5 fields by default; reference and notes sit behind "More"', async () => {
    renderDialog({ invoiceId: 7 })
    const dialog = await screen.findByRole('dialog')
    await waitFor(() => expect((within(dialog).getByLabelText(/^amount/i) as HTMLInputElement).value).toBe('250'))

    expect(within(dialog).queryByLabelText(/reference/i)).toBeNull()
    expect(within(dialog).queryByLabelText(/notes/i)).toBeNull()
    const fields = dialog.querySelectorAll('[data-payment-field]')
    expect(fields.length).toBeLessThanOrEqual(5)

    fireEvent.click(within(dialog).getByRole('button', { name: /more/i }))
    expect(within(dialog).getByLabelText(/reference/i)).toBeTruthy()
    expect(within(dialog).getByLabelText(/notes/i)).toBeTruthy()
  })

  it('shows Reference up front when the method master requires one', async () => {
    methodRows = [{ code: 'bank_transfer', label: 'Bank Transfer', requires_reference: true, is_active: true }]
    renderDialog({ invoiceId: 7 })
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByLabelText(/reference/i)).toBeTruthy()
  })

  it('opens without an invoice (Payments page "Record Payment")', async () => {
    renderDialog({ open: true })
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/register payment/i)).toBeTruthy()
    expect(within(dialog).getByLabelText(/^amount/i)).toBeTruthy()
  })

  it('edits a draft payment through the same form and payload builder', async () => {
    const onSuccess = vi.fn()
    renderDialog({
      payment: {
        id: 11,
        invoice_id: 7,
        amount: 120,
        payment_date: '2026-02-10T00:00:00Z',
        method: 'bank_transfer',
        reference: 'OLD-REF',
        notes: null,
        bank_account_id: 5,
      },
      onSuccess,
    })
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/edit draft payment/i)).toBeTruthy()
    expect((within(dialog).getByLabelText(/^amount/i) as HTMLInputElement).value).toBe('120')
    expect((within(dialog).getByLabelText(/payment date/i) as HTMLInputElement).value).toBe('2026-02-10')

    fireEvent.change(within(dialog).getByLabelText(/^amount/i), { target: { value: '150' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(apiPut).toHaveBeenCalled())
    expect(apiPut).toHaveBeenCalledWith('/payments/11', expect.objectContaining({
      invoice_id: '7',
      amount: '150',
      payment_date: '2026-02-10',
      reference: 'OLD-REF',
      bank_account_id: 5,
    }))
    expect(apiPut.mock.calls[0][1]).not.toHaveProperty('exchange_rate')
    expect(apiPost).not.toHaveBeenCalled()
    await waitFor(() => expect(onSuccess).toHaveBeenCalled())
  })
})
