// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import api from '@/lib/api'
import { InvoicesPage } from '@/pages/InvoicesPage'
import { DeliveryOrdersPage } from '@/pages/sales/DeliveryOrdersPage'
import { SalesOrdersPage } from '@/pages/sales/SalesOrdersPage'
import { QuotesPage } from '@/pages/sales/QuotesPage'

/**
 * #365: a Sales cockpit action-queue tile opens its list with
 * `?action_queue=<key>[&mine=1]`. Each list must forward that filter to its
 * API (which applies the same query the tile counted), show a removable chip,
 * and drop the filter from the request and the URL when the chip is removed.
 */

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

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location-search">{location.search}</div>
}

function renderAt(path: string, routePath: string, element: JSX.Element) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route
            path={routePath}
            element={
              <>
                {element}
                <LocationProbe />
              </>
            }
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** Query params of the last list request, from either axios params or the URL. */
function lastListParams(endpoint: string): Record<string, unknown> {
  const calls = mockedGet.mock.calls.filter(([url]) => String(url).split('?')[0] === endpoint)
  const last = calls[calls.length - 1]
  if (!last) return {}
  const [url, config] = last as [string, { params?: Record<string, unknown> } | undefined]
  const fromUrl = Object.fromEntries(new URLSearchParams(String(url).split('?')[1] ?? ''))
  return { ...fromUrl, ...(config?.params ?? {}) }
}

beforeEach(() => {
  mockedGet.mockImplementation(async () => ({ data: { data: [], current_page: 1, last_page: 1, total: 0 } }))
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

const cases: Array<[string, string, string, string, JSX.Element]> = [
  ['Quotations', '/sales/quotes', '/quotations', 'quotes_expiring', <QuotesPage />],
  ['Sales Orders', '/sales/orders', '/sales/orders', 'orders_to_invoice', <SalesOrdersPage />],
  ['Delivery Orders', '/delivery-orders', '/sales/delivery-orders', 'deliveries_to_invoice', <DeliveryOrdersPage />],
  ['Invoices', '/invoices', '/invoices', 'overdue_invoices', <InvoicesPage />],
]

describe('#365 — list pages honour action_queue from a cockpit tile', () => {
  it.each(cases)('%s list forwards the queue filter and clears it with the chip', async (_name, route, endpoint, queue, element) => {
    renderAt(`${route}?action_queue=${queue}&mine=1`, route, element)

    await waitFor(() => expect(lastListParams(endpoint).action_queue).toBe(queue))
    expect(lastListParams(endpoint).mine).toBe('1')

    const chip = await screen.findByTestId('action-queue-filter-chip')
    expect(chip.textContent).toContain('(mine)')

    fireEvent.click(screen.getByTestId('action-queue-filter-chip-remove'))

    await waitFor(() => expect(screen.queryByTestId('action-queue-filter-chip')).toBeNull())
    await waitFor(() => expect(lastListParams(endpoint).action_queue).toBeUndefined())
    expect(lastListParams(endpoint).mine).toBeUndefined()
    expect(screen.getByTestId('location-search').textContent).not.toContain('action_queue')
  })

  it('ignores an unknown queue key', async () => {
    renderAt('/invoices?action_queue=bogus', '/invoices', <InvoicesPage />)

    await waitFor(() => expect(mockedGet).toHaveBeenCalled())
    expect(lastListParams('/invoices').action_queue).toBeUndefined()
    expect(screen.queryByTestId('action-queue-filter-chip')).toBeNull()
  })
})
