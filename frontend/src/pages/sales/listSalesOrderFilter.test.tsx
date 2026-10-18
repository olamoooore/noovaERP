// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import api from '@/lib/api'
import { InvoicesPage } from '@/pages/InvoicesPage'
import { DeliveryOrdersPage } from '@/pages/sales/DeliveryOrdersPage'

/**
 * #403: the Sales Order page's smart buttons open `/invoices?sales_order_id=<id>`
 * and `/delivery-orders?sales_order_id=<id>`. Both lists must send that filter
 * to the server, show a removable "Sales order SO-xxx" chip, and drop the filter
 * (from the request and the URL) when the chip is removed.
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

function listCalls(endpoint: string) {
  return mockedGet.mock.calls.filter(([url]) => url === endpoint)
}

function lastListParams(endpoint: string): Record<string, unknown> {
  const calls = listCalls(endpoint)
  const last = calls[calls.length - 1]
  return ((last?.[1] as { params?: Record<string, unknown> } | undefined)?.params ?? {}) as Record<string, unknown>
}

beforeEach(() => {
  mockedGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders/42') {
      return { data: { id: 42, sales_order_number: 'SO-0042' } }
    }
    return { data: { data: [], current_page: 1, last_page: 1, total: 0 } }
  })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('#403 — list pages honour sales_order_id from the URL', () => {
  it('Invoices list filters by the sales order and shows a removable chip', async () => {
    renderAt('/invoices?sales_order_id=42', '/invoices', <InvoicesPage />)

    await waitFor(() => expect(listCalls('/invoices').length).toBeGreaterThan(0))
    expect(lastListParams('/invoices').sales_order_id).toBe('42')

    const chip = await screen.findByTestId('sales-order-filter-chip')
    await waitFor(() => expect(chip.textContent).toContain('Sales order SO-0042'))

    fireEvent.click(screen.getByTestId('sales-order-filter-chip-remove'))

    await waitFor(() => expect(screen.queryByTestId('sales-order-filter-chip')).toBeNull())
    await waitFor(() => expect(lastListParams('/invoices').sales_order_id).toBeUndefined())
    expect(screen.getByTestId('location-search').textContent).not.toContain('sales_order_id')
  })

  it('Invoices list shows no chip and sends no filter without the param', async () => {
    renderAt('/invoices', '/invoices', <InvoicesPage />)

    await waitFor(() => expect(listCalls('/invoices').length).toBeGreaterThan(0))
    expect(lastListParams('/invoices').sales_order_id).toBeUndefined()
    expect(screen.queryByTestId('sales-order-filter-chip')).toBeNull()
  })

  it('Delivery Orders list filters by the sales order and shows a removable chip', async () => {
    renderAt('/delivery-orders?sales_order_id=42', '/delivery-orders', <DeliveryOrdersPage />)

    await waitFor(() => expect(listCalls('/sales/delivery-orders').length).toBeGreaterThan(0))
    expect(lastListParams('/sales/delivery-orders').sales_order_id).toBe('42')

    const chip = await screen.findByTestId('sales-order-filter-chip')
    await waitFor(() => expect(chip.textContent).toContain('Sales order SO-0042'))

    fireEvent.click(screen.getByTestId('sales-order-filter-chip-remove'))

    await waitFor(() => expect(screen.queryByTestId('sales-order-filter-chip')).toBeNull())
    await waitFor(() => expect(lastListParams('/sales/delivery-orders').sales_order_id).toBeUndefined())
    expect(screen.getByTestId('location-search').textContent).not.toContain('sales_order_id')
  })
})
