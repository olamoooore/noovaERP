// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
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

const originalTz = process.env.TZ

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async () => ({ data: { data: [] } }))
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  process.env.TZ = originalTz
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

describe('DeliveryOrderCreatePage — delivery date default (#467)', () => {
  it('defaults to the local business date at 00:30 UTC+8, not the previous UTC day', () => {
    // 2026-10-06T00:30:00+08:00 === 2026-10-05T16:30:00Z. The old inline
    // new Date().toISOString() initialiser returned the UTC day (2026-10-05),
    // i.e. yesterday, for every user east of UTC before 08:00 local.
    process.env.TZ = 'Asia/Kuala_Lumpur'
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-06T00:30:00+08:00'))

    // Sanity: the wall-clock instant really is on the previous UTC day.
    expect(new Date().toISOString().split('T')[0]).toBe('2026-10-05')

    renderPage()

    const deliveryDateLabel = screen.getByText('Delivery Date')
    const deliveryDateInput = deliveryDateLabel.parentElement?.querySelector(
      'input[type="date"]',
    ) as HTMLInputElement | null

    expect(deliveryDateInput).toBeTruthy()
    expect(deliveryDateInput?.value).toBe('2026-10-06')
  })
})
