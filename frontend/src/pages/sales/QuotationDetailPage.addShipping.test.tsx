// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QuotationDetailPage } from './QuotationDetailPage'
import { useAuthStore } from '@/store/authStore'

// jsdom polyfills Radix needs at render time
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
  status: 'draft',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  quotation_date: '2026-09-01',
  valid_until: '2026-10-01',
  subtotal: 100,
  tax_amount: 0,
  discount_amount: 0,
  total: 100,
  currency_code: 'USD',
  lines: [
    {
      id: 11,
      line_type: 'product',
      product_id: 7,
      product: { id: 7, name: 'Widget', sku: 'W-1' },
      description: 'Widget',
      quantity: 5,
      unit_price: 20,
      tax_rate: 0,
      line_total: 100,
    },
  ],
}

beforeEach(() => {
  useAuthStore.setState({ role: 'Admin', user: { id: 1, name: 'Admin', email: 'a@b.c' }, token: 't' })

  apiGet.mockReset()
  apiPost.mockReset()
  apiPost.mockResolvedValue({ data: { message: 'Shipping added' } })
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/quotations/5') return { data: quotation }
    if (url === '/sales/delivery-methods') {
      return { data: { data: [{ id: 3, name: 'Standard Courier', pricing_type: 'fixed' }] } }
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

describe('QuotationDetailPage — Add shipping (#409)', () => {
  it('posts the chosen delivery method to the quotation shipping endpoint', async () => {
    renderPage()

    await screen.findAllByText(/QT-2026-0005/)
    fireEvent.click(screen.getByRole('button', { name: /Add shipping/ }))

    const select = (await screen.findByLabelText('Delivery method')) as HTMLSelectElement
    await screen.findByRole('option', { name: 'Standard Courier' })
    fireEvent.change(select, { target: { value: '3' } })

    const dialogButtons = screen.getAllByRole('button', { name: /Add shipping/ })
    fireEvent.click(dialogButtons[dialogButtons.length - 1])

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/quotations/5/shipping', { delivery_method_id: 3 })
    })
  })
})
