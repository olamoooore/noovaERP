// @vitest-environment jsdom
// #490 (PAY-UX4): for a foreign-currency invoice the payment form shows the
// currency, an editable payment rate defaulted from the rate snapshot, the
// base-currency amount and the expected FX gain/loss; an edited rate is sent
// as `exchange_rate`.
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

// Previews mirror the backend's settlement maths for USD→SGD
// (invoice rate 1.35; snapshot 1.362; edited 1.3311).
function preview(rate: number) {
  const base = Math.round(1000 * rate * 100) / 100
  const fx = Math.round((base - 1350) * 100) / 100
  return {
    currency: 'USD',
    base_currency_code: 'SGD',
    foreign: true,
    exchange_rate: rate,
    rate_source: 'api',
    invoice_rate: 1.35,
    amount: 1000,
    base_amount: base,
    ar_base_amount: 1350,
    fx_amount: fx,
    fx_type: fx > 0 ? 'gain' : fx < 0 ? 'loss' : 'none',
  }
}

beforeEach(() => {
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/invoices/7') {
      return { data: { id: 7, invoice_number: 'INV-7', customer_id: 3, balance: 1000, total: 1000 } }
    }
    if (url === '/payments/fx-preview') {
      const edited = config?.params?.exchange_rate as number | undefined
      return { data: { data: preview(edited ?? 1.362) } }
    }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { id: 99 } })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

function renderForm() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <RecordPaymentForm unpaidInvoices={[]} initialInvoiceId="7" onSuccess={() => {}} />
    </QueryClientProvider>,
  )
}

describe('RecordPaymentForm FX preview (#490)', () => {
  it('shows currency, defaulted rate, base amount and expected gain for a foreign invoice', async () => {
    renderForm()

    const rate = (await screen.findByLabelText(/Payment rate/)) as HTMLInputElement
    await waitFor(() => expect(rate.value).toBe('1.362'))
    expect(screen.getByText('USD')).toBeTruthy()
    expect(screen.getByTestId('fx-base-amount').textContent).toBe('1362.00 SGD')
    expect(screen.getByText(/Expected FX gain/)).toBeTruthy()
    expect(screen.getByTestId('fx-amount').textContent).toBe('12.00 SGD')
  })

  it('re-previews an edited rate and sends it as exchange_rate', async () => {
    renderForm()

    const rate = (await screen.findByLabelText(/Payment rate/)) as HTMLInputElement
    await waitFor(() => expect(rate.value).toBe('1.362'))

    fireEvent.change(rate, { target: { value: '1.3311' } })

    await waitFor(() => expect(screen.getByTestId('fx-amount').textContent).toBe('18.90 SGD'))
    expect(screen.getByText(/Expected FX loss/)).toBeTruthy()
    expect(apiGet).toHaveBeenCalledWith(
      '/payments/fx-preview',
      expect.objectContaining({ params: expect.objectContaining({ exchange_rate: 1.3311 }) }),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Record Payment' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({ invoice_id: '7', exchange_rate: 1.3311 }))
  })

  it('does not send exchange_rate when the defaulted rate is untouched', async () => {
    renderForm()

    const rate = (await screen.findByLabelText(/Payment rate/)) as HTMLInputElement
    await waitFor(() => expect(rate.value).toBe('1.362'))

    fireEvent.click(screen.getByRole('button', { name: 'Record Payment' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost.mock.calls[0][1]).not.toHaveProperty('exchange_rate')
  })
})
