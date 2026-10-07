// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { TaxPricingSettings } from './SalesSettingsSections'

// #543 (ST6): the default tax is picked from the active sales tax rules the
// server lists (writing default_tax_id) instead of a free-typed rate, and a
// rejected save is reported, never shown as saved.


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
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}

const { apiGet, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPut: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: { get: apiGet, put: apiPut },
}))

const toast = vi.hoisted(() => vi.fn())
vi.mock('@/hooks/use-toast', () => ({ useToast: () => ({ toast }) }))

function settingsPayload() {
  return {
    data: {
      data: {
        tax: {
          default_tax_rate: 6,
          default_tax_id: 2,
          available_taxes: [
            { id: 2, name: 'Rule Six', code: 'R6', rate: 6 },
            { id: 3, name: 'Rule Ten', code: 'R10', rate: 10 },
          ],
          prices_include_tax: false,
          default_tax_treatment: 'standard',
        },
      },
    },
  }
}

beforeEach(() => {
  apiGet.mockReset()
  apiPut.mockReset()
  toast.mockReset()
  apiPut.mockImplementation(async () => ({ data: {} }))
  apiGet.mockImplementation(async () => settingsPayload())
})

afterEach(cleanup)

function renderWith(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>)
}

describe('TaxPricingSettings (#543)', () => {
  it('has no free-typed rate input and saves the picked rule id', async () => {
    renderWith(<TaxPricingSettings />)

    const picker = await screen.findByRole('combobox', { name: 'Default tax' })
    expect(picker.textContent).toContain('Rule Six (R6) — 6%')
    expect(screen.queryByRole('spinbutton')).toBeNull()

    fireEvent.click(picker)
    fireEvent.click(await screen.findByText('Rule Ten (R10) — 10%'))
    fireEvent.click(screen.getByRole('button', { name: /save tax settings/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', { tax: { default_tax_id: 3 } })
    })
    expect(JSON.stringify(apiPut.mock.calls[0][1])).not.toContain('default_tax_rate')
  })

  it('clears the default with "No default tax"', async () => {
    renderWith(<TaxPricingSettings />)

    fireEvent.click(await screen.findByRole('combobox', { name: 'Default tax' }))
    fireEvent.click(await screen.findByText('No default tax'))
    fireEvent.click(screen.getByRole('button', { name: /save tax settings/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', { tax: { default_tax_id: null } })
    })
  })

  it('reports a rejected rule instead of showing it as saved', async () => {
    apiPut.mockRejectedValueOnce({
      response: { status: 422, data: { message: 'Choose an active sales tax rule.' } },
    })
    renderWith(<TaxPricingSettings />)

    fireEvent.click(await screen.findByRole('combobox', { name: 'Default tax' }))
    fireEvent.click(await screen.findByText('Rule Ten (R10) — 10%'))
    fireEvent.click(screen.getByRole('button', { name: /save tax settings/i }))

    await waitFor(() => {
      expect(toast).toHaveBeenCalledWith(
        expect.objectContaining({ variant: 'destructive', description: 'Choose an active sales tax rule.' }),
      )
    })
    expect(toast).not.toHaveBeenCalledWith(expect.objectContaining({ title: 'Tax settings saved' }))
  })
})
