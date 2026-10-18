// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { addCalendarDays, todayDateOnly } from '@/lib/dateOnly'
import { QuotationDocumentForm } from './QuotationDocumentForm'

// #545 (SET-UX1): a new quotation's Valid Until follows the tenant's
// Sales Settings → Quotations validity days (0 = no default expiry).


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

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: vi.fn(), put: vi.fn() },
}))

let salesDefaults: Record<string, unknown> = {}

beforeEach(() => {
  window.localStorage.clear()
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/settings/sales/defaults') return { data: { data: salesDefaults } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderForm() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <QuotationDocumentForm mode="create" />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('QuotationDocumentForm validity default (#545)', () => {
  it('pre-fills Valid Until from the tenant validity days', async () => {
    salesDefaults = { invoice: { field_profile: 'general' }, quotation: { default_validity_days: 14, default_terms: '' } }
    renderForm()

    const validUntil = (await screen.findByLabelText('Valid until')) as HTMLInputElement
    await waitFor(() => expect(validUntil.value).toBe(addCalendarDays(todayDateOnly(), 14)))
  })

  it('leaves Valid Until empty when the tenant sets 0 days', async () => {
    salesDefaults = { invoice: { field_profile: 'general' }, quotation: { default_validity_days: 0, default_terms: '' } }
    renderForm()

    const validUntil = (await screen.findByLabelText('Valid until')) as HTMLInputElement
    await waitFor(() => expect(validUntil.value).toBe(''))
  })

  it('keeps the historical 30 days when the tenant has no setting', async () => {
    salesDefaults = { invoice: { field_profile: 'general' } }
    renderForm()

    const validUntil = (await screen.findByLabelText('Valid until')) as HTMLInputElement
    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/settings/sales/defaults'))
    expect(validUntil.value).toBe(addCalendarDays(todayDateOnly(), 30))
  })
})
