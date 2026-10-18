// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { CustomerCreditNoteDetail } from './CustomerCreditNoteDetail'
import { useAuthStore } from '@/store/authStore'

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

const base = {
  id: 7,
  credit_note_number: 'CN-2026-0007',
  credit_note_date: '2026-02-01',
  total_amount: 100,
  currency_code: 'MYR',
  customer: { id: 1, name: 'Klinik Ali' },
  invoice: { id: 5, invoice_number: 'INV-2026-0005' },
  lines: [],
}

let current: Record<string, unknown> = { ...base, status: 'draft' }

function asAccountant(permissions: string[]) {
  useAuthStore.setState({ role: 'Accountant', permissions })
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/customers/credit-notes/7') return { data: current }
    return { data: { data: [] } }
  })
  apiPost.mockImplementation(async () => ({ data: {} }))
})

afterEach(() => {
  cleanup()
  useAuthStore.setState({ role: null, permissions: [] })
})

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/accounting/customers/credit-notes/7']}>
        <Routes>
          <Route path="/accounting/customers/credit-notes/:id" element={<CustomerCreditNoteDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('CustomerCreditNoteDetail — header actions (#520)', () => {
  it('posts a draft credit note through the post endpoint', async () => {
    current = { ...base, status: 'draft' }
    asAccountant(['accounting.view', 'accounting.post'])
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: /^post$/i }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/accounting/customers/credit-notes/7/post')
    })
    expect(screen.queryByRole('button', { name: /void/i })).toBeNull()
  })

  it('voids a posted credit note only after a reason is given and confirmed', async () => {
    current = { ...base, status: 'posted', journal_entry_id: 55 }
    asAccountant(['accounting.view', 'accounting.post'])
    renderPage()

    expect(await screen.findByText(/Credit Note CN-2026-0007/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: /^post$/i })).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: /^void$/i }))
    const confirm = await screen.findByRole('button', { name: /confirm void/i })
    expect((confirm as HTMLButtonElement).disabled).toBe(true)

    fireEvent.change(screen.getByLabelText(/reason/i), { target: { value: 'Issued against the wrong invoice' } })
    expect((confirm as HTMLButtonElement).disabled).toBe(false)
    fireEvent.click(confirm)

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/accounting/customers/credit-notes/7/void', {
        reason: 'Issued against the wrong invoice',
      })
    })
  })

  it('shows the void error from the server instead of pretending it worked', async () => {
    current = { ...base, status: 'posted', journal_entry_id: 55 }
    asAccountant(['accounting.view', 'accounting.post'])
    apiPost.mockImplementation(async () => {
      throw { response: { status: 409, data: { message: 'Cancel the accepted e-invoice before voiding.' } } }
    })
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: /^void$/i }))
    fireEvent.change(await screen.findByLabelText(/reason/i), { target: { value: 'Duplicate' } })
    fireEvent.click(screen.getByRole('button', { name: /confirm void/i }))

    expect(await screen.findByText('Cancel the accepted e-invoice before voiding.')).toBeTruthy()
  })

  it('hides Post and Void from a user without accounting.post', async () => {
    current = { ...base, status: 'posted', journal_entry_id: 55 }
    asAccountant(['accounting.view'])
    renderPage()

    expect(await screen.findByText(/Credit Note CN-2026-0007/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: /^void$/i })).toBeNull()
    expect(screen.queryByRole('button', { name: /^post$/i })).toBeNull()
    // The e-invoice Submit/Retry actions are posting-privilege too.
    expect(screen.queryByRole('button', { name: /^submit$/i })).toBeNull()
  })

  it('links to the journal entry and the reversal entry', async () => {
    current = { ...base, status: 'voided', journal_entry_id: 55, reversal_journal_entry_id: 56 }
    asAccountant(['accounting.view', 'accounting.post'])
    renderPage()

    const je = await screen.findByRole('link', { name: /view journal entry/i })
    expect(je.getAttribute('href')).toBe('/accounting/core/journal-entries?entry_id=55')
    const rev = screen.getByRole('link', { name: /view reversal entry/i })
    expect(rev.getAttribute('href')).toBe('/accounting/core/journal-entries?entry_id=56')
    expect(screen.queryByRole('button', { name: /^void$/i })).toBeNull()
  })
})
