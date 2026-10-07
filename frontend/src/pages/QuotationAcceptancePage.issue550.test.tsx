// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import QuotationAcceptancePage from './QuotationAcceptancePage'

// #550: the portal honours the quotation's signature / prepayment requirements.

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

function snapshot(overrides: Record<string, unknown> = {}) {
  return {
    order_number: 'QUO-550',
    status: 'sent',
    customer_name: 'Carla Co',
    subtotal: 200,
    tax_amount: 0,
    total: 200,
    currency_code: 'SGD',
    order_date: '2026-10-07',
    required_date: null,
    notes: null,
    lines: [],
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
  fireEvent.click(screen.getByRole('checkbox'))
  fireEvent.click(screen.getByRole('button', { name: /submit acceptance/i }))
}

describe('QuotationAcceptancePage (#550)', () => {
  it('does not submit without a signature when the quotation requires one', async () => {
    apiGet.mockResolvedValue({ data: snapshot({ require_signature: true }) })
    renderPage()

    expect(await screen.findByText('Signature (required)')).toBeTruthy()
    await fillAndSubmit()

    expect(apiPost).not.toHaveBeenCalled()
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: 'Signature required' }))
  })

  it('shows the prepayment due before confirmation', async () => {
    apiGet.mockResolvedValue({
      data: snapshot({ require_payment: true, prepayment_percent: 30, prepayment_amount: 60 }),
    })
    renderPage()

    expect(await screen.findByText(/30% of the total/)).toBeTruthy()
    expect(screen.getByText(/confirmed once the prepayment has been received/)).toBeTruthy()
  })
})
