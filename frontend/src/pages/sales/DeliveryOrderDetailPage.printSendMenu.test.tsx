// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { DeliveryOrderDetailPage } from './DeliveryOrderDetailPage'

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

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const deliveryOrder = {
  id: 5,
  delivery_order_number: 'DO-2026-0005',
  status: 'confirmed',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali', email: 'billing@klinik.example' },
  delivery_date: '2026-02-01',
  total: 100,
  currency_code: 'MYR',
  lines: [],
  invoices: [],
}

const RESOLUTION = {
  outcome: 'RESOLVED',
  template_id: 'tpl-do',
  template_name: 'Delivery Order Standard',
  rule: { scope_type: 'entity', scope_value: '3' },
  rule_label: 'entity rule',
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/delivery-orders/5') return { data: deliveryOrder }
    if (url.startsWith('/documents/delivery_order/resolution')) {
      return { data: { data: RESOLUTION } }
    }
    if (url.startsWith('/template-builder/templates')) {
      return { data: { data: [{ id: 'tpl-do', name: 'Delivery Order Standard' }] } }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/delivery-orders/5']}>
        <Routes>
          <Route path="/delivery-orders/:id" element={<DeliveryOrderDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DeliveryOrderDetailPage — shared print and send menu (#618)', () => {
  it('offers the menu with an Email action (the existing send flow) and no WhatsApp', async () => {
    renderPage()
    await screen.findAllByText(/DO-2026-0005/)

    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')

    expect(screen.getByText('Preview')).toBeTruthy()
    expect(screen.getByText('Download PDF')).toBeTruthy()
    expect(screen.getByText('Print')).toBeTruthy()
    expect(screen.getByText('Choose template')).toBeTruthy()
    expect(screen.getByText('Email')).toBeTruthy()
    expect(screen.queryByText('WhatsApp')).toBeNull()

    expect(await screen.findByText('Using: Delivery Order Standard (entity rule)')).toBeTruthy()
  })

  it('reuses the existing DO send dialog for the Email action', async () => {
    renderPage()
    await screen.findAllByText(/DO-2026-0005/)
    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')

    fireEvent.click(screen.getByText('Email'))

    expect(await screen.findByText('Send Delivery Order')).toBeTruthy()
  })

  it('previews at the delivery order PDF endpoint with the resolved template', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)

    renderPage()
    await screen.findAllByText(/DO-2026-0005/)
    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')
    await screen.findByText('Using: Delivery Order Standard (entity rule)')

    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/delivery-orders/5/pdf',
        expect.objectContaining({
          params: { template: 'tpl-do' },
          responseType: 'blob',
        }),
      )
    })
    expect(open).toHaveBeenCalled()
  })
})
