// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SalesOrdersPage } from './SalesOrdersPage'

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

// Issue #382 (O13): sorting must be done by the server across every page.
// The page is handed rows in a deliberately "wrong" client order; the
// rendered order must stay exactly what the server returned, and clicking a
// column must re-query with sort/direction and page=1.
const rows = [
  { id: 1, sales_order_number: 'SO-1', customer: { id: 1, name: 'Zed' }, channel: 'admin', order_date: '2026-09-01', created_at: '2026-09-01T00:00:00Z', status: 'draft', total: 5, lines: [] },
  { id: 2, sales_order_number: 'SO-2', customer: { id: 2, name: 'Alpha' }, channel: 'admin', order_date: '2026-09-02', created_at: '2026-09-03T00:00:00Z', status: 'draft', total: 900, lines: [] },
]

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders') {
      return { data: { data: rows, total: 60, last_page: 3, current_page: 1, per_page: 20 } }
    }
    return { data: { data: [] } }
  })
})

afterEach(() => cleanup())

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <SalesOrdersPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const orderCalls = () => apiGet.mock.calls.filter(([url]) => url === '/sales/orders').map(([, cfg]) => cfg?.params ?? {})

describe('SalesOrdersPage server-side sort (#382)', () => {
  it('requests the default newest-first sort and renders rows in server order', async () => {
    renderPage()
    await screen.findByText('SO-1')
    expect(orderCalls()[0]).toMatchObject({ sort: 'created_at', direction: 'desc', page: 1 })
    const numbers = screen.getAllByText(/^SO-\d$/).map((el) => el.textContent)
    // Server returned SO-1 then SO-2; a client-side created_at-desc sort would flip them.
    expect(numbers).toEqual(['SO-1', 'SO-2'])
  })

  it('clicking a column re-queries the server with sort/direction and resets to page 1', async () => {
    renderPage()
    await screen.findByText('SO-1')
    fireEvent.click(screen.getByRole('button', { name: /next/i }))
    await waitFor(() => expect(orderCalls().some((p) => p.page === 2)).toBe(true))

    fireEvent.click(await screen.findByText('Total'))
    await waitFor(() =>
      expect(orderCalls().at(-1)).toMatchObject({ sort: 'total', direction: 'asc', page: 1 }),
    )
    fireEvent.click(await screen.findByText('Total'))
    await waitFor(() => expect(orderCalls().at(-1)).toMatchObject({ sort: 'total', direction: 'desc', page: 1 }))
  })
})
