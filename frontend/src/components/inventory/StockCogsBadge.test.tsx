// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { StockCogsBadge } from './StockCogsBadge'
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
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
}
if (!Element.prototype.releasePointerCapture) {
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

function renderBadge(documentType: 'sales_order' | 'delivery_order' | 'invoice' = 'invoice') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <StockCogsBadge documentType={documentType} documentId={9} permission="invoices.view" />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const greenPayload = {
  document: { type: 'invoice', id: 9, number: 'INV-2026-0009', label: 'Invoice' },
  has_mismatch: false,
  stock: { ok: true, issued: 10, sold: 10, offenders: [] },
  cogs: { ok: true, posted_count: 1, offenders: [] },
}

const redPayload = {
  document: { type: 'invoice', id: 9, number: 'INV-2026-0009', label: 'Invoice' },
  has_mismatch: true,
  stock: {
    ok: false,
    issued: 20,
    sold: 10,
    offenders: [
      {
        kind: 'over_issued',
        product_id: 7,
        product: { id: 7, name: 'Badge Widget', sku: 'BWX-7' },
        issued: 20,
        expected: 10,
        excess: 10,
        movements: [
          {
            id: 11,
            type: 'sale',
            direction: 'out',
            quantity: 10,
            date: '2026-01-11T09:00:00+00:00',
            product: { id: 7, name: 'Badge Widget', sku: 'BWX-7' },
            source_document: { type: 'DeliveryOrder', id: 3, number: 'DO-2026-0003' },
          },
          {
            id: 12,
            type: 'sale',
            direction: 'out',
            quantity: 10,
            date: '2026-01-12T09:00:00+00:00',
            product: { id: 7, name: 'Badge Widget', sku: 'BWX-7' },
            source_document: { type: 'Invoice', id: 9, number: 'INV-2026-0009' },
          },
        ],
      },
    ],
  },
  cogs: {
    ok: false,
    posted_count: 0,
    offenders: [
      {
        kind: 'missing_cogs',
        source_document: { type: 'Invoice', id: 9, number: 'INV-2026-0009' },
        net_issued: 10,
        expected_postings: 1,
        posted_count: 0,
        journal_entry_ids: [],
        message: 'Goods were issued but no COGS journal entry is active for this document.',
      },
    ],
  },
}

beforeEach(() => {
  localStorage.clear()
  useAuthStore.getState().setAuth(
    { id: 3, name: 'Ali Admin', email: 'ali@example.com' },
    'token',
    'tenant-1',
    'Admin',
    ['invoices.view'],
  )
  apiGet.mockReset()
})

afterEach(cleanup)

describe('StockCogsBadge (#696 INV-D3)', () => {
  it('renders the green badge with issued/sold and the one COGS posting', async () => {
    apiGet.mockResolvedValue({ data: { data: greenPayload } })

    renderBadge()

    const badge = await screen.findByTestId('stock-cogs-badge-ok')
    expect(badge.textContent).toContain('Stock ✓ (10 issued / 10 sold)')
    expect(badge.textContent).toContain('COGS ✓ (posted once)')
    expect(screen.queryByTestId('stock-cogs-badge-mismatch')).toBeNull()
    expect(apiGet).toHaveBeenCalledWith('/invoices/9/stock-cogs-badge')
  })

  it('turns red and lists the offending movements and the fix action', async () => {
    apiGet.mockResolvedValue({ data: { data: redPayload } })

    renderBadge()

    const badge = await screen.findByTestId('stock-cogs-badge-mismatch')
    expect(badge.textContent).toContain('Stock ✕ (20 issued / 10 sold)')
    expect(badge.textContent).toContain('COGS ✕')

    fireEvent.click(badge)

    // Both offending movements are listed, each linked to its source document.
    await waitFor(() => {
      expect(screen.getAllByTestId('stock-cogs-offending-movement')).toHaveLength(2)
    })
    expect(screen.getByText(/DO-2026-0003/)).toBeTruthy()
    expect(screen.getAllByText(/INV-2026-0009/).length).toBeGreaterThan(0)
    expect(screen.getByText(/Stock over-issued: Badge Widget by 10/)).toBeTruthy()

    // The COGS half names its offending document too.
    expect(await screen.findByTestId('stock-cogs-cogs-offender')).toBeTruthy()
    expect(screen.getByText(/no COGS journal entry is active/)).toBeTruthy()

    // The fix action reuses the existing correction path.
    expect(screen.getByText(/reverse the duplicate delivery issue/i)).toBeTruthy()
  })

  it('renders nothing without the record view permission', async () => {
    useAuthStore.getState().setAuth(
      { id: 3, name: 'Ali Admin', email: 'ali@example.com' },
      'token',
      'tenant-1',
      'Viewer',
      [],
    )
    apiGet.mockResolvedValue({ data: { data: greenPayload } })

    const { container } = renderBadge()

    await waitFor(() => expect(container.textContent).toBe(''))
    expect(apiGet).not.toHaveBeenCalled()
  })
})
