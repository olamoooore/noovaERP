// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SalesOrderDocumentForm } from './SalesOrderDocumentForm'

// #346 (D9): a repeat customer's new order is pre-filled from the server's
// remembered choices (GET /settings/sales/defaults?customer_id=&document=),
// with a "remembered from last order" hint — and never over a value the
// user already typed.

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
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: vi.fn(), put: vi.fn() },
}))

// The real combobox searches the server; a plain button picks customer 7.
vi.mock('@/components/ui/CustomerSearchCombobox', () => ({
  CustomerSearchCombobox: ({ onValueChange }: { onValueChange: (id: string, customer?: unknown) => void }) => (
    <button type="button" role="combobox" aria-label="Pick customer" onClick={() => onValueChange('7', { id: 7, name: 'Repeat Co' })}>
      Pick customer
    </button>
  ),
}))
vi.mock('@/components/sales/CustomerContextStrip', () => ({ CustomerContextStrip: () => null }))

const remembered = {
  document: 'sales_order',
  source: { type: 'sales_order', id: 41, number: 'SO-2026-0041', date: '2026-09-30' },
  values: { warehouse_id: 3, notes: 'Deliver to loading bay 3', incoterm_code: 'FOB', incoterm_place: 'Port Klang' },
}

beforeEach(() => {
  window.localStorage.clear()
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/settings/sales/defaults') {
      if (config?.params?.customer_id === '7' && config.params.document === 'sales_order') {
        return { data: { data: { remembered } } }
      }
      return { data: { data: {} } }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderForm() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <SalesOrderDocumentForm mode="create" />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('SalesOrderDocumentForm remembered customer choices (#346)', () => {
  it('pre-fills blank fields from the last order and shows where they came from', async () => {
    renderForm()
    fireEvent.click(await screen.findByRole('combobox', { name: 'Pick customer' }))

    await waitFor(() =>
      expect(apiGet).toHaveBeenCalledWith('/settings/sales/defaults', { params: { customer_id: '7', document: 'sales_order' } }),
    )
    await waitFor(() => expect((screen.getByLabelText('Notes (printed)') as HTMLTextAreaElement).value).toBe('Deliver to loading bay 3'))
    expect((screen.getByLabelText('Incoterm Code') as HTMLInputElement).value).toBe('FOB')
    expect((screen.getByLabelText('Incoterm Place') as HTMLInputElement).value).toBe('Port Klang')

    const hint = screen.getByTestId('remembered-defaults-hint')
    expect(hint.textContent).toContain('Remembered from last order SO-2026-0041')
    expect(hint.textContent).toContain('warehouse')
  })

  it('never overwrites a value the user already entered', async () => {
    renderForm()
    fireEvent.change(await screen.findByLabelText('Notes (printed)'), { target: { value: 'Call before delivery' } })
    fireEvent.click(screen.getByRole('combobox', { name: 'Pick customer' }))

    await screen.findByTestId('remembered-defaults-hint')
    expect((screen.getByLabelText('Notes (printed)') as HTMLTextAreaElement).value).toBe('Call before delivery')
    expect((screen.getByLabelText('Incoterm Code') as HTMLInputElement).value).toBe('FOB')
  })
})
