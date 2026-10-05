// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StockMovesTab } from './StockMovesTab'
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

function renderTab() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <StockMovesTab endpoint="/sales/delivery-orders/9/stock-moves" permission="sales.view" />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  localStorage.clear()
  useAuthStore.getState().setAuth(
    { id: 3, name: 'Ali Admin', email: 'ali@example.com' },
    'token',
    'tenant-1',
    'Admin',
    ['sales.view'],
  )
  apiGet.mockReset()
})

afterEach(cleanup)

describe('StockMovesTab (#690 INV-UX4)', () => {
  it('renders every movement with its reversal link and flags a net/sold mismatch', async () => {
    apiGet.mockResolvedValue({
      data: {
        data: {
          document: { type: 'delivery_order', id: 9, number: 'DO-2026-0009', label: 'Delivery Order' },
          sales_order_id: 4,
          has_mismatch: true,
          movements: [
            {
              id: 11,
              product_id: 7,
              type: 'sale',
              direction: 'out',
              quantity: 5,
              product: { id: 7, name: 'Trail Widget', sku: 'TW-7' },
              warehouse: { id: 3, name: 'Main Warehouse', code: 'MAIN' },
              lot_number: 'LOT-A',
              batch_number: 'LOT-A',
              expiry_date: '2027-01-01',
              serial_numbers: [],
              date: '2026-01-11T09:00:00+00:00',
              reference: 'DO-2026-0009',
              notes: null,
              source_document: { type: 'DeliveryOrder', id: 9, number: 'DO-2026-0009' },
              created_by: { id: 3, name: 'Ali Admin' },
              reverses_movement_id: null,
              reversed_by_movement_id: 12,
            },
            {
              id: 12,
              product_id: 7,
              type: 'return',
              direction: 'in',
              quantity: 5,
              product: { id: 7, name: 'Trail Widget', sku: 'TW-7' },
              warehouse: { id: 3, name: 'Main Warehouse', code: 'MAIN' },
              lot_number: null,
              batch_number: null,
              expiry_date: null,
              serial_numbers: [],
              date: '2026-01-12T10:00:00+00:00',
              reference: 'REVERSAL-DO-2026-0009',
              notes: 'Reversal: wrong site',
              source_document: { type: 'DeliveryOrder', id: 9, number: 'DO-2026-0009' },
              created_by: { id: 3, name: 'Ali Admin' },
              reverses_movement_id: 11,
              reversed_by_movement_id: null,
            },
          ],
          lines: [
            {
              line_id: 21,
              product_id: 7,
              product: { id: 7, name: 'Trail Widget', sku: 'TW-7' },
              description: 'Trail Widget',
              sold_quantity: 5,
              required_stock_quantity: 5,
              net_issued_quantity: 0,
              stock_tracked: true,
              mismatch: true,
              components: [],
            },
          ],
        },
      },
    })

    renderTab()

    expect(await screen.findByTestId('stock-moves-tab')).toBeTruthy()
    expect(await screen.findAllByTestId('stock-move-row')).toHaveLength(2)
    expect(await screen.findAllByTestId('stock-move-line-row')).toHaveLength(1)

    // Reversal links are rendered on both movements.
    expect(await screen.findByText('Reversed by #12')).toBeTruthy()
    expect(await screen.findByText('Reverses #11')).toBeTruthy()

    // The lot and the source document of the movement are shown.
    expect(await screen.findByText('LOT-A')).toBeTruthy()
    expect(screen.getAllByText(/DO-2026-0009/).length).toBeGreaterThan(0)

    // Net issued ≠ sold is flagged.
    expect(await screen.findByTestId('stock-moves-mismatch')).toBeTruthy()
    expect(screen.getAllByText('Mismatch').length).toBeGreaterThan(0)

    expect(apiGet).toHaveBeenCalledWith('/sales/delivery-orders/9/stock-moves')
  })

  it('shows the balanced state when net issued matches the sold quantity', async () => {
    apiGet.mockResolvedValue({
      data: {
        data: {
          document: { type: 'delivery_order', id: 9, number: 'DO-2026-0009', label: 'Delivery Order' },
          sales_order_id: 4,
          has_mismatch: false,
          movements: [
            {
              id: 11,
              product_id: 7,
              type: 'sale',
              direction: 'out',
              quantity: 5,
              product: { id: 7, name: 'Trail Widget', sku: 'TW-7' },
              warehouse: { id: 3, name: 'Main Warehouse', code: 'MAIN' },
              lot_number: null,
              batch_number: null,
              expiry_date: null,
              serial_numbers: [],
              date: '2026-01-11T09:00:00+00:00',
              reference: 'DO-2026-0009',
              notes: null,
              source_document: { type: 'DeliveryOrder', id: 9, number: 'DO-2026-0009' },
              created_by: null,
              reverses_movement_id: null,
              reversed_by_movement_id: null,
            },
          ],
          lines: [
            {
              line_id: 21,
              product_id: 7,
              product: { id: 7, name: 'Trail Widget', sku: 'TW-7' },
              description: 'Trail Widget',
              sold_quantity: 5,
              required_stock_quantity: 5,
              net_issued_quantity: 5,
              stock_tracked: true,
              mismatch: false,
              components: [],
            },
          ],
        },
      },
    })

    renderTab()

    expect(await screen.findByTestId('stock-moves-balanced')).toBeTruthy()
    expect(screen.queryByTestId('stock-moves-mismatch')).toBeNull()
    expect(screen.getAllByText('Matches').length).toBeGreaterThan(0)
  })
})
