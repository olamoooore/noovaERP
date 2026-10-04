// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, act } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import CreateInvoicePage from './CreateInvoicePage'

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

const { apiGet, resolveBatchMock, settingsControl } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  resolveBatchMock: vi.fn(),
  settingsControl: {
    // The /settings/sales payload for the current test.
    payload: {} as Record<string, unknown>,
    // Optional gate: when set, /settings/sales only resolves once released.
    gate: null as Promise<void> | null,
  },
}))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

vi.mock('@/lib/pricingApi', () => ({
  default: {
    resolveBatch: resolveBatchMock,
    sellableCurrencies: vi.fn(async () => []),
    listPriceLists: vi.fn(async () => ({ data: [] })),
  },
}))

// Customer terms are 7 days — the tenant setting (45) must never beat them.
const customer = { id: 1, name: 'Klinik Ali', payment_terms: 7 }

beforeEach(() => {
  settingsControl.payload = { invoice: { allow_custom_number: true, default_payment_terms_days: 30 } }
  settingsControl.gate = null

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices') return { data: { data: [] } }
    if (url.startsWith('/invoices/')) return { data: { data: null } }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    if (url.startsWith('/settings/sales')) {
      if (settingsControl.gate) await settingsControl.gate
      return { data: { data: settingsControl.payload } }
    }
    return { data: { data: [] } }
  })

  resolveBatchMock.mockReset()
  resolveBatchMock.mockImplementation(async () => ({ lines: [] }))
})

afterEach(cleanup)

function renderPage(path = '/invoices/new') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <CreateInvoicePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const termsChip = () => screen.getByTitle('Payment terms and due date')

async function pickCustomer() {
  const customerTrigger = (await screen.findAllByRole('combobox')).find((c) =>
    c.textContent?.includes('Search customers'),
  )
  expect(customerTrigger, 'customer combobox trigger').toBeTruthy()
  fireEvent.click(customerTrigger!)
  fireEvent.click(await screen.findByText('Klinik Ali'))
}

describe('CreateInvoicePage sales settings (#315)', () => {
  it('hides the invoice-number field when sales.invoice.allow_custom_number is false', async () => {
    settingsControl.payload = { invoice: { allow_custom_number: false, default_payment_terms_days: 45 } }
    renderPage()

    // Wait for the settings to be consumed (terms switch to the setting) …
    await waitFor(() => expect(termsChip().textContent).toContain('Net 45'))

    // … then the custom-number editor must be gone.
    expect(screen.queryByTitle('Invoice number')).toBeNull()
  })

  it('shows the invoice-number field when custom numbering is allowed', async () => {
    settingsControl.payload = { invoice: { allow_custom_number: true, default_payment_terms_days: 30 } }
    renderPage()

    expect(await screen.findByTitle('Invoice number')).toBeTruthy()
  })

  it('leaves the quotation number field alone (invoice setting does not gate quotes)', async () => {
    settingsControl.payload = { invoice: { allow_custom_number: false, default_payment_terms_days: 30 } }
    renderPage('/sales/quotes/new')

    expect(await screen.findByTitle('Quotation number')).toBeTruthy()
  })

  it('defaults payment terms from sales settings instead of the 30-day hard-code', async () => {
    settingsControl.payload = { invoice: { allow_custom_number: true, default_payment_terms_days: 45 } }
    renderPage()

    await waitFor(() => expect(termsChip().textContent).toContain('Net 45'))
  })

  it("uses the customer's terms over the sales-settings default", async () => {
    settingsControl.payload = { invoice: { allow_custom_number: true, default_payment_terms_days: 45 } }
    renderPage()

    // The setting applies first …
    await waitFor(() => expect(termsChip().textContent).toContain('Net 45'))

    // … then the customer's own terms (7 days) take precedence.
    await pickCustomer()
    await waitFor(() => expect(termsChip().textContent).toContain('Net 7'))
  })

  it('keeps customer terms when the settings response arrives after the customer pick', async () => {
    settingsControl.payload = {
      invoice: {
        allow_custom_number: false,
        default_payment_terms_days: 45,
        default_requires_approval: true,
      },
    }
    let release!: () => void
    settingsControl.gate = new Promise<void>((resolve) => {
      release = resolve
    })

    renderPage()

    // Customer picked while the settings request is still in flight.
    await pickCustomer()
    await waitFor(() => expect(termsChip().textContent).toContain('Net 7'))

    // Release the settings response. The approval chip is applied by the
    // settings effect regardless of the terms fix, so it proves the late
    // settings were actually consumed …
    await act(async () => {
      release()
    })
    await screen.findByText('Approval req.')

    // … and the customer terms must still win over the late setting.
    expect(termsChip().textContent).toContain('Net 7')
  })
})
