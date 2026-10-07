// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { QuotationDocumentForm } from './QuotationDocumentForm'

// #339 (D1 Smart Customer Pick): picking a matched contact or address row in
// the customer search sets the customer AND that contact/address on the
// quotation header in one pick.


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

vi.mock('@/components/ui/CustomerSearchCombobox', () => ({
  CustomerSearchCombobox: ({ onValueChange }: { onValueChange: (...args: any[]) => void }) => (
    <div>
      <button type="button" onClick={() => onValueChange('1', { id: 1, name: 'ACME Trading' })}>pick-acme</button>
      <button
        type="button"
        onClick={() => onValueChange('1', { id: 1, name: 'ACME Trading' }, undefined, { kind: 'address', addressId: '31', addressType: 'shipping' })}
      >
        pick-acme-kl-warehouse
      </button>
      <button
        type="button"
        onClick={() => onValueChange('1', { id: 1, name: 'ACME Trading' }, '12', { kind: 'contact', contactId: '12' })}
      >
        pick-acme-john
      </button>
    </div>
  ),
}))

const acme = {
  id: 1,
  name: 'ACME Trading',
  code: 'C-ACME',
  customer_contacts: [
    { id: 11, name: 'Billing Bea', email: 'bea@acme.test', contact_category: 'billing' },
    { id: 12, name: 'John Tan', email: 'john@acme.test', phone: '012-339', contact_category: 'purchasing' },
  ],
  addresses: [
    { id: 30, type: 'billing', street: '1 Head Office Rd', city: 'Singapore', is_default: true },
    { id: 29, type: 'shipping', street: '3 Main Dock', city: 'Jurong', is_default: true },
    { id: 31, type: 'shipping', label: 'KL Warehouse', street: '8 Jalan Klang', city: 'Kuala Lumpur', is_default: false },
  ],
}

beforeEach(() => {
  window.localStorage.clear()
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/settings/sales/defaults') return { data: { data: { invoice: { field_profile: 'general' } } } }
    if (url === '/customers/1') return { data: { data: acme } }
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

const openDetails = () => fireEvent.click(screen.getByTitle('References, contacts, addresses, logistics and terms'))

describe('QuotationDocumentForm smart customer pick (#339)', () => {
  it('a plain customer pick keeps the default billing/shipping addresses', async () => {
    renderForm()
    fireEvent.click(await screen.findByText('pick-acme'))
    openDetails()

    expect(await screen.findByText(/3 Main Dock, Jurong/)).toBeTruthy()
  })

  it('a pick on a matched address row sets that ship-to address', async () => {
    renderForm()
    fireEvent.click(await screen.findByText('pick-acme-kl-warehouse'))
    openDetails()

    expect(await screen.findByText(/8 Jalan Klang, Kuala Lumpur/)).toBeTruthy()
    expect(screen.getByText(/1 Head Office Rd, Singapore/)).toBeTruthy()
    expect(screen.queryByText(/3 Main Dock/)).toBeNull()
  })

  it('a pick on a matched contact row sets that person as the billing contact', async () => {
    renderForm()
    fireEvent.click(await screen.findByText('pick-acme-john'))
    openDetails()

    await waitFor(() => expect(screen.getByText(/John Tan · 1 Head Office Rd/)).toBeTruthy())
  })
})
