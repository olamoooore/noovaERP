// @vitest-environment jsdom
// #494: a short payment shows the payment difference with "keep open" (the
// default — nothing extra is sent) or "mark as fully paid", which sends the
// write-off reason so the backend writes the difference off. The write-off
// option is only offered within the tenant tolerance with a configured reason.
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

beforeEach(() => {
  tolerance = 20
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/7') {
      return { data: { id: 7, invoice_number: 'INV-7', customer_id: 3, balance: 1000, total: 1000 } }
    }
    if (url === '/settings/sales') {
      return {
        data: {
          data: {
            payment: {
              write_off_tolerance: tolerance,
              write_off_reasons: [{ code: 'bank_charges', label: 'Bank charges', account_id: 61 }],
            },
          },
        },
      }
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

describe('RecordPaymentForm payment difference (#494)', () => {
  it('keeps the invoice open by default and sends no write-off', async () => {
    await renderShortPayment('990')

    expect(screen.getByTestId('payment-difference-amount').textContent).toBe('10.00')

    fireEvent.click(screen.getByRole('button', { name: 'Record Payment' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost.mock.calls[0][1]).not.toHaveProperty('payment_difference')
    expect(apiPost.mock.calls[0][1]).not.toHaveProperty('write_off_reason')
  })

  it('marks the invoice fully paid with the chosen reason and label', async () => {
    await renderShortPayment('990')

    const writeOff = screen.getByRole('radio', { name: /Mark as fully paid/ }) as HTMLInputElement
    await waitFor(() => expect(writeOff.disabled).toBe(false))
    fireEvent.click(writeOff)
    fireEvent.change(await screen.findByLabelText('Label (optional)'), { target: { value: 'TT charges' } })

    fireEvent.click(screen.getByRole('button', { name: 'Record Payment' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
      invoice_id: '7',
      amount: '990',
      payment_difference: 'write_off',
      write_off_reason: 'bank_charges',
      write_off_label: 'TT charges',
    }))
  })

  it('does not offer the write-off when the difference exceeds the tolerance', async () => {
    tolerance = 5
    await renderShortPayment('990')

    await screen.findByText(/exceeds the write-off tolerance/)
    const writeOff = screen.getByRole('radio', { name: /Mark as fully paid/ }) as HTMLInputElement
    expect(writeOff.disabled).toBe(true)
  })
})
