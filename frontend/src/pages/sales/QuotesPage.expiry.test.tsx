// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { QuotesPage } from './QuotesPage'

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

vi.mock('@/lib/pricingApi', () => ({
  default: {
    sellableCurrencies: vi.fn(async () => []),
  },
}))

const expiredQuote = {
  id: 3351,
  quotation_number: 'QUO-2026-3351',
  customer: { id: 1, name: 'Klinik Expired' },
  quotation_date: '2026-09-01',
  valid_until: '2026-09-30',
  status: 'expired',
  is_expired: true,
  is_late: false,
  created_at: '2026-09-01T00:00:00Z',
  total: 100,
  lines: [],
}

const staleOpenQuote = {
  id: 3352,
  quotation_number: 'QUO-2026-3352',
  customer: { id: 2, name: 'Klinik Stale' },
  quotation_date: '2026-09-01',
  valid_until: '2026-09-15',
  status: 'sent',
  is_expired: true,
  is_late: false,
  created_at: '2026-09-01T00:00:00Z',
  total: 200,
  lines: [],
}

const openQuote = {
  id: 3353,
  quotation_number: 'QUO-2026-3353',
  customer: { id: 3, name: 'Klinik Open' },
  quotation_date: '2026-10-01',
  valid_until: '2026-11-01',
  status: 'draft',
  is_expired: false,
  is_late: false,
  created_at: '2026-10-01T00:00:00Z',
  total: 300,
  lines: [],
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/quotations')) {
      const expiredOnly = url.includes('status=expired')
      const rows = expiredOnly ? [expiredQuote] : [expiredQuote, staleOpenQuote, openQuote]
      return {
        data: {
          data: rows,
          meta: { total: rows.length, last_page: 1, current_page: 1, per_page: 20 },
          total: rows.length,
          last_page: 1,
        },
      }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotations']}>
        <QuotesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('QuotesPage — quotation validity expiry (#335)', () => {
  it('shows the expired status badge and the stale-validity badge on the list', async () => {
    renderPage()

    await screen.findByText('QUO-2026-3351')

    // The command-set status renders as `expired`; a past-due row the daily
    // job has not swept yet still carries the extra "Expired" badge.
    expect(screen.getByText('expired')).toBeTruthy()
    expect(screen.getByText('Expired')).toBeTruthy()
    expect(screen.getByText('QUO-2026-3352')).toBeTruthy()
    expect(screen.getByText('QUO-2026-3353')).toBeTruthy()
  })

  it('filters the list by the Expired status', async () => {
    renderPage()

    await screen.findByText('QUO-2026-3351')

    const statusTrigger = (await screen.findAllByRole('combobox'))[0]
    fireEvent.click(statusTrigger)
    const listbox = await screen.findByRole('listbox')
    fireEvent.click(within(listbox).getByText('Expired'))

    await waitFor(() => {
      const filtered = apiGet.mock.calls.some(
        ([url]) => typeof url === 'string' && url.includes('/quotations?') && url.includes('status=expired'),
      )
      expect(filtered).toBe(true)
    })
  })
})
