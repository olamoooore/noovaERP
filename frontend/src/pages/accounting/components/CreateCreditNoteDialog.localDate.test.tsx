// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import CreateCreditNoteDialog from './CreateCreditNoteDialog'

// #519 (CN10): the credit note date must default to the LOCAL business date.
// new Date().toISOString().split('T')[0] is the UTC day, so in UTC+8 a credit
// note created before 08:00 was dated the previous day.

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

vi.mock('@/lib/api', () => ({
  default: {
    get: vi.fn(async () => ({ data: { data: [] } })),
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
  },
}))
vi.mock('@/hooks/useCustomerCreditNotes', () => ({
  useCreateCreditNote: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

const originalTz = process.env.TZ

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  if (originalTz === undefined) delete process.env.TZ
  else process.env.TZ = originalTz
})

describe('#519 — Create Credit Note date default', () => {
  it('defaults to the local day at 07:30 Asia/Kuala_Lumpur, not the UTC day', async () => {
    process.env.TZ = 'Asia/Kuala_Lumpur'
    vi.useFakeTimers({ toFake: ['Date'] })
    // 07:30 MYT on 3 Oct === 23:30 UTC on 2 Oct.
    vi.setSystemTime(new Date('2026-10-02T23:30:00Z'))

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <CreateCreditNoteDialog onClose={() => {}} onSuccess={() => {}} />
      </QueryClientProvider>,
    )

    await waitFor(() => expect(screen.getByRole('dialog')).toBeTruthy())
    const dateInput = screen.getByRole('dialog').querySelector('input[type="date"]') as HTMLInputElement
    expect(dateInput).toBeTruthy()
    expect(dateInput.value).toBe('2026-10-03')
  })
})
