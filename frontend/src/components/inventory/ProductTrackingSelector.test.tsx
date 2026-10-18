// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ProductTrackingSelector } from './ProductTrackingSelector'
import { missingTrackingLineIndex } from '@/lib/lotTracking'

// Issue #689 (INV-UX3): the invoice/DO lot-serial picker pre-selects the FEFO
// lot the server suggests, highlights near-expiry lots, and a tracked line
// cannot be saved without a lot or serial.

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet },
}))

const batchPayload = {
  tracking_method: 'batch',
  suggested_batch_id: 11,
  suggested_serial_id: null,
  near_expiry_days: 30,
  batches: [
    { id: 11, batch_number: 'L-SOON', lot_number: 'L-SOON', expiry_date: '2026-10-16', available_quantity: 4, days_until_expiry: 10, near_expiry: true },
    { id: 12, batch_number: 'L-LATER', lot_number: 'L-LATER', expiry_date: '2027-11-10', available_quantity: 9, days_until_expiry: 400, near_expiry: false },
  ],
  serials: [],
}

function renderSelector(props: Partial<React.ComponentProps<typeof ProductTrackingSelector>> = {}) {
  const onChange = vi.fn()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const utils = render(
    <QueryClientProvider client={client}>
      <ProductTrackingSelector productId={5} warehouseId={2} trackingMethod="batch" onChange={onChange} {...props} />
    </QueryClientProvider>,
  )
  return { onChange, ...utils }
}

describe('ProductTrackingSelector (#689)', () => {
  beforeEach(() => {
    apiGet.mockReset()
  })
  afterEach(() => cleanup())

  it('pre-selects the FEFO lot suggested by the server when nothing is chosen', async () => {
    apiGet.mockResolvedValue({ data: batchPayload })
    const { onChange } = renderSelector()

    await waitFor(() => expect(onChange).toHaveBeenCalledTimes(1))
    expect(onChange).toHaveBeenCalledWith({
      product_batch_id: 11,
      product_serial_id: null,
      lot_number: 'L-SOON',
      serial_number: '',
      expiry_date: '2026-10-16',
    })
    expect(screen.getByRole('option', { name: /L-SOON.*FEFO/ })).toBeTruthy()
  })

  it('never overrides an existing lot selection', async () => {
    apiGet.mockResolvedValue({ data: batchPayload })
    const { onChange } = renderSelector({ batchId: 12 })

    await screen.findByRole('option', { name: /L-LATER/ })
    expect(onChange).not.toHaveBeenCalled()
  })

  it('never overrides a free-text lot number already on the line', async () => {
    apiGet.mockResolvedValue({ data: batchPayload })
    const { onChange } = renderSelector({ lotNumber: 'LEGACY-1' })

    await screen.findByRole('option', { name: /L-LATER/ })
    expect(onChange).not.toHaveBeenCalled()
  })

  it('highlights a near-expiry selected lot', async () => {
    apiGet.mockResolvedValue({ data: batchPayload })
    renderSelector({ batchId: 11 })

    expect(await screen.findByText(/Near expiry: 10 days left/)).toBeTruthy()
    expect(screen.getByRole('option', { name: /L-SOON.*expires in 10d/ })).toBeTruthy()
  })

  it('flags a required lot when none is available', async () => {
    apiGet.mockResolvedValue({ data: { ...batchPayload, batches: [], suggested_batch_id: null } })
    const { onChange } = renderSelector()

    expect(await screen.findByText(/Lot required/)).toBeTruthy()
    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('missingTrackingLineIndex (#689)', () => {
  it('returns the first tracked product line without a lot or serial', () => {
    expect(missingTrackingLineIndex([
      { line_type: 'product', product_id: 1, tracking_method: 'none' },
      { line_type: 'note', product_id: null, tracking_method: 'batch' },
      { line_type: 'product', product_id: 2, tracking_method: 'batch', product_batch_id: 7 },
      { line_type: 'product', product_id: 3, tracking_method: 'serial', product_serial_id: null, serial_number: '' },
    ])).toBe(3)
  })

  it('accepts a lot number or serial number without ids, and untracked lines', () => {
    expect(missingTrackingLineIndex([
      { product_id: 1, tracking_method: 'batch', lot_number: 'LEGACY' },
      { product_id: 2, tracking_method: 'serial', serial_number: 'SN-1' },
      { product_id: 3, tracking_method: 'quantity' },
      { product_id: 4 },
    ])).toBe(-1)
  })
})
