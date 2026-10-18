// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { DeliveryOrderCreatePage } from './DeliveryOrderCreatePage'

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

const { apiGet, apiMock } = vi.hoisted(() => {
  const apiGet = vi.fn()
  return {
    apiGet,
    apiMock: {
      get: apiGet,
      post: vi.fn(async () => ({ data: {} })),
      put: vi.fn(async () => ({ data: {} })),
      patch: vi.fn(async () => ({ data: {} })),
      delete: vi.fn(async () => ({ data: {} })),
    },
  }
})

vi.mock('@/lib/api', () => ({
  default: apiMock,
  api: apiMock,
}))

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/settings/sales/defaults') {
      return { data: { data: { delivery_order: { default_requires_approval: true } } } }
    }
    if (url === '/settings/sales') {
      // Non-admin roles: the full settings endpoint is OWNER/ADMIN-only.
      throw Object.assign(new Error('Forbidden'), { response: { status: 403 } })
    }
    return { data: { data: [] } }
  })
})

afterEach(() => {
  cleanup()
})

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/delivery-orders/create']}>
        <DeliveryOrderCreatePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('DeliveryOrderCreatePage — tenant approval default for non-admins (#540)', () => {
  it('reads the approval default from the read-only defaults endpoint, not the admin settings', async () => {
    renderPage()

    expect(await screen.findByText(/Will require approval before confirming/)).toBeTruthy()

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith('/settings/sales/defaults')
    })
    expect(apiGet.mock.calls.some(([url]) => url === '/settings/sales')).toBe(false)
  })
})
