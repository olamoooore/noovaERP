// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import QuotationAcceptancePage from './QuotationAcceptancePage'

// #414 (DX4): the customer can accept a subset of the quotation's lines; the
// rest stays open as a new revision.

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

const { apiGet, apiPost, toast } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn(), toast: vi.fn() }))
vi.mock('@/lib/api', () => ({ default: { get: apiGet, post: apiPost } }))
vi.mock('@/hooks/use-toast', () => ({ useToast: () => ({ toast }) }))

const lines = [
  { id: 11, line_type: 'product', description: 'Widget', quantity: 2, unit_price: 100, line_total: 200 },
  { id: 12, line_type: 'product', description: 'Add-on', quantity: 1, unit_price: 50, line_total: 54.5 },
  { id: 13, line_type: 'product', description: 'Extra', quantity: 3, unit_price: 10, line_total: 32.7 },
]

function snapshot(overrides: Record<string, unknown> = {}) {
  return {
    order_number: 'QUO-414',
    status: 'sent',
    customer_name: 'Carla Co',
    subtotal: 280,
    tax_amount: 7.2,
    total: 287.2,
    currency_code: 'SGD',
    order_date: '2026-10-07',
    required_date: null,
    notes: null,
    lines,
    already_accepted: false,
    signer_name: null,
    signer_email: null,
    accepted_at: null,
    ...overrides,
  }
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotation-accept/tok']}>
        <Routes>
          <Route path="/quotation-accept/:token" element={<QuotationAcceptancePage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  toast.mockReset()
  HTMLCanvasElement.prototype.getContext = (() => null) as unknown as typeof HTMLCanvasElement.prototype.getContext
})

afterEach(cleanup)

async function fillAndSubmit() {
  fireEvent.change(await screen.findByLabelText('Full name'), { target: { value: 'Carla' } })
  fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'carla@example.com' } })
  fireEvent.click(screen.getByLabelText(/I agree that the quotation details/))
  fireEvent.click(screen.getByRole('button', { name: /submit acceptance/i }))
}

describe('QuotationAcceptancePage (#414 partial acceptance)', () => {
  it('sends only the lines the customer kept ticked and reports the remainder revision', async () => {
    apiGet.mockResolvedValue({ data: snapshot() })
    apiPost.mockResolvedValue({
      data: { status: 'confirmed', remainder_quotation_number: 'QUO-414-R02', accepted_line_ids: [11, 12] },
    })
    renderPage()

    const extra = await screen.findByLabelText('Accept line: Extra')
    expect((extra as HTMLButtonElement).getAttribute('aria-checked')).toBe('true')
    fireEvent.click(extra)
    expect(screen.getByText(/2 of 3 lines/)).toBeTruthy()

    await fillAndSubmit()

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost.mock.calls[0][1]).toEqual(expect.objectContaining({ accepted_line_ids: [11, 12] }))
    await waitFor(() =>
      expect(toast).toHaveBeenCalledWith(expect.objectContaining({ description: expect.stringContaining('QUO-414-R02') })),
    )
  })

  it('accepting every line sends a whole-quotation acceptance', async () => {
    apiGet.mockResolvedValue({ data: snapshot() })
    apiPost.mockResolvedValue({ data: { status: 'confirmed' } })
    renderPage()

    await fillAndSubmit()

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost.mock.calls[0][1]).not.toHaveProperty('accepted_line_ids')
  })

  it('cannot submit with no line ticked', async () => {
    apiGet.mockResolvedValue({ data: snapshot() })
    renderPage()

    for (const line of lines) {
      fireEvent.click(await screen.findByLabelText(`Accept line: ${line.description}`))
    }
    await fillAndSubmit()

    expect(apiPost).not.toHaveBeenCalled()
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: 'Select at least one line' }))
  })
})
