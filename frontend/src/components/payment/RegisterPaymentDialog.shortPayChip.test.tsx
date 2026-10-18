// @vitest-environment jsdom
// #506 (PY4): a short payment within the tenant write-off tolerance offers a
// one-tap chip pre-labelled with the suggested reason (the first configured
// reason) that applies the write-off. Outside the tolerance — or with no
// reason configured — there is no chip and the invoice stays open.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RecordPaymentForm } from './RegisterPaymentDialog'

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

let tolerance = 20
let reasons: Array<{ code: string; label: string; account_id: number }> = []

beforeEach(() => {
  tolerance = 20
  reasons = [
    { code: 'bank_charges', label: 'Bank charges', account_id: 61 },
    { code: 'rounding', label: 'Rounding', account_id: 62 },
  ]
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/7') {
      return { data: { id: 7, invoice_number: 'INV-7', customer_id: 3, balance: 1000, total: 1000 } }
    }
    if (url === '/settings/sales') {
      return { data: { data: { payment: { write_off_tolerance: tolerance, write_off_reasons: reasons } } } }
    }
    if (url === '/payments/fx-preview') {
      return { data: { data: { foreign: false } } }
    }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { id: 99 } })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

async function renderShortPayment(amount: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <RecordPaymentForm unpaidInvoices={[]} initialInvoiceId="7" onSuccess={() => {}} />
    </QueryClientProvider>,
  )
  const amountInput = (await screen.findByLabelText('Amount')) as HTMLInputElement
  await waitFor(() => expect(amountInput.value).toBe('1000'))
  fireEvent.change(amountInput, { target: { value: amount } })
  await screen.findByTestId('payment-difference')
}

describe('RecordPaymentForm short-pay chip (#506)', () => {
  it('within tolerance shows a chip labelled with the suggested reason that applies the write-off in one tap', async () => {
    await renderShortPayment('990')

    const chip = await screen.findByTestId('short-pay-chip')
    expect(chip.textContent).toContain('10.00')
    expect(chip.textContent).toContain('Bank charges')
    expect(chip.getAttribute('aria-pressed')).toBe('false')
    expect((screen.getByRole('radio', { name: /Keep open/ }) as HTMLInputElement).checked).toBe(true)

    fireEvent.click(chip)

    expect(chip.getAttribute('aria-pressed')).toBe('true')
    expect((screen.getByRole('radio', { name: /Mark as fully paid/ }) as HTMLInputElement).checked).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: 'Record Payment' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
      invoice_id: '7',
      amount: '990',
      payment_difference: 'write_off',
      write_off_reason: 'bank_charges',
    }))
  })

  it('outside tolerance shows no chip and keeps the invoice open', async () => {
    tolerance = 5
    await renderShortPayment('990')

    await screen.findByText(/exceeds the write-off tolerance/)
    expect(screen.queryByTestId('short-pay-chip')).toBeNull()
    expect((screen.getByRole('radio', { name: /Keep open/ }) as HTMLInputElement).checked).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: 'Record Payment' }))
    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost.mock.calls[0][1]).not.toHaveProperty('payment_difference')
  })

  it('shows no chip when no write-off reason is configured', async () => {
    reasons = []
    await renderShortPayment('990')

    await screen.findByText(/Write-offs need a tolerance/)
    expect(screen.queryByTestId('short-pay-chip')).toBeNull()
  })
})
