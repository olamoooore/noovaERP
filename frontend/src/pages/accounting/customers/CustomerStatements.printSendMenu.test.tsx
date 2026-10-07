// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { CustomerStatements } from './CustomerStatements'

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

const CUSTOMER = {
  id: 7,
  code: 'KA-1',
  name: 'Klinik Ali',
  email: 'billing@klinik.example',
  legal_entity_id: 3,
  customer_group: 'clinic',
  language: 'en',
}

const RESOLUTION = {
  outcome: 'RESOLVED',
  template_id: 'tpl-soa',
  template_name: 'Statement of Account',
  rule: { scope_type: 'entity', scope_value: '3' },
  rule_label: 'entity rule',
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/customers/7') return { data: CUSTOMER }
    if (url.startsWith('/customers?')) return { data: { data: [CUSTOMER] } }
    if (url.startsWith('/documents/customer_statement/resolution')) {
      return { data: { data: RESOLUTION } }
    }
    if (url.startsWith('/template-builder/templates')) {
      return { data: { data: [{ id: 'tpl-soa', name: 'Statement of Account' }] } }
    }
    if (url.startsWith('/accounting/customers/transactions')) {
      return { data: { data: [] } }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/accounting/reporting/customer-statements?customer_id=7']}>
        <Routes>
          <Route path="/accounting/reporting/customer-statements" element={<CustomerStatements />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CustomerStatements — shared print and send menu (#618)', () => {
  it('offers the shared menu with the resolved template label and an Email action', async () => {
    renderPage()
    await screen.findByText('Customer Account Statement')

    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')

    expect(screen.getByText('Preview')).toBeTruthy()
    expect(screen.getByText('Download PDF')).toBeTruthy()
    expect(screen.getByText('Print')).toBeTruthy()
    expect(screen.getByText('Choose template')).toBeTruthy()
    // Statements can be emailed through the existing statement endpoint but
    // have no WhatsApp channel.
    expect(screen.getByText('Email')).toBeTruthy()
    expect(screen.queryByText('WhatsApp')).toBeNull()

    expect(await screen.findByText('Using: Statement of Account (entity rule)')).toBeTruthy()
  })

  it('previews from the customer statement endpoint for the selected period', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)

    renderPage()
    await screen.findByText('Customer Account Statement')
    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')

    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        expect.stringContaining('/accounting/customers/7/statement?start_date='),
        expect.objectContaining({
          params: { template: 'tpl-soa' },
          responseType: 'blob',
        }),
      )
    })
    expect(open).toHaveBeenCalled()
  })

  it('uses a custom From/To range for the list and PDF, and rejects To < From (#1018)', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    vi.spyOn(window, 'open').mockReturnValue(null)

    renderPage()
    await screen.findByText('Customer Account Statement')

    const from = screen.getByLabelText('From date') as HTMLInputElement
    const to = screen.getByLabelText('To date') as HTMLInputElement
    fireEvent.change(from, { target: { value: '2026-01-15' } })
    fireEvent.change(to, { target: { value: '2026-02-15' } })

    // The list query uses the custom range crossing the month end.
    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/accounting/customers/transactions',
        expect.objectContaining({
          params: expect.objectContaining({ start_date: '2026-01-15', end_date: '2026-02-15' }),
        }),
      )
    })

    // Preview/PDF use the same range.
    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')
    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        expect.stringContaining('/accounting/customers/7/statement?start_date=2026-01-15&end_date=2026-02-15'),
        expect.anything(),
      )
    })

    // To before From: validation is shown and the print/send action is withheld.
    fireEvent.change(to, { target: { value: '2026-01-01' } })
    expect(await screen.findByText(/To date must be on or after the From date/)).toBeTruthy()
    await waitFor(() => expect(screen.queryByRole('button', { name: /print \/ send/i })).toBeNull())
  })
})
