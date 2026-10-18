// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StockAvailabilityChip } from './StockAvailabilityChip'

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

function renderChip(props: {
  productId: number | string | null
  warehouseId: number | string | null
  requested: number
}) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <StockAvailabilityChip {...props} />
    </QueryClientProvider>,
  )
}

afterEach(cleanup)

beforeEach(() => {
  apiGet.mockReset()
})

describe('StockAvailabilityChip (#687 UX1)', () => {
  it('shows the available quantity with on hand / reserved for the selected warehouse', async () => {
    apiGet.mockResolvedValue({
      data: { on_hand: 10, reserved: 2, available: 8, incoming: [], incoming_quantity: 0 },
    })

    renderChip({ productId: 7, warehouseId: '3', requested: 5 })

    const chip = await screen.findByTestId('stock-availability-chip')
    expect(chip.textContent).toContain('8')
    expect(chip.textContent).toContain('On hand 10 · Reserved 2')
    expect(chip.getAttribute('data-insufficient')).toBe('false')

    // The shared #465 stock-check path is the only source of the figures.
    expect(apiGet).toHaveBeenCalledWith('/sales/delivery-orders/stock-check', {
      params: { product_id: 7, warehouse_id: '3' },
    })
  })

  it('turns red when the line quantity exceeds availability', async () => {
    apiGet.mockResolvedValue({
      data: { on_hand: 2, reserved: 0, available: 2, incoming: [] },
    })

    renderChip({ productId: 7, warehouseId: '3', requested: 3 })

    const chip = await screen.findByTestId('stock-availability-chip')
    await waitFor(() => expect(chip.getAttribute('data-insufficient')).toBe('true'))
    expect(chip.querySelector('.text-destructive')).toBeTruthy()
  })

  it('stays hidden while no warehouse is selected yet', async () => {
    apiGet.mockResolvedValue({ data: { on_hand: 0, reserved: 0, available: 0, incoming: [] } })

    renderChip({ productId: 7, warehouseId: '', requested: 1 })

    expect(screen.queryByTestId('stock-availability-chip')).toBeNull()
    expect(apiGet).not.toHaveBeenCalled()
  })

  it('hover details list incoming supply with expected dates', async () => {
    apiGet.mockResolvedValue({
      data: {
        on_hand: 10,
        reserved: 2,
        available: 8,
        incoming: [
          {
            source_type: 'purchase_order',
            source_id: 42,
            reference: 'PO-687-0042',
            quantity: 4,
            expected_date: '2026-10-20',
            status: 'ordered',
          },
        ],
        incoming_quantity: 4,
      },
    })

    renderChip({ productId: 7, warehouseId: '3', requested: 5 })

    const trigger = await screen.findByTestId('stock-availability-chip')
    fireEvent.focusIn(trigger)

    // Radix renders the content twice (popper + a visually-hidden a11y copy).
    await waitFor(() => expect(screen.getAllByText(/PO-687-0042/).length).toBeGreaterThan(0))
    expect(screen.getAllByText(/2026-10-20/).length).toBeGreaterThan(0)
  })

  it('discloses when there is no incoming supply instead of implying delivery dates', async () => {
    apiGet.mockResolvedValue({
      data: { on_hand: 10, reserved: 2, available: 8, incoming: [], incoming_quantity: 0 },
    })

    renderChip({ productId: 7, warehouseId: '3', requested: 5 })

    const trigger = await screen.findByTestId('stock-availability-chip')
    fireEvent.focusIn(trigger)

    await waitFor(() => expect(screen.getAllByText(/none scheduled/i).length).toBeGreaterThan(0))
  })
})
