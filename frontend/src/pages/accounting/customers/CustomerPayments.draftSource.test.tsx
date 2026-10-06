// @vitest-environment jsdom
// #492 (PAY-UX6): `recorded` is the status of manual AND imported drafts, so
// the status label must not call every draft "imported" — the origin is shown
// as its own badge.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, within, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { CustomerPayments } from './CustomerPayments'

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

const manualDraft = {
  id: 11,
  payment_number: 'PAY-2026-0011',
  payment_date: '2026-02-10',
  amount: 100,
  method: 'cash',
  payment_status: 'recorded',
  source: 'manual',
  journal_entry_id: null,
  currency: 'MYR',
  customer: { id: 1, name: 'Klinik Ali' },
  created_at: '2026-02-10T09:00:00Z',
}

const importedDraft = {
  ...manualDraft,
  id: 12,
  payment_number: 'PAY-2026-0012',
  source: 'import',
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/payments?')) {
      return { data: { data: [manualDraft, importedDraft], current_page: 1, last_page: 1, per_page: 20, total: 2 } }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/accounting/customers/payments']}>
        <Routes>
          <Route path="/accounting/customers/payments" element={<CustomerPayments />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CustomerPayments — draft label and source (#492 PAY-UX6)', () => {
  it('describes a draft as "not yet posted" without claiming it was imported', async () => {
    renderPage()
    const row = (await screen.findByText('PAY-2026-0011')).closest('tr') as HTMLElement
    const draftBadge = within(row).getByText('Draft')
    expect(draftBadge.closest('[title]')?.getAttribute('title')).toBe('Draft (not yet posted)')
    expect(document.body.innerHTML).not.toContain('imported, not yet posted')
  })

  it('shows the source of each draft as a separate badge', async () => {
    renderPage()
    const manualRow = (await screen.findByText('PAY-2026-0011')).closest('tr') as HTMLElement
    const importRow = screen.getByText('PAY-2026-0012').closest('tr') as HTMLElement
    expect(within(manualRow).getByText('Manual')).toBeTruthy()
    expect(within(manualRow).queryByText('Imported')).toBeNull()
    expect(within(importRow).getByText('Imported')).toBeTruthy()
  })

  it('offers "Draft (not yet posted)" in the status filter', async () => {
    renderPage()
    await screen.findByText('PAY-2026-0011')
    const trigger = screen.getAllByRole('combobox').find((el) => /status/i.test(el.textContent || ''))
    expect(trigger).toBeTruthy()
    fireEvent.click(trigger!)
    expect(await screen.findByRole('option', { name: 'Draft (not yet posted)' })).toBeTruthy()
  })
})
