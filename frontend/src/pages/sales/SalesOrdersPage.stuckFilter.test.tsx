// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
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

// Issue #411 (DX1): "lines stuck at stage X" is applied server-side —
// the list forwards ?stuck_at= to GET /sales/orders and shows what it filters by.
beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/orders') {
      return { data: { data: [], total: 0, last_page: 1, current_page: 1, per_page: 20 } }
    }
    return { data: { data: [] } }
  })
})

afterEach(() => cleanup())

function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <SalesOrdersPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const orderCalls = () => apiGet.mock.calls.filter(([url]) => url === '/sales/orders').map(([, cfg]) => cfg?.params ?? {})

describe('SalesOrdersPage — lines stuck at stage filter (#411)', () => {
  it('sends stuck_at from the URL to the server and labels the filter', async () => {
    renderAt('/sales/orders?stuck_at=invoice')

    await waitFor(() => expect(orderCalls().length).toBeGreaterThan(0))
    expect(orderCalls().at(-1)?.stuck_at).toBe('invoice')
    expect(screen.getAllByText('Lines stuck at: Invoicing').length).toBeGreaterThan(0)
  })

  it('ignores an unknown stage and offers the filter with every stage', async () => {
    renderAt('/sales/orders?stuck_at=bogus')

    await waitFor(() => expect(orderCalls().length).toBeGreaterThan(0))
    expect(orderCalls().at(-1)?.stuck_at).toBeUndefined()
    expect(screen.getByRole('combobox', { name: 'Lines stuck at stage' })).toBeTruthy()
  })
})
