// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { SingleStatementPanel } from './SingleStatementPanel'
import { BatchStatementPanel } from './BatchStatementPanel'

// #1018 (VC-010): the Sales statement panels default their period with local
// calendar dates (toISOString() is the UTC day — at +0800 the first of the
// month became the last day of the previous month) and reject To < From.

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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

// The customer picker is not under test here — a button that selects one.
vi.mock('@/components/ui/CustomerSearchCombobox', () => ({
  CustomerSearchCombobox: ({ onValueChange }: { onValueChange: (value: string, customer?: unknown) => void }) => (
    <button type="button" onClick={() => onValueChange('7', { id: '7', code: 'KA-1', name: 'Klinik Ali' })}>
      pick customer
    </button>
  ),
}))

const PAYLOAD = {
  currency: 'SGD',
  closing_balance: 100,
  total_debits: 100,
  total_credits: 0,
  aging: { current: 100, days_1_30: 0, days_31_60: 0, days_61_90: 0, days_90_plus: 0, total: 100 },
}

const originalTz = process.env.TZ

beforeEach(() => {
  process.env.TZ = 'Asia/Singapore'
  vi.useFakeTimers({ toFake: ['Date'] })
  // 07:00 SGT on 7 Oct 2026 === 23:00 UTC on 6 Oct.
  vi.setSystemTime(new Date('2026-10-06T23:00:00Z'))

  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url.includes('/statement')) {
      return { data: { statement_id: '1', filename: 'SOA', payload: PAYLOAD } }
    }
    if (url.startsWith('/documents/customer_statement/resolution')) {
      return { data: { data: { outcome: 'RESOLVED', template_id: 'tpl', template_name: 'SOA', rule_label: 'default' } } }
    }
    return { data: { data: [] } }
  })
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/accounting/customer-statements/batch/preview') {
      return {
        data: {
          data: [{ id: 7, name: 'Klinik Ali', closing_balance: 100, overdue_amount: 0, oldest_overdue_days: 0 }],
        },
      }
    }
    return { data: { data: { id: 1, status: 'queued' } } }
  })
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  if (originalTz === undefined) delete process.env.TZ
  else process.env.TZ = originalTz
})

function wrap(node: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={qc}>{node}</QueryClientProvider>)
}

describe('#1018 — SingleStatementPanel period', () => {
  it('defaults From/To to the local first-of-month and today at +0800', () => {
    wrap(<SingleStatementPanel />)
    expect((screen.getByLabelText('From') as HTMLInputElement).value).toBe('2026-10-01')
    expect((screen.getByLabelText('To') as HTMLInputElement).value).toBe('2026-10-07')
  })

  it('rejects To < From: shows the error, sets min on To and withholds print/send', async () => {
    wrap(<SingleStatementPanel />)
    fireEvent.click(screen.getByText('pick customer'))
    expect(await screen.findByRole('button', { name: /print \/ send/i })).toBeTruthy()

    const to = screen.getByLabelText('To') as HTMLInputElement
    expect(to.min).toBe('2026-10-01')
    fireEvent.change(to, { target: { value: '2026-09-15' } })

    expect(await screen.findByText('The To date must be on or after the From date.')).toBeTruthy()
    await waitFor(() => expect(screen.queryByRole('button', { name: /print \/ send/i })).toBeNull())
    // No preview request is made for the invalid range.
    expect(apiGet).not.toHaveBeenCalledWith(
      '/accounting/customers/7/statement',
      expect.objectContaining({ params: expect.objectContaining({ end_date: '2026-09-15' }) }),
    )
  })
})

describe('#1018 — BatchStatementPanel period', () => {
  it('defaults From/To to the local first-of-month and today at +0800', () => {
    wrap(<BatchStatementPanel />)
    expect((screen.getByLabelText('From') as HTMLInputElement).value).toBe('2026-10-01')
    expect((screen.getByLabelText('To') as HTMLInputElement).value).toBe('2026-10-07')
  })

  it('rejects To < From: shows the error and disables preview and send', async () => {
    wrap(<BatchStatementPanel />)
    fireEvent.click(screen.getByRole('button', { name: /preview list/i }))
    const send = await screen.findByRole('button', { name: /generate & send/i })
    expect((send as HTMLButtonElement).disabled).toBe(false)

    const to = screen.getByLabelText('To') as HTMLInputElement
    expect(to.min).toBe('2026-10-01')
    fireEvent.change(to, { target: { value: '2026-09-15' } })

    expect(await screen.findByText('The To date must be on or after the From date.')).toBeTruthy()
    expect((screen.getByRole('button', { name: /generate & send/i }) as HTMLButtonElement).disabled).toBe(true)
    expect((screen.getByRole('button', { name: /preview list/i }) as HTMLButtonElement).disabled).toBe(true)
  })
})
