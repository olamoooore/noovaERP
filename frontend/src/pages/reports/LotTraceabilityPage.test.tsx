// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import api from '@/lib/api'
import LotTraceabilityPage from './LotTraceabilityPage'

// jsdom polyfills Radix needs at render time (same as the other smoke suites).
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

vi.mock('@/lib/api', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

const mockedGet = vi.mocked(api.get)

const traceResult = {
  query: { type: 'lot', value: 'LOT-583-A' },
  phi_masked: true,
  timeline: [
    {
      segment: 'supplier_receipt',
      event: 'lot_recorded',
      occurred_at: '2026-08-24 00:00:00',
      reference_type: 'ProductBatch',
      reference_id: 1,
      reference: 'LOT-583-A',
      title: 'Lot LOT-583-A recorded from supplier',
      legal_entity_id: null,
      details: { product_name: 'Traceability Implant', supplier_name: 'Traceable Supplier' },
    },
    {
      segment: 'delivery',
      event: 'delivery_order',
      occurred_at: '2026-09-13 00:00:00',
      reference_type: 'DeliveryOrder',
      reference_id: 5,
      reference: 'DO-583-0001',
      title: 'Delivered to Traceable General Hospital',
      legal_entity_id: null,
      details: { customer_name: 'Traceable General Hospital', patient_name: 'J******e' },
    },
    {
      segment: 'medical_case',
      event: 'medical_case',
      occurred_at: '2026-09-14 00:00:00',
      reference_type: 'MedicalCase',
      reference_id: 7,
      reference: 'CASE-2026-0007',
      title: 'Medical case CASE-2026-0007',
      legal_entity_id: null,
      details: { case_no: 'CASE-2026-0007', patient_name: 'J******e' },
    },
    {
      segment: 'invoice',
      event: 'invoice',
      occurred_at: '2026-09-15 00:00:00',
      reference_type: 'Invoice',
      reference_id: 9,
      reference: 'INV-583-0001',
      title: 'Invoiced INV-583-0001',
      legal_entity_id: 3,
      details: { invoice_number: 'INV-583-0001', customer_name: 'Traceable General Hospital' },
    },
  ],
  gaps: ['Medical-case lines only carry a lot when…'],
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <LotTraceabilityPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mockedGet.mockReset()
})

afterEach(cleanup)

describe('LotTraceabilityPage', () => {
  it('traces a lot through the report endpoint and renders the ordered timeline', async () => {
    mockedGet.mockResolvedValueOnce({ data: { data: traceResult } } as never)

    renderPage()

    fireEvent.change(screen.getByLabelText(/lot \/ batch number/i), {
      target: { value: 'LOT-583-A' },
    })
    fireEvent.click(screen.getByRole('button', { name: /trace/i }))

    await waitFor(() =>
      expect(mockedGet).toHaveBeenCalledWith('/reports/traceability', {
        params: { lot_number: 'LOT-583-A' },
      }),
    )

    expect(await screen.findByText('Lot LOT-583-A recorded from supplier')).toBeTruthy()
    expect(screen.getByText('Delivered to Traceable General Hospital')).toBeTruthy()
    expect(screen.getByText('Medical case CASE-2026-0007')).toBeTruthy()
    expect(screen.getByText('Invoiced INV-583-0001')).toBeTruthy()
    expect(screen.getByText(/patient names hidden/i)).toBeTruthy()
  })
})
