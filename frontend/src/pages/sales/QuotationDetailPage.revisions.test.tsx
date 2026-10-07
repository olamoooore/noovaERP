// @vitest-environment jsdom
/**
 * Issue #336 (Q12) — quotation Reject / New revision actions and a visible
 * version history on the quotation detail page.
 *
 * The backend already rejects a quotation into an auto-versioned replacement
 * draft (`POST /quotations/{id}/reject`, #178/#179); the detail page had no
 * button for it and never showed the earlier versions.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QuotationDetailPage } from './QuotationDetailPage'
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

const baseQuotation = {
  id: 5,
  quotation_number: 'QT-2026-0005',
  status: 'sent',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  quotation_date: '2026-09-01',
  valid_until: '2026-12-01',
  subtotal: 200,
  tax_amount: 0,
  discount_amount: 0,
  total: 200,
  currency_code: 'USD',
  version_number: 1,
  is_active_version: true,
  version_history: [],
  lines: [],
}

const replacement = {
  ...baseQuotation,
  id: 9,
  quotation_number: 'QT-2026-0005-R02',
  status: 'draft',
  version_number: 2,
}

let current: Record<string, unknown> = baseQuotation

beforeEach(() => {
  current = baseQuotation
  useAuthStore.setState({ role: 'Admin', permissions: [], user: { id: 1, name: 'Admin', email: 'a@b.c' }, token: 't' } as never)
  apiGet.mockReset()
  apiPost.mockReset()
  apiPost.mockResolvedValue({ data: { quotation: replacement, superseded_quotation_id: 5 } })
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/quotations/5') return { data: current }
    if (url === '/quotations/9') return { data: replacement }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotations/5']}>
        <Routes>
          <Route path="/quotations/:id" element={<QuotationDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function chooseReasonCode(label: RegExp) {
  const trigger = await screen.findByRole('combobox', { name: /reason code/i })
  fireEvent.pointerDown(trigger, { button: 0, ctrlKey: false, pointerType: 'mouse' })
  fireEvent.click(await screen.findByRole('option', { name: label }))
}

describe('QuotationDetailPage — Reject / New revision (#336)', () => {
  it('rejects with a reason code and text and opens the replacement draft', async () => {
    renderPage()
    await screen.findAllByText(/QT-2026-0005/)

    fireEvent.click(await screen.findByRole('button', { name: /^reject$/i }))
    const dialog = await screen.findByRole('dialog')

    // A reason code is mandatory.
    const submit = within(dialog).getByRole('button', { name: /reject quotation/i })
    expect((submit as HTMLButtonElement).disabled).toBe(true)

    await chooseReasonCode(/price too high/i)
    fireEvent.change(within(dialog).getByLabelText(/details/i), {
      target: { value: 'Customer found a cheaper supplier' },
    })
    fireEvent.click(submit)

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/quotations/5/reject', {
        reason_code: 'price_too_high',
        reason_text: 'Customer found a cheaper supplier',
      })
    })

    // The page moves to the new version.
    expect(await screen.findByRole('heading', { name: 'QT-2026-0005-R02' })).toBeTruthy()
  })

  it('creates a new revision through the same versioned endpoint with a revision reason', async () => {
    renderPage()
    await screen.findAllByText(/QT-2026-0005/)

    fireEvent.click(await screen.findByRole('button', { name: /new revision/i }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/details/i), {
      target: { value: 'Add installation line' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: /create revision/i }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/quotations/5/reject', {
        reason_code: 'revision',
        reason_text: 'Add installation line',
      })
    })
    expect(await screen.findByRole('heading', { name: 'QT-2026-0005-R02' })).toBeTruthy()
  })

  it('does not offer reject / new revision on a confirmed, cancelled or superseded quotation', async () => {
    for (const variant of [
      { status: 'confirmed' },
      { status: 'cancelled' },
      { status: 'sent', is_active_version: false },
    ]) {
      current = { ...baseQuotation, ...variant }
      const { unmount } = renderPage()
      await screen.findAllByText(/QT-2026-0005/)
      expect(screen.queryByRole('button', { name: /^reject$/i })).toBeNull()
      expect(screen.queryByRole('button', { name: /new revision/i })).toBeNull()
      unmount()
    }
  })

  it('hides reject / new revision without quotations.write', async () => {
    useAuthStore.setState({ role: 'Viewer', permissions: ['quotations.view'] } as never)
    renderPage()
    await screen.findAllByText(/QT-2026-0005/)
    expect(screen.queryByRole('button', { name: /^reject$/i })).toBeNull()
    expect(screen.queryByRole('button', { name: /new revision/i })).toBeNull()
  })
})

describe('QuotationDetailPage — version history (#336)', () => {
  const history = [
    {
      id: 3, quotation_number: 'QT-2026-0003', version_number: 1, status: 'cancelled', is_active_version: false,
      subtotal: 200, tax_amount: 0, discount_amount: 0, total: 200, total_change: null,
      correction_reason_code: null, correction_reason_text: null,
    },
    {
      id: 4, quotation_number: 'QT-2026-0003-R02', version_number: 2, status: 'cancelled', is_active_version: false,
      subtotal: 180, tax_amount: 0, discount_amount: 0, total: 180, total_change: -20,
      correction_reason_code: 'price_too_high', correction_reason_text: 'Customer wants a lower price',
    },
    {
      id: 5, quotation_number: 'QT-2026-0005', version_number: 3, status: 'sent', is_active_version: true,
      subtotal: 230, tax_amount: 20, discount_amount: 0, total: 250, total_change: 70,
      correction_reason_code: 'revision', correction_reason_text: 'Add installation',
    },
  ]

  it('lists v1, v2, v3 with totals and the change in total between versions', async () => {
    current = { ...baseQuotation, version_number: 3, total: 250, version_history: history }
    renderPage()
    await screen.findAllByText(/QT-2026-0005/)

    const panel = await screen.findByRole('region', { name: /version history/i })
    const rows = within(panel).getAllByRole('row').slice(1)
    expect(rows).toHaveLength(3)

    // Columns: Version, Quotation #, Status, Subtotal, Tax, Total, Change, Reason.
    const cells = (row: HTMLElement) => within(row).getAllByRole('cell').map((cell) => cell.textContent?.trim())

    expect(cells(rows[0])[0]).toBe('v1')
    expect(cells(rows[0])[5]).toBe('$200.00')
    expect(cells(rows[0])[6]).toBe('—')

    expect(cells(rows[1])[0]).toBe('v2')
    expect(cells(rows[1])[5]).toBe('$180.00')
    expect(cells(rows[1])[6]).toBe('-$20.00')
    expect(cells(rows[1])[7]).toMatch(/price too high/i)
    expect(cells(rows[1])[7]).toMatch(/customer wants a lower price/i)

    expect(cells(rows[2])[0]).toMatch(/^v3/)
    expect(cells(rows[2])[0]).toMatch(/current/i)
    expect(cells(rows[2])[4]).toBe('$20.00')
    expect(cells(rows[2])[5]).toBe('$250.00')
    expect(cells(rows[2])[6]).toBe('+$70.00')

    // Earlier versions open their own (read-only) quotation.
    const v1Link = within(rows[0]).getByRole('link', { name: 'QT-2026-0003' })
    expect(v1Link.getAttribute('href')).toBe('/quotations/3')
  })

  it('shows no version history panel for a quotation that was never revised', async () => {
    renderPage()
    await screen.findAllByText(/QT-2026-0005/)
    expect(screen.queryByRole('region', { name: /version history/i })).toBeNull()
  })
})
