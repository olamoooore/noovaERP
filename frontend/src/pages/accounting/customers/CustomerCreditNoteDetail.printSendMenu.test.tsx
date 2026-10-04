// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { CustomerCreditNoteDetail } from './CustomerCreditNoteDetail'

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

const creditNote = {
  id: 7,
  credit_note_number: 'CN-2026-0007',
  status: 'draft',
  credit_note_date: '2026-02-01',
  total_amount: 100,
  currency_code: 'MYR',
  customer: { id: 1, name: 'Klinik Ali' },
  invoice: { id: 5, invoice_number: 'INV-2026-0005' },
  lines: [],
}

const RESOLUTION = {
  outcome: 'RESOLVED',
  template_id: 'tpl-cn',
  template_name: 'Credit Note Standard',
  rule: { scope_type: 'customer_group', scope_value: 'clinic' },
  rule_label: 'customer rule',
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/customers/credit-notes/7') return { data: creditNote }
    if (url.startsWith('/documents/credit_note/resolution')) {
      return { data: { data: RESOLUTION } }
    }
    if (url.startsWith('/template-builder/templates')) {
      return { data: { data: [{ id: 'tpl-cn', name: 'Credit Note Standard' }] } }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/accounting/customers/credit-notes/7']}>
        <Routes>
          <Route path="/accounting/customers/credit-notes/:id" element={<CustomerCreditNoteDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CustomerCreditNoteDetail — shared print and send menu (#618)', () => {
  it('offers the menu with the resolved template rule and no send actions', async () => {
    renderPage()
    await screen.findByText(/Credit Note CN-2026-0007/)

    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')

    expect(screen.getByText('Preview')).toBeTruthy()
    expect(screen.getByText('Download PDF')).toBeTruthy()
    expect(screen.getByText('Print')).toBeTruthy()
    expect(screen.getByText('Choose template')).toBeTruthy()
    expect(screen.queryByText('Email')).toBeNull()
    expect(screen.queryByText('WhatsApp')).toBeNull()

    expect(await screen.findByText('Using: Credit Note Standard (customer rule)')).toBeTruthy()
  })

  it('previews at the credit note PDF endpoint with the resolved template', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)

    renderPage()
    await screen.findByText(/Credit Note CN-2026-0007/)
    fireEvent.pointerDown(await screen.findByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
    await screen.findByText('Preview')
    await screen.findByText('Using: Credit Note Standard (customer rule)')

    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/credit-notes/7/pdf',
        expect.objectContaining({
          params: { template: 'tpl-cn' },
          responseType: 'blob',
        }),
      )
    })
    expect(open).toHaveBeenCalled()
  })
})
