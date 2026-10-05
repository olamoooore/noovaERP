// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QuotationDetailPage } from './QuotationDetailPage'
import { useAuthStore } from '@/store/authStore'

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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const quotation = {
  id: 5,
  quotation_number: 'QT-2026-0005',
  status: 'sent',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  quotation_date: '2026-09-01',
  valid_until: '2026-10-01',
  subtotal: 100,
  tax_amount: 0,
  discount_amount: 0,
  total: 100,
  currency_code: 'USD',
  lines: [],
}

const RESOLUTION = {
  outcome: 'RESOLVED',
  template_id: 'tpl-qt',
  template_name: 'Quotation Standard',
  rule: { scope_type: 'tenant', scope_value: null },
  rule_label: 'tenant rule',
}

beforeEach(() => {
  useAuthStore.setState({ role: 'Admin', user: { id: 1, name: 'Admin', email: 'a@b.c' }, token: 't' })
  apiGet.mockReset()
  apiPost.mockReset()
  apiPost.mockResolvedValue({ data: { data: { delivery: { recipient: 'buyer@example.com' } } } })
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/quotations/5') return { data: quotation }
    if (url.startsWith('/documents/quotation/resolution')) {
      return { data: { data: RESOLUTION } }
    }
    if (url.startsWith('/template-builder/templates')) {
      return { data: { data: [{ id: 'tpl-qt', name: 'Quotation Standard' }] } }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotations/5']}>
        <Routes>
          <Route path="/quotations/:id" element={<QuotationDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('QuotationDetailPage — shared print and send menu (#618)', () => {
  it('offers the email send channel for quotations (#334)', async () => {
    renderPage()
    await screen.findAllByText(/QT-2026-0005/)

    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')

    expect(screen.getByText('Preview')).toBeTruthy()
    expect(screen.getByText('Download PDF')).toBeTruthy()
    expect(screen.getByText('Print')).toBeTruthy()
    expect(screen.getByText('Choose template')).toBeTruthy()
    expect(screen.getByText('Email')).toBeTruthy()
    expect(screen.queryByText('WhatsApp')).toBeNull()

    expect(await screen.findByText('Using: Quotation Standard (tenant rule)')).toBeTruthy()
  })

  it('sends the quotation PDF + acceptance link through the send endpoint (#334)', async () => {
    renderPage()
    await screen.findAllByText(/QT-2026-0005/)

    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByText('Email'))

    // The dialog confirms the recipient before the send call.
    fireEvent.change(await screen.findByLabelText('To'), { target: { value: 'buyer@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/quotations/5/send', {
        recipient_email: 'buyer@example.com',
        cc: undefined,
      })
    })
  })

  it('previews at the quotation PDF endpoint with the resolved template', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)

    renderPage()
    await screen.findAllByText(/QT-2026-0005/)
    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')
    await screen.findByText('Using: Quotation Standard (tenant rule)')

    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/quotations/5/pdf',
        expect.objectContaining({
          params: { template: 'tpl-qt' },
          responseType: 'blob',
        }),
      )
    })
    expect(open).toHaveBeenCalled()
  })
})
