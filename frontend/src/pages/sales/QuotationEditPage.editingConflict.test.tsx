// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QuotationEditPage } from './QuotationEditPage'
import { useAuthStore } from '@/store/authStore'

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

const { apiGet, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPut: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: apiPut,
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

function makeQuotation(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    quotation_number: 'QUO-2026-0005',
    customer_id: 1,
    customer: { id: 1, name: 'Klinik Ali' },
    quotation_date: '2026-09-01',
    valid_until: '2026-09-30',
    status: 'draft',
    lock_version: 3,
    updated_at: '2026-09-10T08:00:00Z',
    notes: 'original note',
    terms: '',
    discount_type: 'fixed',
    discount_value: 0,
    lines: [
      {
        id: 11,
        line_type: 'product',
        product_id: 7,
        product: { id: 7, name: 'Widget', sku: 'W-1' },
        description: 'Widget',
        quantity: 2,
        unit_price: 20,
        tax_rate: 0,
        discount_amount: 0,
        line_total: 40,
      },
    ],
    ...overrides,
  }
}

function staleConflict(data: Record<string, unknown>) {
  return { response: { status: 409, data } }
}

beforeEach(() => {
  useAuthStore.setState({
    user: { id: 1, name: 'Me', email: 'me@example.com' },
    role: 'Owner',
    permissions: [],
    token: 'test-token',
  })

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/quotations/5') return { data: makeQuotation() }
    if (url.startsWith('/tags/')) return { data: { data: [] } }
    if (url.startsWith('/products')) return { data: { data: [] } }
    if (url.startsWith('/customers')) return { data: { data: [] } }
    return { data: { data: [] } }
  })

  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: {} }))
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotations/5/edit']}>
        <Routes>
          <Route path="/quotations/:id/edit" element={<QuotationEditPage />} />
          <Route path="/quotations/:id" element={<div>quotation detail</div>} />
          <Route path="*" element={<div>other</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function editNotes(value: string) {
  // #323: notes/terms live in the single details drawer now.
  fireEvent.click(await screen.findByTitle('References, contacts, addresses, logistics and terms'))
  const notes = (await screen.findByPlaceholderText('Internal notes...')) as HTMLTextAreaElement
  fireEvent.change(notes, { target: { value } })
  fireEvent.click(screen.getByRole('button', { name: 'Close' }))
  await waitFor(() => expect(screen.queryByPlaceholderText('Internal notes...')).toBeNull())
  return notes
}

describe('QuotationEditPage — concurrent editing banner and save-conflict dialog (#906)', () => {
  it('sends the loaded lock version when saving so a concurrent edit is detected', async () => {
    renderPage()
    await editNotes('my note')

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith(
        '/quotations/5',
        expect.objectContaining({ notes: 'my note', version: 3 }),
      )
    })
  })

  it("shows who else is editing from the document's active lock", async () => {
    apiGet.mockImplementation(async (url: string) => {
      if (url === '/quotations/5') {
        return {
          data: makeQuotation({
            active_lock: { user_id: 2, user_name: 'Siti', expires_at: '2026-09-10T08:15:00Z' },
          }),
        }
      }
      if (url.startsWith('/tags/')) return { data: { data: [] } }
      return { data: { data: [] } }
    })

    renderPage()

    const banner = await screen.findByTestId('editing-banner')
    expect(within(banner).getByText(/Siti/)).toBeTruthy()
    expect(within(banner).getByText(/editing this document/i)).toBeTruthy()
  })

  it('opens the save-conflict dialog with their changes next to mine on 409', async () => {
    apiPut.mockRejectedValue(
      staleConflict({
        message: 'This document was changed by another user since you loaded it.',
        code: 'stale_record',
        current_version: 4,
        current_updated_at: '2026-09-10T09:00:00Z',
        data: makeQuotation({ notes: 'their note', lock_version: 4 }),
        active_lock: { user_id: 2, user_name: 'Siti', expires_at: '2026-09-10T09:15:00Z' },
      }),
    )

    renderPage()
    await editNotes('my note')
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Siti/)).toBeTruthy()
    expect(within(dialog).getByText('their note')).toBeTruthy()
    expect(within(dialog).getByText('my note')).toBeTruthy()
    expect(within(dialog).getByRole('button', { name: /reload theirs/i })).toBeTruthy()
    expect(within(dialog).getByRole('button', { name: /overwrite/i })).toBeTruthy()
    expect(within(dialog).getByRole('button', { name: /copy my lines/i })).toBeTruthy()
  })

  it('overwrite re-sends my changes against the current version', async () => {
    apiPut
      .mockRejectedValueOnce({
        response: {
          status: 409,
          data: {
            message: 'stale',
            code: 'stale_record',
            current_version: 4,
            data: makeQuotation({ notes: 'their note', lock_version: 4 }),
            active_lock: { user_id: 2, user_name: 'Siti' },
          },
        },
      })
      .mockResolvedValueOnce({ data: { id: 5 } })

    renderPage()
    await editNotes('my note')
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: /overwrite/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledTimes(2)
      expect(apiPut).toHaveBeenLastCalledWith(
        '/quotations/5',
        expect.objectContaining({ notes: 'my note', version: 4 }),
      )
    })
  })

  it('reload theirs refetches the current document and closes the dialog', async () => {
    apiPut.mockRejectedValue(
      staleConflict({
        message: 'stale',
        code: 'stale_record',
        current_version: 4,
        data: makeQuotation({ notes: 'their note', lock_version: 4 }),
        active_lock: { user_id: 2, user_name: 'Siti' },
      }),
    )

    renderPage()
    await editNotes('my note')
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

    const dialog = await screen.findByRole('dialog')
    const getCallsBefore = apiGet.mock.calls.filter(([url]) => url === '/quotations/5').length

    fireEvent.click(within(dialog).getByRole('button', { name: /reload theirs/i }))

    await waitFor(() => {
      const getCallsAfter = apiGet.mock.calls.filter(([url]) => url === '/quotations/5').length
      expect(getCallsAfter).toBeGreaterThan(getCallsBefore)
    })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull()
    })
  })
})
